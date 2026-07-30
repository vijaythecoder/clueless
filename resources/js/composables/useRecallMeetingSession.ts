import { normalizeMeetingEvent, normalizeMeetingParticipant } from '@/services/meetingEventNormalizer';
import type {
    AnalysisAckRequest,
    AnalysisAckResponse,
    AnalysisClaimApiResponse,
    AnalysisClaimRequest,
    AnalysisContextTurn,
    AnalysisContextTurnResponse,
    AnalysisDelivery,
    AnalysisDeliveryCounts,
    AnalysisDeliveryResponse,
    CaptureFailure,
    MeetingCapture,
    MeetingCaptureLinksResponse,
    MeetingCaptureResponse,
    MeetingCaptureStartRequest,
    MeetingCaptureStartResponse,
    MeetingCaptureStatus,
    MeetingEvent,
    MeetingEventPageResponse,
    MeetingInsightPageResponse,
    MeetingParticipant,
    ParticipantAssignmentResponse,
    RecallAnalysisDriver,
    RecallTranscriptTurn,
    ServerCopilotUiTool,
} from '@/types/meetingCapture';
import axios from 'axios';
import { computed, ref } from 'vue';

const storageKey = 'clueless.recall.active-capture';
const pollIntervalMs = 250;
const stopQuietPeriodMs = 1_000;
const stopTimeoutMs = 10_000;
const retryDelaysMs = [250, 500, 1_000, 2_000] as const;
const terminalStatuses = new Set<MeetingCaptureStatus>(['ended', 'failed']);

interface RecallMeetingSessionOptions {
    onPartial: (turn: RecallTranscriptTurn) => void;
    onFinal: (turn: RecallTranscriptTurn) => Promise<void> | void;
    onParticipants: (participants: MeetingParticipant[]) => void;
    onStatus: (status: MeetingCaptureStatus, failure?: CaptureFailure) => void;
    onAnalysisDelivery: (delivery: AnalysisDelivery) => Promise<void>;
    onUiTool: (tool: ServerCopilotUiTool) => Promise<void>;
    onError: (message: string) => void;
}

interface PersistedMeetingSession {
    capture: MeetingCapture;
    links: MeetingCaptureLinksResponse;
    cursor: number;
    participants: MeetingParticipant[];
    recovery: boolean;
    analysisDriver: RecallAnalysisDriver;
    insightCursor: number;
}

interface StopDrainState {
    startedAt: number;
    quietSince: number | null;
}

