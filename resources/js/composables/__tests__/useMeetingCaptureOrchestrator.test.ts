import {
    shouldRequestLocalPermissions,
    shouldShowParticipantAssignment,
    useMeetingCaptureOrchestrator,
    type MeetingCaptureOrchestratorDependencies,
} from '@/composables/useMeetingCaptureOrchestrator';
import type { MeetingParticipant } from '@/types/meetingCapture';
import { describe, expect, it, vi } from 'vitest';

function createHarness(overrides: Partial<MeetingCaptureOrchestratorDependencies> = {}): {
    calls: string[];
    dependencies: MeetingCaptureOrchestratorDependencies;
    orchestrator: ReturnType<typeof useMeetingCaptureOrchestrator>;
} {
    const calls: string[] = [];
    const record = (name: string) => {
        calls.push(name);
    };
    const dependencies: MeetingCaptureOrchestratorDependencies = {
        createLocalConversation: vi.fn(async () => {
            record('create-local-conversation');
            return 11;
        }),
        endConversation: vi.fn(async (sessionId) => {
            record(`end-conversation:${sessionId}`);
        }),
        enableLocalProtection: vi.fn(async () => {
            record('enable-local-protection');
        }),
        connectSalespersonTranscription: vi.fn(async () => {
            record('connect-salesperson');
        }),
        connectCustomerTranscription: vi.fn(async () => {
            record('connect-customer');
        }),
        disconnectSalespersonTranscription: vi.fn(() => {
            record('disconnect-salesperson');
        }),
        disconnectCustomerTranscription: vi.fn(() => {
            record('disconnect-customer');
        }),
        drainLocalTranscriptions: vi.fn(async () => {
            record('drain-local-transcriptions');
            return true;
        }),
        startMicrophone: vi.fn(async () => {
            record('start-microphone');
        }),
        startSystemAudio: vi.fn(async () => {
            record('start-system-audio');
        }),
        stopLocalAudio: vi.fn(async () => {
            record('stop-local-audio');
        }),
        checkLocalPermissions: vi.fn(async () => {
            record('check-local-permissions');
        }),
        checkRecallReadiness: vi.fn(async () => {
            record('check-recall-readiness');
            return { configured: true, webhookReady: true };
        }),
        startRecallCapture: vi.fn(async () => {
            record('start-recall-capture');
            return { conversationId: 42, usesRealtimeCopilot: false };
        }),
        stopRecallCapture: vi.fn(async () => {
            record('stop-recall-capture');
            return true;
        }),
        disconnectRecall: vi.fn(() => {
            record('disconnect-recall');
        }),
        connectCopilot: vi.fn(async () => {
            record('connect-copilot');
        }),
        flushCopilot: vi.fn(async () => {
            record('flush-copilot');
            return true;
        }),
        disconnectCopilot: vi.fn(() => {
            record('disconnect-copilot');
        }),
        flushPersistence: vi.fn(async () => {
            record('flush-persistence');
            return true;
        }),
        setSessionId: vi.fn((sessionId) => {
            record(`set-session:${sessionId ?? 'null'}`);
        }),
        setActive: vi.fn((active) => {
            record(`set-active:${String(active)}`);
        }),
        setConnectionStatus: vi.fn((status) => {
            record(`status:${status}`);
        }),
        onError: vi.fn(),
        ...overrides,
    };

    return {
        calls,
        dependencies,
        orchestrator: useMeetingCaptureOrchestrator(dependencies),
    };
}

