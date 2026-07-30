import type { CaptureMode, MeetingParticipant } from '@/types/meetingCapture';
import { ref } from 'vue';

export interface RecallReadiness {
    configured: boolean;
    webhookReady: boolean;
}

export interface RecallCaptureStartResult {
    conversationId: number;
    usesRealtimeCopilot: boolean;
}

export interface MeetingCaptureOrchestratorDependencies {
    createLocalConversation: () => Promise<number>;
    endConversation: (sessionId: number) => Promise<void>;
    enableLocalProtection: () => Promise<void>;
    connectSalespersonTranscription: () => Promise<void>;
    connectCustomerTranscription: () => Promise<void>;
    disconnectSalespersonTranscription: () => void;
    disconnectCustomerTranscription: () => void;
    drainLocalTranscriptions: () => Promise<boolean>;
    startMicrophone: () => Promise<void>;
    startSystemAudio: () => Promise<void>;
    stopLocalAudio: () => Promise<void>;
    checkLocalPermissions: () => Promise<void>;
    checkRecallReadiness: () => Promise<RecallReadiness>;
    startRecallCapture: (meetingUrl: string) => Promise<RecallCaptureStartResult>;
    stopRecallCapture: () => Promise<boolean>;
    disconnectRecall: () => void;
    connectCopilot: () => Promise<void>;
    flushCopilot: () => Promise<boolean>;
    disconnectCopilot: () => void;
    flushPersistence: () => Promise<boolean>;
    setSessionId: (sessionId: number | null) => void;
    setActive: (active: boolean) => void;
    setConnectionStatus: (status: 'connecting' | 'connected' | 'disconnected') => void;
    onError: (message: string) => void;
}

const RECALL_DRAIN_WARNING = 'Meeting shutdown is still draining. Reopen the app to resume recovery.';
type LocalStartupStage = 'permissions' | 'protection' | 'conversation' | 'realtime' | 'microphone' | 'system-audio';

export function shouldRequestLocalPermissions(mode: CaptureMode | null | undefined): boolean {
    return mode === 'local';
}

export function shouldShowParticipantAssignment(mode: CaptureMode | null, participants: MeetingParticipant[]): boolean {
    const humans = participants.filter((participant) => !participant.isBot);

    return mode === 'recall' && humans.length >= 1 && !humans.some((participant) => participant.salesRole === 'salesperson');
}