interface AppliedPage {
    hasGap: boolean;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isMeetingCaptureStatus(value: unknown): value is MeetingCaptureStatus {
    return (
        value === 'creating' ||
        value === 'joining' ||
        value === 'waiting_room' ||
        value === 'active' ||
        value === 'stopping' ||
        value === 'ended' ||
        value === 'failed'
    );
}

function isMeetingProvider(value: unknown): value is MeetingCapture['provider'] {
    return value === 'recall' || value === 'local';
}

function isSalesRole(value: unknown): value is MeetingParticipant['salesRole'] {
    return value === 'salesperson' || value === 'customer' || value === 'unknown' || value === 'bot';
}

function readPersistedParticipant(value: unknown): MeetingParticipant | null {
    if (
        !isRecord(value) ||
        typeof value.id !== 'number' ||
        !Number.isInteger(value.id) ||
        value.id <= 0 ||
        typeof value.providerParticipantId !== 'string' ||
        value.providerParticipantId === '' ||
        typeof value.displayName !== 'string' ||
        value.displayName === '' ||
        typeof value.isBot !== 'boolean' ||
        !isSalesRole(value.salesRole) ||
        typeof value.emailPresent !== 'boolean' ||
        (value.isHost !== undefined && typeof value.isHost !== 'boolean')
    ) {
        return null;
    }

    return {
        id: value.id,
        providerParticipantId: value.providerParticipantId,
        displayName: value.displayName,
        ...(typeof value.isHost === 'boolean' ? { isHost: value.isHost } : {}),
        isBot: value.isBot,
        salesRole: value.salesRole,
        emailPresent: value.emailPresent,
    };
}

function captureFailure(code: unknown, message: unknown): CaptureFailure | undefined {
    return typeof code === 'string' && code !== '' && typeof message === 'string' && message !== '' ? { code, message } : undefined;
}

function normalizeCapture(value: MeetingCaptureResponse): MeetingCapture {
    const capture: MeetingCapture = {
        id: value.id,
        conversationId: value.conversation_id,
        provider: value.provider,
        status: value.status,
    };
    const failure = captureFailure(value.failure_code, value.failure_message);
    if (failure !== undefined) {
        capture.failure = failure;
    }

    return capture;
}

function readPersistedSession(): PersistedMeetingSession | null {
    const serialized = localStorage.getItem(storageKey);
    if (serialized === null) {
        return null;
    }

    try {
        const value: unknown = JSON.parse(serialized);
        if (!isRecord(value) || !isRecord(value.capture) || !isRecord(value.links)) {
            return null;
        }

        const storedCapture = value.capture;
        const storedLinks = value.links;
        if (
            typeof storedCapture.id !== 'string' ||
            typeof storedCapture.conversationId !== 'number' ||
            !isMeetingProvider(storedCapture.provider) ||
            !isMeetingCaptureStatus(storedCapture.status) ||
            typeof storedLinks.events !== 'string' ||
            typeof storedLinks.stop !== 'string' ||
            typeof storedLinks.claim_analysis !== 'string' ||
            typeof value.cursor !== 'number' ||
            !Number.isInteger(value.cursor) ||
            value.cursor < 0
        ) {
            return null;
        }

        const restoredCapture: MeetingCapture = {
            id: storedCapture.id,
            conversationId: storedCapture.conversationId,
            provider: storedCapture.provider,
            status: storedCapture.status,
        };
        if (isRecord(storedCapture.failure) && typeof storedCapture.failure.code === 'string' && typeof storedCapture.failure.message === 'string') {
            restoredCapture.failure = {
                code: storedCapture.failure.code,
                message: storedCapture.failure.message,
            };
        }

        const restoredParticipants = Array.isArray(value.participants)
            ? value.participants.map(readPersistedParticipant).filter((participant): participant is MeetingParticipant => participant !== null)
            : [];

        return {
            capture: restoredCapture,
            links: {
                events: storedLinks.events,
                stop: storedLinks.stop,
                claim_analysis: storedLinks.claim_analysis,
            },
            cursor: value.cursor,
            participants: restoredParticipants,
            recovery: value.recovery === true,
            analysisDriver: value.analysisDriver === 'responses' ? 'responses' : 'realtime',
            insightCursor:
                typeof value.insightCursor === 'number' && Number.isInteger(value.insightCursor) && value.insightCursor >= 0
                    ? value.insightCursor
                    : 0,
        };
    } catch {
        return null;
    }
}

function startPayload(request: MeetingCaptureStartRequest): Record<string, string> {
    const payload: Record<string, string> = {
        provider: request.provider,
        idempotency_key: request.idempotencyKey,
    };
    const optionalFields: Array<[string, string | undefined]> = [
        ['meeting_url', request.meetingUrl],
        ['template_used', request.templateUsed],
        ['customer_name', request.customerName],
        ['customer_company', request.customerCompany],
    ];
    for (const [key, value] of optionalFields) {
        if (value !== undefined) {
            payload[key] = value;
        }
    }

    return payload;
}

function normalizeContextTurn(value: AnalysisContextTurnResponse): AnalysisContextTurn {
    return {
        providerItemId: value.provider_item_id,
        participantId: value.participant_id,
        displayName: value.display_name,
        salesRole: value.sales_role,
        text: value.text,
        startedAt: value.started_at ?? value.started_offset_ms ?? 0,
        ...(value.ended_at !== undefined && value.ended_at !== null
            ? { endedAt: value.ended_at }
            : value.ended_offset_ms !== undefined && value.ended_offset_ms !== null
              ? { endedAt: value.ended_offset_ms }
              : {}),
    };
}

function normalizeAnalysisDelivery(value: AnalysisDeliveryResponse): AnalysisDelivery {
    return {
        id: value.id,
        leaseToken: value.lease_token,
        providerItemId: value.provider_item_id,
        participantId: value.participant_id,
        displayName: value.display_name,
        salesRole: value.sales_role,
        text: value.text,
        startedAt: value.started_at ?? value.started_offset_ms ?? 0,
        ...(value.ended_at !== undefined && value.ended_at !== null
            ? { endedAt: value.ended_at }
            : value.ended_offset_ms !== undefined && value.ended_offset_ms !== null
              ? { endedAt: value.ended_offset_ms }
              : {}),
        context: value.context.map(normalizeContextTurn),
    };
}

function chronologicalDeliveries(deliveries: AnalysisDelivery[]): AnalysisDelivery[] {
    return [...deliveries].sort((left, right) => left.startedAt - right.startedAt || left.id - right.id);
}

export function useRecallMeetingSession(options: RecallMeetingSessionOptions) {
    const capture = ref<MeetingCapture | null>(null);
    const participants = ref<MeetingParticipant[]>([]);
    const cursor = ref(0);
    const isPolling = ref(false);
    const isAnalysisDelayed = ref(false);
    const analysisDriver = ref<RecallAnalysisDriver>('realtime');
    const insightCursor = ref(0);
    const appliedServerToolCallIds = new Set<string>();

    let links: MeetingCaptureLinksResponse | null = null;
    let runToken = 0;
    let abortController: AbortController | null = null;
    let pollPromise: Promise<void> | null = null;
    let analysisPromise: Promise<void> | null = null;
    let stopDrain: StopDrainState | null = null;
    let recoveryMode = false;
    let retryIndex = 0;
    let networkWarningActive = false;
    let analysisWarningActive = false;
    let analysisDrainedThroughCursor = -1;
    let analysisWorkerGeneration = 0;
    let delayTimer: ReturnType<typeof setTimeout> | null = null;
    let stopDeadlineTimer: ReturnType<typeof setTimeout> | null = null;
    let releaseDelay: (() => void) | null = null;
    const reportedContractGaps = new Set<string>();
    let lastAnalysisCounts: AnalysisDeliveryCounts = {
        pending: 0,
        processing: 0,
        completed: 0,
        failed: 0,
    };

    function isCurrent(token: number): boolean {
        return token === runToken && abortController?.signal.aborted === false;
    }

    function persist(recovery = recoveryMode): void {
        if (capture.value === null || links === null || (!recovery && terminalStatuses.has(capture.value.status))) {
            localStorage.removeItem(storageKey);
            return;
        }

        const state: PersistedMeetingSession = {
            capture: capture.value,
            links,
            cursor: cursor.value,
            participants: participants.value,
            recovery,
            analysisDriver: analysisDriver.value,
            insightCursor: insightCursor.value,
        };
        localStorage.setItem(storageKey, JSON.stringify(state));
    }

    function wait(milliseconds: number, token: number): Promise<void> {
        return new Promise((resolve) => {
            if (!isCurrent(token)) {
                resolve();
                return;
            }

            releaseDelay = () => {
                if (delayTimer !== null) {
                    clearTimeout(delayTimer);
                }
                delayTimer = null;
                releaseDelay = null;
                resolve();
            };
            delayTimer = setTimeout(() => releaseDelay?.(), milliseconds);
        });
    }

    function wakePolling(): void {
        releaseDelay?.();
    }

    function clearStopDeadline(): void {
        if (stopDeadlineTimer !== null) {
            clearTimeout(stopDeadlineTimer);
            stopDeadlineTimer = null;
        }
    }

    function timeoutStopDrain(): void {
        if (stopDrain === null) {
            return;
        }

        options.onError('Meeting shutdown is still draining. Reopen the app to resume recovery.');
        recoveryMode = true;
        persist(true);
        runToken += 1;
        abortController?.abort();
        abortController = null;
        wakePolling();
        isPolling.value = false;
        pollPromise = null;
        analysisPromise = null;
        stopDrain = null;
    }

    function reportContractGap(cursorValue: number | null, nextCursor: number): void {
        const key = cursorValue === null ? `page:${nextCursor}` : `event:${cursorValue}`;
        if (reportedContractGaps.has(key)) {
            return;
        }

        reportedContractGaps.add(key);
        options.onError('A meeting event could not be applied. Retrying from the last saved update.');
    }

    function updateCaptureStatus(status: MeetingCaptureStatus, failure?: CaptureFailure): void {
        if (capture.value === null) {
            return;
        }

        const changed =
            capture.value.status !== status || capture.value.failure?.code !== failure?.code || capture.value.failure?.message !== failure?.message;
        capture.value = {
            ...capture.value,
            status,
            ...(failure === undefined ? {} : { failure }),
        };
        if (failure === undefined) {
            delete capture.value.failure;
        }
        if (changed) {
            options.onStatus(status, failure);
        }
    }

    function upsertParticipant(participant: MeetingParticipant): void {
        const index = participants.value.findIndex((candidate) => candidate.id === participant.id);
        if (index === -1) {
            participants.value = [...participants.value, participant];
        } else {
            const updated = [...participants.value];
            updated[index] = participant;
            participants.value = updated;
        }
        options.onParticipants([...participants.value]);
    }

    async function applyEvent(event: MeetingEvent): Promise<void> {
        if (event.type === 'capture.status') {
            updateCaptureStatus(event.status);
            if (terminalStatuses.has(event.status)) {
                recoveryMode = true;
            }
            return;
        }
        if (event.type === 'participant.upsert') {
            upsertParticipant(event.participant);
            return;
        }
        if (event.type === 'transcript.partial') {
            options.onPartial(event.turn);
            return;
        }
        if (event.type === 'transcript.final') {
            await options.onFinal(event.turn);
            return;
        }

        options.onError(event.message);
    }

    async function applyPage(page: MeetingEventPageResponse): Promise<AppliedPage> {
        let appliedCursor = cursor.value;
        let hasGap = false;
        const sortedEvents = [...page.events].sort((left, right) => {
            const leftCursor = isRecord(left) && typeof left.cursor === 'number' ? left.cursor : Number.MAX_SAFE_INTEGER;
            const rightCursor = isRecord(right) && typeof right.cursor === 'number' ? right.cursor : Number.MAX_SAFE_INTEGER;
            return leftCursor - rightCursor;
        });

        for (const rawEvent of sortedEvents) {
            const rawCursor =
                isRecord(rawEvent) && typeof rawEvent.cursor === 'number' && Number.isInteger(rawEvent.cursor) && rawEvent.cursor > 0
                    ? rawEvent.cursor
                    : null;
            if (rawCursor !== null && rawCursor <= appliedCursor) {
                continue;
            }

            const event = normalizeMeetingEvent(rawEvent, appliedCursor);
            if (event === null) {
                reportContractGap(rawCursor, page.next_cursor);
                hasGap = true;
                break;
            }
            try {
                await applyEvent(event);
            } catch {
                reportContractGap(event.cursor, page.next_cursor);
                hasGap = true;
                break;
            }
            appliedCursor = event.cursor;
        }

        if (!hasGap && Number.isInteger(page.next_cursor) && page.next_cursor >= appliedCursor) {
            appliedCursor = page.next_cursor;
        }
        cursor.value = appliedCursor;

        if (!hasGap) {
            const pageFailure = captureFailure(page.capture.failure_code, page.capture.failure_message);
            updateCaptureStatus(page.capture.status, pageFailure);
            if (terminalStatuses.has(page.capture.status)) {
                recoveryMode = true;
            }
        }
        persist();

        return { hasGap };
    }

    async function acknowledgeAnalysisDelivery(
        deliveryId: number,
        leaseToken: string,
        status: AnalysisAckRequest['status'],
        errorCode?: string,
        token = runToken,
    ): Promise<void> {
        if (capture.value === null || !isCurrent(token)) {
            return;
        }

        const payload: AnalysisAckRequest = {
            lease_token: leaseToken,
            status,
        };
        if (errorCode !== undefined) {
            payload.error_code = errorCode;
        }
        await axios.post<AnalysisAckResponse>(`/meeting-captures/${capture.value.id}/analysis-deliveries/${deliveryId}/ack`, payload, {
            signal: abortController?.signal,
        });
    }

    async function processAnalysisBatches(token: number): Promise<void> {
        if (analysisDriver.value === 'responses') {
            return;
        }

        while (capture.value !== null && links !== null && isCurrent(token)) {
            const throughCursor = cursor.value;
            const request: AnalysisClaimRequest = {
                through_cursor: throughCursor,
            };
            const response = await axios.post<AnalysisClaimApiResponse>(links.claim_analysis, request, {
                signal: abortController?.signal,
            });
            if (!isCurrent(token)) {
                return;
            }

            lastAnalysisCounts = response.data.counts;
            const deliveries = chronologicalDeliveries(response.data.deliveries.map(normalizeAnalysisDelivery));
            isAnalysisDelayed.value = deliveries.length === 0 && response.data.counts.pending > 0;
            if (deliveries.length === 0) {
                if (response.data.counts.pending === 0 && response.data.counts.processing === 0) {
                    analysisDrainedThroughCursor = Math.max(analysisDrainedThroughCursor, throughCursor);
                }
                analysisWarningActive = false;
                return;
            }

            const outcomes = await Promise.allSettled(
                deliveries.map(async (delivery) => {
                    if (!isCurrent(token)) {
                        return;
                    }

                    try {
                        await options.onAnalysisDelivery(delivery);
                    } catch {
                        if (!isCurrent(token)) {
                            return;
                        }
                        await acknowledgeAnalysisDelivery(delivery.id, delivery.leaseToken, 'failed', 'copilot_analysis_failed', token);
                        return;
                    }

                    if (!isCurrent(token)) {
                        return;
                    }
                    await acknowledgeAnalysisDelivery(delivery.id, delivery.leaseToken, 'completed', undefined, token);
                }),
            );

            if (outcomes.some((outcome) => outcome.status === 'rejected')) {
                throw new Error('analysis_delivery_ack_failed');
            }
        }
    }

    function startAnalysisWorker(force = false): Promise<void> {
        if (analysisDriver.value === 'responses') {
            return Promise.resolve();
        }

        if (analysisPromise !== null) {
            return analysisPromise;
        }
        if (!force && analysisDrainedThroughCursor >= cursor.value && analysisIsDrained()) {
            return Promise.resolve();
        }

        const token = runToken;
        analysisWorkerGeneration += 1;
        const guardedPromise = processAnalysisBatches(token)
            .catch(() => {
                if (token === runToken && !analysisWarningActive) {
                    analysisWarningActive = true;
                    options.onError('Meeting analysis is temporarily delayed. It will retry from the saved transcript.');
                }
            })
            .finally(() => {
                if (token === runToken) {
                    analysisPromise = null;
                    const terminalOrStopping = stopDrain !== null || (capture.value !== null && terminalStatuses.has(capture.value.status));
                    if (
                        terminalOrStopping &&
                        lastAnalysisCounts.pending === 0 &&
                        lastAnalysisCounts.processing === 0 &&
                        analysisDrainedThroughCursor >= cursor.value
                    ) {
                        wakePolling();
                    }
                }
            });
        analysisPromise = guardedPromise;
        return guardedPromise;
    }

    async function claimAnalysisDeliveries(): Promise<void> {
        analysisDrainedThroughCursor = -1;
        return startAnalysisWorker(true);
    }

    function analysisIsDrained(): boolean {
        return analysisPromise === null && lastAnalysisCounts.pending === 0 && lastAnalysisCounts.processing === 0;
    }

    async function applyInsightPage(token: number): Promise<boolean> {
        if (analysisDriver.value !== 'responses' || links?.insights === undefined || !isCurrent(token)) {
            return false;
        }

        const response = await axios.get<MeetingInsightPageResponse>(links.insights, {
            params: { after: insightCursor.value, limit: 100 },
            signal: abortController?.signal,
        });
        if (!isCurrent(token)) {
            return false;
        }

        for (const tool of response.data.ui_tools) {
            const callId = typeof tool.call_id === 'string' ? tool.call_id.trim() : '';
            if (!callId || appliedServerToolCallIds.has(callId)) {
                continue;
            }
            await options.onUiTool(tool);
            appliedServerToolCallIds.add(callId);
        }
        while (appliedServerToolCallIds.size > 2_000) {
            const oldest = appliedServerToolCallIds.values().next().value;
            if (typeof oldest === 'string') appliedServerToolCallIds.delete(oldest);
        }

        lastAnalysisCounts = response.data.counts;
        isAnalysisDelayed.value = response.data.counts.pending > 12 || response.data.counts.processing > 12;
        if (Number.isInteger(response.data.next_cursor) && response.data.next_cursor >= insightCursor.value) {
            insightCursor.value = response.data.next_cursor;
        }
        persist();

        return response.data.has_more;
    }

    async function pollLoop(token: number): Promise<void> {
        while (isCurrent(token) && capture.value !== null && links !== null) {
            try {
                const previousCursor = cursor.value;
                const response = await axios.get<MeetingEventPageResponse>(links.events, {
                    params: { after: cursor.value, limit: 100 },
                    signal: abortController?.signal,
                });
                if (!isCurrent(token)) {
                    return;
                }

                const appliedPage = await applyPage(response.data);
                const hasMoreInsights = await applyInsightPage(token);
                networkWarningActive = false;
                retryIndex = 0;
                const stableDelayedTerminal =
                    stopDrain === null &&
                    !appliedPage.hasGap &&
                    cursor.value === previousCursor &&
                    capture.value !== null &&
                    terminalStatuses.has(capture.value.status) &&
                    analysisPromise === null &&
                    isAnalysisDelayed.value &&
                    lastAnalysisCounts.pending > 0 &&
                    lastAnalysisCounts.processing === 0;
                if (stableDelayedTerminal) {
                    recoveryMode = true;
                    persist(true);
                    return;
                }
                if (!appliedPage.hasGap && analysisDriver.value === 'realtime') {
                    void startAnalysisWorker();
                }

                if (stopDrain !== null) {
                    const now = Date.now();
                    const cursorAdvanced = cursor.value !== previousCursor;
                    if (appliedPage.hasGap || cursorAdvanced || capture.value === null || !terminalStatuses.has(capture.value.status)) {
                        stopDrain.quietSince = null;
                    } else if (stopDrain.quietSince === null) {
                        stopDrain.quietSince = now;
                    }

                    if (
                        !appliedPage.hasGap &&
                        capture.value !== null &&
                        terminalStatuses.has(capture.value.status) &&
                        stopDrain.quietSince !== null &&
                        now - stopDrain.quietSince >= stopQuietPeriodMs &&
                        analysisIsDrained()
                    ) {
                        recoveryMode = false;
                        localStorage.removeItem(storageKey);
                        clearStopDeadline();
                        stopDrain = null;
                        return;
                    }

                    if (now - stopDrain.startedAt >= stopTimeoutMs) {
                        timeoutStopDrain();
                        return;
                    }
                } else if (capture.value !== null && terminalStatuses.has(capture.value.status) && !appliedPage.hasGap) {
                    if (analysisIsDrained()) {
                        recoveryMode = false;
                        localStorage.removeItem(storageKey);
                        return;
                    } else {
                        recoveryMode = true;
                        persist(true);
                    }
                }

                if ((response.data.has_more || hasMoreInsights) && !appliedPage.hasGap) {
                    continue;
                }
                await wait(pollIntervalMs, token);
            } catch {
                if (!isCurrent(token)) {
                    return;
                }

                if (!networkWarningActive) {
                    networkWarningActive = true;
                    options.onError('Meeting updates are temporarily unavailable; retrying from the last saved cursor.');
                }
                const retryDelay = retryDelaysMs[Math.min(retryIndex, retryDelaysMs.length - 1)];
                retryIndex += 1;
                await wait(retryDelay, token);
            }
        }
    }

    function beginPolling(): void {
        if (isPolling.value || capture.value === null || links === null) {
            return;
        }

        const token = runToken;
        isPolling.value = true;
        pollPromise = pollLoop(token).finally(() => {
            if (token === runToken) {
                isPolling.value = false;
                pollPromise = null;
            }
        });
    }

    function haltPolling(preserveResumeState: boolean): void {
        const activeCapture = capture.value;
        runToken += 1;
        abortController?.abort();
        abortController = null;
        wakePolling();
        clearStopDeadline();
        isPolling.value = false;
        pollPromise = null;
        analysisPromise = null;
        stopDrain = null;

        if (preserveResumeState && activeCapture !== null) {
            persist(recoveryMode);
        } else {
            localStorage.removeItem(storageKey);
        }
    }

    async function start(request: MeetingCaptureStartRequest): Promise<MeetingCapture> {
        haltPolling(false);
        capture.value = null;
        participants.value = [];
        cursor.value = 0;
        insightCursor.value = 0;
        links = null;
        isAnalysisDelayed.value = false;
        recoveryMode = false;
        retryIndex = 0;
        networkWarningActive = false;
        analysisWarningActive = false;
        analysisDrainedThroughCursor = -1;
        reportedContractGaps.clear();
        lastAnalysisCounts = { pending: 0, processing: 0, completed: 0, failed: 0 };
        appliedServerToolCallIds.clear();

        abortController = new AbortController();
        const response = await axios.post<MeetingCaptureStartResponse>('/meeting-captures', startPayload(request), {
            signal: abortController.signal,
        });
        capture.value = normalizeCapture(response.data.capture);
        links = response.data.links;
        analysisDriver.value = response.data.analysis_driver === 'responses' ? 'responses' : 'realtime';
        options.onStatus(capture.value.status, capture.value.failure);
        persist();
        beginPolling();

        return capture.value;
    }

    async function assignSalesperson(participantId: number): Promise<void> {
        if (capture.value === null || !Number.isInteger(participantId) || participantId <= 0) {
            return;
        }

        const preAssignmentAnalysis = analysisPromise;
        const response = await axios.patch<ParticipantAssignmentResponse>(
            `/meeting-captures/${capture.value.id}/participants/${participantId}`,
            { sales_role: 'salesperson' },
            { signal: abortController?.signal },
        );
        const generationAfterPatch = analysisWorkerGeneration;
        const normalized = response.data.participants
            .map(normalizeMeetingParticipant)
            .filter((participant): participant is MeetingParticipant => participant !== null);
        participants.value = normalized;
        options.onParticipants([...normalized]);
        persist(recoveryMode);
        if (analysisDriver.value === 'responses') {
            wakePolling();
            return;
        }
        if (preAssignmentAnalysis !== null) {
            await preAssignmentAnalysis;
        }
        if (analysisWorkerGeneration > generationAfterPatch) {
            if (analysisPromise !== null) {
                await analysisPromise;
            }
        } else {
            await claimAnalysisDeliveries();
        }
        if (capture.value !== null && terminalStatuses.has(capture.value.status)) {
            if (analysisIsDrained() && stopDrain === null) {
                recoveryMode = false;
                localStorage.removeItem(storageKey);
            } else {
                recoveryMode = true;
                persist(true);
                wakePolling();
            }
        }
    }

    async function stop(): Promise<void> {
        if (capture.value === null || links === null) {
            return;
        }

        stopDrain = {
            startedAt: Date.now(),
            quietSince: null,
        };
        const token = runToken;
        const deadline = new Promise<'timeout'>((resolve) => {
            stopDeadlineTimer = setTimeout(() => {
                if (token === runToken && stopDrain !== null) {
                    timeoutStopDrain();
                }
                resolve('timeout');
            }, stopTimeoutMs);
        });
        const stopRequest = axios
            .post(links.stop, {}, { signal: abortController?.signal })
            .then(() => 'continue' as const)
            .catch(() => {
                if (token === runToken) {
                    options.onError('The stop request could not be confirmed; continuing recovery polling.');
                }
                return 'continue' as const;
            });
        if ((await Promise.race([stopRequest, deadline])) === 'timeout') {
            clearStopDeadline();
            return;
        }

        wakePolling();
        beginPolling();
        const activePoll = pollPromise;
        if (activePoll !== null) {
            await Promise.race([activePoll.then(() => 'drained' as const), deadline]);
        }
        clearStopDeadline();
        stopDrain = null;
        isPolling.value = false;
    }

    async function resume(): Promise<boolean> {
        const persisted = readPersistedSession();
        if (persisted === null || (terminalStatuses.has(persisted.capture.status) && !persisted.recovery)) {
            localStorage.removeItem(storageKey);
            return false;
        }

        haltPolling(false);
        capture.value = persisted.capture;
        links = persisted.links;
        cursor.value = persisted.cursor;
        insightCursor.value = persisted.insightCursor;
        analysisDriver.value = persisted.analysisDriver;
        participants.value = persisted.participants;
        isAnalysisDelayed.value = false;
        recoveryMode = persisted.recovery;
        retryIndex = 0;
        networkWarningActive = false;
        analysisWarningActive = false;
        analysisDrainedThroughCursor = -1;
        reportedContractGaps.clear();
        lastAnalysisCounts = { pending: 0, processing: 0, completed: 0, failed: 0 };
        appliedServerToolCallIds.clear();
        abortController = new AbortController();
        options.onStatus(capture.value.status, capture.value.failure);
        if (participants.value.length > 0) {
            options.onParticipants([...participants.value]);
        }
        if (terminalStatuses.has(capture.value.status) && recoveryMode) {
            stopDrain = {
                startedAt: Date.now(),
                quietSince: null,
            };
            const token = runToken;
            stopDeadlineTimer = setTimeout(() => {
                if (token === runToken && stopDrain !== null) {
                    timeoutStopDrain();
                }
            }, stopTimeoutMs);
        }
        persist(recoveryMode);
        beginPolling();
        return true;
    }

    function disconnect(): void {
        const preserve = capture.value !== null && (!terminalStatuses.has(capture.value.status) || recoveryMode);
        haltPolling(preserve);
    }

    return {
        capture,
        participants,
        cursor,
        insightCursor,
        isPolling,
        isAnalysisDelayed,
        analysisDriver,
        usesServerAnalysis: computed(() => analysisDriver.value === 'responses'),
        start,
        stop,
        assignSalesperson,
        claimAnalysisDeliveries,
        acknowledgeAnalysisDelivery,
        resume,
        disconnect,
    };
}