describe('useMeetingCaptureOrchestrator', () => {
    it('starts only the local audio and transcription dependencies in Local mode', async () => {
        const { calls, dependencies, orchestrator } = createHarness();

        expect(await orchestrator.startLocal()).toBe(true);

        expect(calls).toEqual([
            'status:connecting',
            'check-local-permissions',
            'enable-local-protection',
            'create-local-conversation',
            'set-session:11',
            'connect-salesperson',
            'connect-customer',
            'connect-copilot',
            'start-microphone',
            'start-system-audio',
            'set-active:true',
            'status:connected',
        ]);
        expect(dependencies.startRecallCapture).not.toHaveBeenCalled();
        expect(orchestrator.mode.value).toBe('local');
    });

    it('checks local permissions before creating a conversation or connecting realtime sessions', async () => {
        const { calls, dependencies, orchestrator } = createHarness({
            checkLocalPermissions: vi.fn(async () => {
                calls.push('check-local-permissions');
                throw new Error('Microphone access is denied. Grant it in System Settings, then try again.');
            }),
        });

        expect(await orchestrator.startLocal()).toBe(false);

        expect(calls).toEqual([
            'status:connecting',
            'check-local-permissions',
            'stop-local-audio',
            'disconnect-salesperson',
            'disconnect-customer',
            'disconnect-copilot',
            'set-session:null',
            'set-active:false',
            'status:disconnected',
        ]);
        expect(dependencies.createLocalConversation).not.toHaveBeenCalled();
        expect(dependencies.connectSalespersonTranscription).not.toHaveBeenCalled();
        expect(dependencies.connectCustomerTranscription).not.toHaveBeenCalled();
        expect(dependencies.connectCopilot).not.toHaveBeenCalled();
        expect(orchestrator.error.value).toBe('Microphone access is denied. Grant it in System Settings, then try again.');
    });

    it('reports realtime setup failures without blaming audio permissions', async () => {
        const { orchestrator } = createHarness({
            connectCopilot: vi.fn(async () => {
                throw new Error('Request failed with status code 500');
            }),
        });

        expect(await orchestrator.startLocal()).toBe(false);

        expect(orchestrator.error.value).toBe('Unable to connect to OpenAI Realtime. Check the API key and realtime model settings.');
    });

    it('starts Recall polling without a Realtime copilot in Responses mode', async () => {
        const { calls, dependencies, orchestrator } = createHarness();

        expect(await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example')).toBe(true);

        expect(calls).toEqual([
            'status:connecting',
            'check-recall-readiness',
            'start-recall-capture',
            'set-session:42',
            'set-active:true',
            'status:connected',
        ]);
        expect(dependencies.connectSalespersonTranscription).not.toHaveBeenCalled();
        expect(dependencies.connectCustomerTranscription).not.toHaveBeenCalled();
        expect(orchestrator.mode.value).toBe('recall');
    });

    it('never calls a local permission or audio dependency in Recall mode', async () => {
        const { dependencies, orchestrator } = createHarness();

        await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example');

        expect(dependencies.checkLocalPermissions).not.toHaveBeenCalled();
        expect(dependencies.enableLocalProtection).not.toHaveBeenCalled();
        expect(dependencies.startMicrophone).not.toHaveBeenCalled();
        expect(dependencies.startSystemAudio).not.toHaveBeenCalled();
        expect(dependencies.stopLocalAudio).not.toHaveBeenCalled();
    });

    it('uses the combined Recall conversation id without creating a second conversation', async () => {
        const { dependencies, orchestrator } = createHarness();

        await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example');

        expect(orchestrator.currentSessionId.value).toBe(42);
        expect(dependencies.setSessionId).toHaveBeenCalledWith(42);
        expect(dependencies.createLocalConversation).not.toHaveBeenCalled();
    });

    it('resumes a persisted Recall capture without a Realtime copilot in Responses mode', async () => {
        const { calls, dependencies, orchestrator } = createHarness();

        expect(await orchestrator.resumeRecall(73, false)).toBe(true);

        expect(calls).toEqual(['status:connecting', 'set-session:73', 'set-active:true', 'status:connected']);
        expect(dependencies.checkRecallReadiness).not.toHaveBeenCalled();
        expect(dependencies.startRecallCapture).not.toHaveBeenCalled();
        expect(dependencies.startMicrophone).not.toHaveBeenCalled();
        expect(dependencies.connectSalespersonTranscription).not.toHaveBeenCalled();
        expect(orchestrator.mode.value).toBe('recall');
    });

    it('rolls back a partial Recall startup', async () => {
        const connectError = new Error('socket unavailable');
        const { calls, orchestrator } = createHarness({
            startRecallCapture: vi.fn(async () => {
                calls.push('start-recall-capture');
                return { conversationId: 42, usesRealtimeCopilot: true };
            }),
            connectCopilot: vi.fn(async () => {
                throw connectError;
            }),
        });

        expect(await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example')).toBe(false);

        expect(calls).toContain('stop-recall-capture');
        expect(calls).toContain('end-conversation:42');
        expect(calls).toContain('disconnect-recall');
        expect(calls).toContain('disconnect-copilot');
        expect(orchestrator.mode.value).toBeNull();
        expect(orchestrator.currentSessionId.value).toBeNull();
    });

    it('disconnects a failed Recall startup while preserving timed-out recovery state', async () => {
        const { calls, orchestrator } = createHarness({
            startRecallCapture: vi.fn(async () => {
                calls.push('start-recall-capture');
                return { conversationId: 42, usesRealtimeCopilot: true };
            }),
            connectCopilot: vi.fn(async () => {
                throw new Error('socket unavailable');
            }),
            stopRecallCapture: vi.fn(async () => {
                calls.push('stop-recall-capture');
                return false;
            }),
        });

        expect(await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example')).toBe(false);

        expect(calls).toContain('disconnect-recall');
        expect(calls).toContain('disconnect-copilot');
        expect(calls).not.toContain('end-conversation:42');
        expect(orchestrator.currentSessionId.value).toBe(42);
        expect(orchestrator.mode.value).toBe('recall');
        expect(orchestrator.recoveryRequired.value).toBe(true);
    });

    it('stops Responses-backed Recall without flushing a Realtime copilot', async () => {
        const { calls, orchestrator } = createHarness();
        await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example');
        calls.length = 0;

        expect(await orchestrator.stop()).toBe(true);

        expect(calls).toEqual([
            'stop-recall-capture',
            'flush-persistence',
            'end-conversation:42',
            'disconnect-recall',
            'set-session:null',
            'set-active:false',
            'status:disconnected',
        ]);
    });

    it('preserves resumable state when Recall drain times out', async () => {
        const { calls, dependencies, orchestrator } = createHarness({
            stopRecallCapture: vi.fn(async () => {
                calls.push('stop-recall-capture');
                return false;
            }),
        });
        await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example');
        calls.length = 0;

        expect(await orchestrator.stop()).toBe(false);

        expect(calls).toEqual(['stop-recall-capture']);
        expect(dependencies.endConversation).not.toHaveBeenCalled();
        expect(dependencies.disconnectRecall).not.toHaveBeenCalled();
        expect(orchestrator.currentSessionId.value).toBe(42);
        expect(orchestrator.mode.value).toBe('recall');
        expect(orchestrator.recoveryRequired.value).toBe(true);
    });

    it('requires an explicit Teams URL', async () => {
        const { dependencies, orchestrator } = createHarness();

        expect(await orchestrator.startRecall('   ')).toBe(false);

        expect(dependencies.checkRecallReadiness).not.toHaveBeenCalled();
        expect(dependencies.startRecallCapture).not.toHaveBeenCalled();
        expect(orchestrator.error.value).toBe('Enter a Teams meeting URL.');
    });

    it('offers but never auto-starts Local fallback after 507', async () => {
        const capacityError = { response: { status: 507 } };
        const { dependencies, orchestrator } = createHarness({
            startRecallCapture: vi.fn(async () => {
                throw capacityError;
            }),
        });

        expect(await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example')).toBe(false);

        expect(orchestrator.localFallbackAvailable.value).toBe(true);
        expect(dependencies.createLocalConversation).not.toHaveBeenCalled();
        expect(dependencies.startMicrophone).not.toHaveBeenCalled();
    });

    it('shows Recall settings action when readiness is missing', async () => {
        const { dependencies, orchestrator } = createHarness({
            checkRecallReadiness: vi.fn(async () => ({ configured: false, webhookReady: false })),
        });

        expect(await orchestrator.startRecall('https://teams.microsoft.com/l/meetup-join/example')).toBe(false);

        expect(orchestrator.needsRecallSettings.value).toBe(true);
        expect(dependencies.startRecallCapture).not.toHaveBeenCalled();
    });

    it('requests explicit participant assignment as soon as the first human joins', () => {
        const participants: MeetingParticipant[] = [
            {
                id: 1,
                providerParticipantId: 'host',
                displayName: 'Account Owner',
                isHost: true,
                isBot: false,
                salesRole: 'unknown',
                emailPresent: true,
            },
            {
                id: 2,
                providerParticipantId: 'guest',
                displayName: 'Guest',
                isBot: false,
                salesRole: 'unknown',
                emailPresent: false,
            },
        ];

        expect(shouldShowParticipantAssignment('recall', [participants[0]])).toBe(true);
        expect(shouldShowParticipantAssignment('recall', participants)).toBe(true);
        expect(participants.every((participant) => participant.salesRole === 'unknown')).toBe(true);
        expect(shouldShowParticipantAssignment('local', participants)).toBe(false);
        expect(
            shouldShowParticipantAssignment(
                'recall',
                participants.map((participant, index) => ({ ...participant, salesRole: index ? 'customer' : 'salesperson' })),
            ),
        ).toBe(false);
    });

    it('checks local permissions only after Local mode is selected', () => {
        expect(shouldRequestLocalPermissions(null)).toBe(false);
        expect(shouldRequestLocalPermissions('local')).toBe(true);
        expect(shouldRequestLocalPermissions('recall')).toBe(false);
    });
});