export function useMeetingCaptureOrchestrator(dependencies: MeetingCaptureOrchestratorDependencies) {
    const mode = ref<CaptureMode | null>(null);
    const busy = ref(false);
    const error = ref<string | null>(null);
    const currentSessionId = ref<number | null>(null);
    const localFallbackAvailable = ref(false);
    const needsRecallSettings = ref(false);
    const recoveryRequired = ref(false);
    let recallCopilotConnected = false;

    function clearStartFeedback(): void {
        error.value = null;
        localFallbackAvailable.value = false;
        needsRecallSettings.value = false;
        recoveryRequired.value = false;
    }

    function setSessionId(sessionId: number | null): void {
        currentSessionId.value = sessionId;
        dependencies.setSessionId(sessionId);
    }

    function report(message: string): void {
        error.value = message;
        dependencies.onError(message);
    }

    async function startLocal(): Promise<boolean> {
        if (busy.value || currentSessionId.value !== null) {
            return false;
        }

        busy.value = true;
        clearStartFeedback();
        mode.value = 'local';
        dependencies.setConnectionStatus('connecting');
        let sessionId: number | null = null;
        let stage: LocalStartupStage = 'permissions';

        try {
            await dependencies.checkLocalPermissions();
            stage = 'protection';
            await dependencies.enableLocalProtection();
            stage = 'conversation';
            sessionId = await dependencies.createLocalConversation();
            setSessionId(sessionId);
            stage = 'realtime';
            await dependencies.connectSalespersonTranscription();
            await dependencies.connectCustomerTranscription();
            await dependencies.connectCopilot();
            stage = 'microphone';
            await dependencies.startMicrophone();
            stage = 'system-audio';
            await dependencies.startSystemAudio();
            dependencies.setActive(true);
            dependencies.setConnectionStatus('connected');
            return true;
        } catch (caught) {
            await rollbackLocalStartup(sessionId);
            report(localStartupError(stage, caught));
            return false;
        } finally {
            busy.value = false;
        }
    }

    async function rollbackLocalStartup(sessionId: number | null): Promise<void> {
        await safely(dependencies.stopLocalAudio);
        dependencies.disconnectSalespersonTranscription();
        dependencies.disconnectCustomerTranscription();
        dependencies.disconnectCopilot();
        if (sessionId !== null) {
            await safely(() => dependencies.endConversation(sessionId));
        }
        setSessionId(null);
        dependencies.setActive(false);
        dependencies.setConnectionStatus('disconnected');
        mode.value = null;
    }

    async function startRecall(meetingUrl: string): Promise<boolean> {
        if (busy.value || currentSessionId.value !== null) {
            return false;
        }

        const normalizedUrl = meetingUrl.trim();
        clearStartFeedback();
        if (!normalizedUrl) {
            error.value = 'Enter a Teams meeting URL.';
            return false;
        }

        busy.value = true;
        mode.value = 'recall';
        dependencies.setConnectionStatus('connecting');
        let captureStarted = false;
        let sessionId: number | null = null;

        try {
            const readiness = await dependencies.checkRecallReadiness();
            if (!readiness.configured || !readiness.webhookReady) {
                needsRecallSettings.value = true;
                error.value = 'Recall is not ready for meeting capture.';
                mode.value = null;
                dependencies.setConnectionStatus('disconnected');
                return false;
            }

            const capture = await dependencies.startRecallCapture(normalizedUrl);
            captureStarted = true;
            sessionId = capture.conversationId;
            setSessionId(sessionId);
            if (capture.usesRealtimeCopilot) {
                recallCopilotConnected = true;
                await dependencies.connectCopilot();
            }
            dependencies.setActive(true);
            dependencies.setConnectionStatus('connected');
            return true;
        } catch (caught) {
            if (httpStatus(caught) === 507) {
                localFallbackAvailable.value = true;
                error.value = 'Recall has no available meeting capacity.';
            } else {
                error.value = 'Unable to start Teams capture.';
            }

            if (captureStarted && sessionId !== null) {
                const activeSessionId = sessionId;
                const drained = await safelyResult(dependencies.stopRecallCapture, false);
                if (!drained) {
                    recoveryRequired.value = true;
                    dependencies.setActive(true);
                    dependencies.setConnectionStatus('disconnected');
                    dependencies.onError(RECALL_DRAIN_WARNING);
                    dependencies.disconnectRecall();
                    if (recallCopilotConnected) dependencies.disconnectCopilot();
                    return false;
                }

                await safely(dependencies.flushPersistence);
                await safely(() => dependencies.endConversation(activeSessionId));
            }

            dependencies.disconnectRecall();
            if (recallCopilotConnected) dependencies.disconnectCopilot();
            recallCopilotConnected = false;
            setSessionId(null);
            dependencies.setActive(false);
            dependencies.setConnectionStatus('disconnected');
            mode.value = null;
            dependencies.onError(error.value);
            return false;
        } finally {
            busy.value = false;
        }
    }

    async function resumeRecall(sessionId: number, usesRealtimeCopilot = true): Promise<boolean> {
        if (busy.value || currentSessionId.value !== null || !Number.isInteger(sessionId) || sessionId <= 0) {
            return false;
        }

        busy.value = true;
        clearStartFeedback();
        mode.value = 'recall';
        recoveryRequired.value = true;
        dependencies.setConnectionStatus('connecting');
        setSessionId(sessionId);

        try {
            if (usesRealtimeCopilot) {
                recallCopilotConnected = true;
                await dependencies.connectCopilot();
            }
            dependencies.setActive(true);
            dependencies.setConnectionStatus('connected');
            recoveryRequired.value = false;
            return true;
        } catch {
            dependencies.setActive(true);
            dependencies.setConnectionStatus('disconnected');
            report('The meeting was restored, but the copilot could not reconnect. Retry End Call to continue recovery.');
            return false;
        } finally {
            busy.value = false;
        }
    }

    async function stop(): Promise<boolean> {
        if (busy.value || mode.value === null || currentSessionId.value === null) {
            return false;
        }

        busy.value = true;
        error.value = null;

        try {
            return mode.value === 'recall' ? await stopRecall() : await stopLocal();
        } finally {
            busy.value = false;
        }
    }

    async function stopRecall(): Promise<boolean> {
        const sessionId = currentSessionId.value;
        if (sessionId === null) {
            return false;
        }

        const drained = await safelyResult(dependencies.stopRecallCapture, false);
        if (!drained) {
            recoveryRequired.value = true;
            report(RECALL_DRAIN_WARNING);
            return false;
        }

        const copilotFlushed = recallCopilotConnected ? await safelyResult(dependencies.flushCopilot, false) : true;
        const persistenceFlushed = await safelyResult(dependencies.flushPersistence, false);
        if (!copilotFlushed || !persistenceFlushed) {
            recoveryRequired.value = true;
            report('Meeting data is still waiting to be saved. Keep this session open and retry End Call.');
            return false;
        }

        try {
            await dependencies.endConversation(sessionId);
        } catch {
            recoveryRequired.value = true;
            report('The meeting ended, but the conversation could not be finalized. Retry End Call.');
            return false;
        }

        dependencies.disconnectRecall();
        if (recallCopilotConnected) dependencies.disconnectCopilot();
        recallCopilotConnected = false;
        finishStoppedSession();
        return true;
    }

    async function stopLocal(): Promise<boolean> {
        const sessionId = currentSessionId.value;
        if (sessionId === null) {
            return false;
        }

        await safely(dependencies.stopLocalAudio);
        const transcriptsDrained = await safelyResult(dependencies.drainLocalTranscriptions, false);
        const copilotFlushed = await safelyResult(dependencies.flushCopilot, false);
        const persistenceFlushed = await safelyResult(dependencies.flushPersistence, false);
        if (!transcriptsDrained || !copilotFlushed || !persistenceFlushed) {
            recoveryRequired.value = true;
            report('Local call data is still waiting to be saved. Retry End Call.');
            return false;
        }

        try {
            await dependencies.endConversation(sessionId);
        } catch {
            recoveryRequired.value = true;
            report('The local call ended, but the conversation could not be finalized. Retry End Call.');
            return false;
        }

        dependencies.disconnectSalespersonTranscription();
        dependencies.disconnectCustomerTranscription();
        dependencies.disconnectCopilot();
        finishStoppedSession();
        return true;
    }

    function finishStoppedSession(): void {
        setSessionId(null);
        dependencies.setActive(false);
        dependencies.setConnectionStatus('disconnected');
        mode.value = null;
        recoveryRequired.value = false;
        recallCopilotConnected = false;
    }

    function reset(): void {
        if (busy.value || currentSessionId.value !== null) {
            return;
        }

        mode.value = null;
        clearStartFeedback();
    }

    return {
        mode,
        busy,
        error,
        currentSessionId,
        localFallbackAvailable,
        needsRecallSettings,
        recoveryRequired,
        startLocal,
        startRecall,
        resumeRecall,
        stop,
        reset,
    };
}

function httpStatus(error: unknown): number | null {
    if (!isRecord(error) || !isRecord(error.response)) {
        return null;
    }

    return typeof error.response.status === 'number' ? error.response.status : null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function localStartupError(stage: LocalStartupStage, error: unknown): string {
    if ((stage === 'permissions' || stage === 'system-audio') && error instanceof Error && error.message.trim()) {
        return error.message;
    }

    switch (stage) {
        case 'realtime':
            return 'Unable to connect to OpenAI Realtime. Check the API key and realtime model settings.';
        case 'microphone':
            return 'Unable to start the microphone. Grant microphone access in System Settings, then try again.';
        case 'conversation':
            return 'Unable to create the local conversation.';
        case 'protection':
            return 'Unable to prepare local capture.';
        default:
            return 'Unable to start Local capture.';
    }
}

async function safely(operation: () => Promise<unknown>): Promise<void> {
    try {
        await operation();
    } catch {
        // Best-effort rollback continues through all initialized resources.
    }
}

async function safelyResult<T>(operation: () => Promise<T>, fallback: T): Promise<T> {
    try {
        return await operation();
    } catch {
        return fallback;
    }
}
