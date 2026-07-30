import { useRecallMeetingSession } from '@/composables/useRecallMeetingSession';
import type { AnalysisClaimApiResponse, AnalysisDelivery, MeetingCaptureStartRequest, RecallTranscriptTurn } from '@/types/meetingCapture';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
    },
}));

class MemoryStorage implements Storage {
    private values = new Map<string, string>();

    get length(): number {
        return this.values.size;
    }

    clear(): void {
        this.values.clear();
    }

    getItem(key: string): string | null {
        return this.values.get(key) ?? null;
    }

    key(index: number): string | null {
        return [...this.values.keys()][index] ?? null;
    }

    removeItem(key: string): void {
        this.values.delete(key);
    }

    setItem(key: string, value: string): void {
        this.values.set(key, value);
    }
}

const startRequest: MeetingCaptureStartRequest = {
    provider: 'recall',
    meetingUrl: 'https://teams.microsoft.com/l/meetup-join/test',
    idempotencyKey: '58b157c6-7081-49f1-a86c-298b4a2b43a5',
};

const startResponse = {
    capture: {
        id: 'capture-1',
        conversation_id: 42,
        provider: 'recall',
        status: 'active',
        failure_code: null,
        failure_message: null,
    },
    links: {
        events: '/meeting-captures/capture-1/events',
        stop: '/meeting-captures/capture-1/stop',
        claim_analysis: '/meeting-captures/capture-1/analysis-deliveries/claim',
    },
};

const emptyClaim: AnalysisClaimApiResponse = {
    deliveries: [],
    counts: { pending: 0, processing: 0, completed: 0, failed: 0 },
};

const participantEvent = {
    type: 'participant.upsert',
    cursor: 1,
    participant: {
        id: 9,
        provider_participant_id: 'teams-9',
        display_name: 'Grace Hopper',
        is_host: true,
        is_bot: false,
        sales_role: 'salesperson',
        email_present: false,
    },
};

const finalEvent = {
    type: 'transcript.final',
    cursor: 2,
    turn: {
        event_id: 2,
        provider_item_id: 'item-2',
        utterance_key: 'utterance-2',
        participant_id: 9,
        display_name: 'Grace Hopper',
        sales_role: 'salesperson',
        text: 'We can start the security review next week.',
        started_at: 100,
        ended_at: 500,
    },
};

const partialEvent = {
    type: 'transcript.partial',
    cursor: 3,
    turn: {
        event_id: 3,
        utterance_key: 'utterance-3',
        participant_id: 9,
        display_name: 'Grace Hopper',
        sales_role: 'salesperson',
        text: 'A partial thought',
        started_at: 600,
    },
};

const delivery: AnalysisDelivery = {
    id: 17,
    leaseToken: '1de7a4bc-fbd2-4d7d-876c-16d9d2902877',
    providerItemId: 'item-2',
    participantId: 9,
    displayName: 'Grace Hopper',
    salesRole: 'salesperson',
    text: 'We can start the security review next week.',
    startedAt: 100,
    endedAt: 500,
    context: [],
};

function deliveryResponse(value: AnalysisDelivery) {
    return {
        id: value.id,
        lease_token: value.leaseToken,
        provider_item_id: value.providerItemId,
        participant_id: value.participantId,
        display_name: value.displayName,
        sales_role: value.salesRole,
        text: value.text,
        started_at: value.startedAt,
        ended_at: value.endedAt,
        context: value.context.map((turn) => ({
            provider_item_id: turn.providerItemId,
            participant_id: turn.participantId,
            display_name: turn.displayName,
            sales_role: turn.salesRole,
            text: turn.text,
            started_at: turn.startedAt,
            ended_at: turn.endedAt,
        })),
    };
}

function eventPage(events: unknown[], nextCursor: number, options: { hasMore?: boolean; status?: string } = {}) {
    return {
        data: {
            events,
            next_cursor: nextCursor,
            has_more: options.hasMore ?? false,
            capture: {
                id: 'capture-1',
                status: options.status ?? 'active',
                failure_code: null,
                failure_message: null,
            },
        },
    };
}

function claimResponse(value: AnalysisClaimApiResponse = emptyClaim) {
    return { data: value };
}

function createHarness(
    overrides: {
        onPartial?: (turn: RecallTranscriptTurn) => void;
        onFinal?: (turn: RecallTranscriptTurn) => Promise<void> | void;
        onAnalysisDelivery?: (value: AnalysisDelivery) => Promise<void>;
    } = {},
) {
    const callbacks = {
        onPartial: vi.fn(overrides.onPartial ?? (() => undefined)),
        onFinal: vi.fn(overrides.onFinal ?? (() => undefined)),
        onParticipants: vi.fn(),
        onStatus: vi.fn(),
        onAnalysisDelivery: vi.fn(overrides.onAnalysisDelivery ?? (async () => undefined)),
        onUiTool: vi.fn(async () => undefined),
        onError: vi.fn(),
    };

    return {
        callbacks,
        session: useRecallMeetingSession(callbacks),
    };
}

async function flushPromises(): Promise<void> {
    for (let index = 0; index < 200; index += 1) {
        await Promise.resolve();
    }
}

describe('useRecallMeetingSession', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('localStorage', new MemoryStorage());
        vi.mocked(axios.post).mockReset();
        vi.mocked(axios.get).mockReset();
        vi.mocked(axios.patch).mockReset();
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }

            if (url.endsWith('/analysis-deliveries/claim')) {
                return claimResponse();
            }

            return { data: {} };
        });
        vi.mocked(axios.get).mockResolvedValue(eventPage([], 0));
        vi.mocked(axios.patch).mockResolvedValue({ data: { participants: [] } });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('polls every 250ms while nonterminal with one request in flight', async () => {
        let resolveFirst: ((value: ReturnType<typeof eventPage>) => void) | undefined;
        vi.mocked(axios.get).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        const { session } = createHarness();

        await session.start(startRequest);
        await flushPromises();
        expect(axios.get).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1_000);
        expect(axios.get).toHaveBeenCalledTimes(1);

        resolveFirst?.(eventPage([], 0));
        await flushPromises();
        await vi.advanceTimersByTimeAsync(249);
        expect(axios.get).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(axios.get).toHaveBeenCalledTimes(2);
        session.disconnect();
    });

    it('drains has_more pages immediately', async () => {
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([participantEvent], 1, { hasMore: true }))
            .mockResolvedValueOnce(eventPage([finalEvent], 2))
            .mockResolvedValue(eventPage([], 2));
        const { session, callbacks } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(callbacks.onParticipants).toHaveBeenCalledTimes(1);
        expect(callbacks.onFinal).toHaveBeenCalledTimes(1);
        expect(session.cursor.value).toBe(2);
        session.disconnect();
    });

    it('polls persisted Responses insights without claiming renderer analysis deliveries', async () => {
        const responsesStart = {
            ...startResponse,
            analysis_driver: 'responses' as const,
            links: {
                ...startResponse.links,
                insights: '/meeting-captures/capture-1/insights',
            },
        };
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: responsesStart };
            }

            return { data: {} };
        });
        vi.mocked(axios.get).mockImplementation(async (url: string) => {
            if (url.endsWith('/events')) {
                return eventPage([], 0);
            }

            return {
                data: {
                    ui_tools: [
                        {
                            name: 'suggest_talk_track',
                            call_id: 'call-server-1',
                            arguments: {
                                text: 'Ask how much time the current process takes.',
                                reason: 'Quantify the pain',
                                priority: 'high',
                            },
                            context: {
                                analysisDeliveryId: 7,
                                evidenceItemIds: ['item-1'],
                                evidenceItemDeliveryIds: { 'item-1': 7 },
                            },
                        },
                    ],
                    next_cursor: 5,
                    has_more: false,
                    counts: { pending: 0, processing: 0, completed: 1, failed: 0 },
                },
            };
        });
        const { session, callbacks } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        expect(session.usesServerAnalysis.value).toBe(true);
        expect(session.insightCursor.value).toBe(5);
        expect(callbacks.onUiTool).toHaveBeenCalledTimes(1);
        expect(callbacks.onAnalysisDelivery).not.toHaveBeenCalled();
        expect(vi.mocked(axios.post).mock.calls.some(([url]) => String(url).endsWith('/analysis-deliveries/claim'))).toBe(false);
        session.disconnect();
    });

    it('drains has_more pages while analysis remains deferred', async () => {
        let resolveAnalysis: (() => void) | undefined;
        const deferredAnalysis = new Promise<void>((resolve) => {
            resolveAnalysis = resolve;
        });
        let claimIndex = 0;
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([finalEvent], 2, { hasMore: true }))
            .mockResolvedValueOnce(eventPage([], 2))
            .mockResolvedValue(eventPage([], 2));
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery)],
                              counts: { ...emptyClaim.counts, processing: 1 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const { session } = createHarness({ onAnalysisDelivery: async () => deferredAnalysis });

        await session.start(startRequest);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(session.cursor.value).toBe(2);
        expect(vi.mocked(axios.post).mock.calls.some(([url]) => String(url).endsWith('/ack'))).toBe(false);

        session.disconnect();
        resolveAnalysis?.();
        await flushPromises();
    });

    it('keeps the 250ms event cadence while analysis remains deferred', async () => {
        let resolveAnalysis: (() => void) | undefined;
        const deferredAnalysis = new Promise<void>((resolve) => {
            resolveAnalysis = resolve;
        });
        let claimIndex = 0;
        vi.mocked(axios.get).mockResolvedValue(eventPage([finalEvent], 2));
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery)],
                              counts: { ...emptyClaim.counts, processing: 1 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const { session } = createHarness({ onAnalysisDelivery: async () => deferredAnalysis });

        await session.start(startRequest);
        await flushPromises();
        expect(axios.get).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(9);
        session.disconnect();
        resolveAnalysis?.();
        await flushPromises();
    });

    it('retries from the durable cursor after failure', async () => {
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([participantEvent], 1))
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValue(eventPage([], 1));
        const { session, callbacks } = createHarness();

        await session.start(startRequest);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(callbacks.onError).toHaveBeenCalledWith(expect.stringContaining('retry'));
        await vi.advanceTimersByTimeAsync(250);
        expect(axios.get).toHaveBeenLastCalledWith(
            '/meeting-captures/capture-1/events',
            expect.objectContaining({ params: { after: 1, limit: 100 } }),
        );
        session.disconnect();
    });

    it('persists the page cursor only after every durable final has been applied', async () => {
        let resolveFinal: (() => void) | undefined;
        const finalApplied = new Promise<void>((resolve) => {
            resolveFinal = resolve;
        });
        vi.mocked(axios.get).mockResolvedValueOnce(eventPage([finalEvent], 2));
        const { session } = createHarness({ onFinal: async () => finalApplied });

        await session.start(startRequest);
        await flushPromises();

        expect(session.cursor.value).toBe(0);
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"cursor":0');

        resolveFinal?.();
        await flushPromises();

        expect(session.cursor.value).toBe(2);
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"cursor":2');
        session.disconnect();
    });

    it('stops at a malformed final gap and retries from the last durable cursor', async () => {
        const malformedFinal = {
            ...finalEvent,
            turn: {
                ...finalEvent.turn,
                provider_item_id: undefined,
            },
        };
        const afterGap = {
            type: 'capture.status',
            cursor: 3,
            status: 'active',
        };
        vi.mocked(axios.get).mockResolvedValue(eventPage([participantEvent, malformedFinal, afterGap], 3));
        const { session, callbacks } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        expect(session.cursor.value).toBe(1);
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"cursor":1');
        expect(callbacks.onFinal).not.toHaveBeenCalled();
        expect(callbacks.onError).toHaveBeenCalledTimes(1);
        expect(callbacks.onError).toHaveBeenCalledWith(expect.stringContaining('event'));
        expect(vi.mocked(axios.post).mock.calls.filter(([url]) => String(url).endsWith('/analysis-deliveries/claim'))).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(axios.get).toHaveBeenLastCalledWith(
            '/meeting-captures/capture-1/events',
            expect.objectContaining({ params: { after: 1, limit: 100 } }),
        );
        expect(callbacks.onError).toHaveBeenCalledTimes(1);
        session.disconnect();
    });

    it('bounds every analysis claim by the current durable cursor', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(eventPage([finalEvent], 2));
        const { session } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/meeting-captures/capture-1/analysis-deliveries/claim', { through_cursor: 2 }, expect.any(Object));
        session.disconnect();
    });

    it('deduplicates replayed cursors', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(eventPage([participantEvent, participantEvent, finalEvent], 2));
        const { session, callbacks } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        expect(callbacks.onParticipants).toHaveBeenCalledTimes(1);
        expect(callbacks.onFinal).toHaveBeenCalledTimes(1);
        expect(session.cursor.value).toBe(2);
        session.disconnect();
    });

    it('keeps partials display only', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(eventPage([partialEvent], 3));
        const { session, callbacks } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        expect(callbacks.onPartial).toHaveBeenCalledTimes(1);
        expect(callbacks.onFinal).not.toHaveBeenCalled();
        expect(callbacks.onAnalysisDelivery).not.toHaveBeenCalled();
        session.disconnect();
    });

    it('persists and resumes capture cursor from localStorage', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(eventPage([participantEvent], 1));
        const first = createHarness();
        await first.session.start(startRequest);
        await flushPromises();
        first.session.disconnect();

        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"cursor":1');

        vi.mocked(axios.get).mockResolvedValue(eventPage([], 1));
        const second = createHarness();
        await expect(second.session.resume()).resolves.toBe(true);
        await flushPromises();

        expect(second.session.capture.value?.id).toBe('capture-1');
        expect(second.session.cursor.value).toBe(1);
        expect(axios.get).toHaveBeenLastCalledWith(
            '/meeting-captures/capture-1/events',
            expect.objectContaining({ params: { after: 1, limit: 100 } }),
        );
        second.session.disconnect();
    });

    it('claims role-resolved turns in backend order', async () => {
        const later = { ...delivery, id: 18, startedAt: 900, text: 'Second' };
        let claimIndex = 0;
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery), deliveryResponse(later)],
                              counts: { ...emptyClaim.counts, processing: 2 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const order: number[] = [];
        const { session } = createHarness({
            onAnalysisDelivery: async (item) => {
                order.push(item.id);
            },
        });

        await session.start(startRequest);
        await flushPromises();

        expect(order).toEqual([17, 18]);
        expect(vi.mocked(axios.post).mock.calls.filter(([url]) => String(url).endsWith('/ack'))).toHaveLength(2);
        session.disconnect();
    });

    it('submits a claimed analysis batch concurrently and acknowledges each delivery independently', async () => {
        const later = {
            ...delivery,
            id: 18,
            leaseToken: '57b47ab1-0f21-47ef-8a98-592fe7739aa7',
            providerItemId: 'item-18',
            startedAt: 900,
            text: 'Second',
        };
        const resolvers = new Map<number, () => void>();
        let claimIndex = 0;
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery), deliveryResponse(later)],
                              counts: { ...emptyClaim.counts, processing: 2 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const started: number[] = [];
        const { session } = createHarness({
            onAnalysisDelivery: async (item) => {
                started.push(item.id);
                await new Promise<void>((resolve) => resolvers.set(item.id, resolve));
            },
        });

        await session.start(startRequest);
        await flushPromises();

        expect(started).toEqual([17, 18]);
        expect(vi.mocked(axios.post).mock.calls.some(([url]) => String(url).endsWith('/ack'))).toBe(false);

        resolvers.get(18)?.();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/meeting-captures/capture-1/analysis-deliveries/18/ack',
            {
                lease_token: later.leaseToken,
                status: 'completed',
            },
            expect.any(Object),
        );
        expect(vi.mocked(axios.post).mock.calls.some(([url]) => String(url).endsWith('/analysis-deliveries/17/ack'))).toBe(false);

        resolvers.get(17)?.();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/meeting-captures/capture-1/analysis-deliveries/17/ack',
            {
                lease_token: delivery.leaseToken,
                status: 'completed',
            },
            expect.any(Object),
        );
        session.disconnect();
    });

    it('drains more than ten terminal analysis deliveries before cleanup', async () => {
        const deliveries = Array.from({ length: 12 }, (_, index) => ({
            ...delivery,
            id: delivery.id + index,
            leaseToken: `00000000-0000-4000-8000-${String(index).padStart(12, '0')}`,
            startedAt: delivery.startedAt + index,
            text: `Turn ${index + 1}`,
        }));
        const batches: AnalysisClaimApiResponse[] = [
            {
                deliveries: deliveries.slice(0, 10).map(deliveryResponse),
                counts: { ...emptyClaim.counts, processing: 12 },
            },
            {
                deliveries: deliveries.slice(10).map(deliveryResponse),
                counts: { ...emptyClaim.counts, processing: 2 },
            },
            {
                deliveries: [],
                counts: { ...emptyClaim.counts, completed: 12 },
            },
        ];
        let claimIndex = 0;
        vi.mocked(axios.get).mockResolvedValue(eventPage([], 0, { status: 'ended' }));
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                return claimResponse(batches[claimIndex++] ?? emptyClaim);
            }
            return { data: {} };
        });
        const analyzed: number[] = [];
        const { session } = createHarness({
            onAnalysisDelivery: async (item) => {
                analyzed.push(item.id);
            },
        });

        await session.start(startRequest);
        await flushPromises();

        expect(analyzed).toEqual(deliveries.map((item) => item.id));
        expect(vi.mocked(axios.post).mock.calls.filter(([url]) => String(url).endsWith('/analysis-deliveries/claim'))).toHaveLength(3);
        expect(vi.mocked(axios.post).mock.calls.filter(([url]) => String(url).endsWith('/ack'))).toHaveLength(12);
        expect(localStorage.getItem('clueless.recall.active-capture')).toBeNull();
        session.disconnect();
    });

    it('keeps terminal event polling alive until deferred analysis drains', async () => {
        let resolveAnalysis: (() => void) | undefined;
        const deferredAnalysis = new Promise<void>((resolve) => {
            resolveAnalysis = resolve;
        });
        let claimIndex = 0;
        vi.mocked(axios.get).mockResolvedValue(eventPage([finalEvent], 2, { status: 'ended' }));
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery)],
                              counts: { ...emptyClaim.counts, processing: 1 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const { session } = createHarness({ onAnalysisDelivery: async () => deferredAnalysis });

        await session.start(startRequest);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(9);
        expect(session.isPolling.value).toBe(true);
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"recovery":true');

        resolveAnalysis?.();
        await flushPromises();
        await vi.advanceTimersByTimeAsync(250);
        await flushPromises();

        expect(session.isPolling.value).toBe(false);
        expect(localStorage.getItem('clueless.recall.active-capture')).toBeNull();
        session.disconnect();
    });

    it('keeps stop draining events while a multi-second analysis callback is deferred', async () => {
        let resolveAnalysis: (() => void) | undefined;
        const deferredAnalysis = new Promise<void>((resolve) => {
            resolveAnalysis = resolve;
        });
        let claimIndex = 0;
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([finalEvent], 2, { status: 'active' }))
            .mockResolvedValue(eventPage([], 2, { status: 'ended' }));
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery)],
                              counts: { ...emptyClaim.counts, processing: 1 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const { session } = createHarness({ onAnalysisDelivery: async () => deferredAnalysis });
        await session.start(startRequest);
        await flushPromises();

        let stopped = false;
        const stopping = session.stop().then(() => {
            stopped = true;
        });
        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();

        expect(stopped).toBe(false);
        expect(axios.get).toHaveBeenCalledTimes(10);

        resolveAnalysis?.();
        await flushPromises();
        await vi.advanceTimersByTimeAsync(250);
        await stopping;

        expect(stopped).toBe(true);
        expect(localStorage.getItem('clueless.recall.active-capture')).toBeNull();
    });

    it('acks only after the analysis callback resolves', async () => {
        let resolveAnalysis: (() => void) | undefined;
        const analysis = new Promise<void>((resolve) => {
            resolveAnalysis = resolve;
        });
        let claimIndex = 0;
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery)],
                              counts: { ...emptyClaim.counts, processing: 1 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const { session } = createHarness({ onAnalysisDelivery: async () => analysis });

        await session.start(startRequest);
        await flushPromises();
        expect(vi.mocked(axios.post).mock.calls.some(([url]) => String(url).endsWith('/ack'))).toBe(false);

        resolveAnalysis?.();
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith(
            '/meeting-captures/capture-1/analysis-deliveries/17/ack',
            {
                lease_token: delivery.leaseToken,
                status: 'completed',
            },
            expect.any(Object),
        );
        session.disconnect();
    });

    it('leaves a lease unacked when the renderer is disconnected', async () => {
        let resolveAnalysis: (() => void) | undefined;
        const analysis = new Promise<void>((resolve) => {
            resolveAnalysis = resolve;
        });
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                return claimResponse({
                    deliveries: [deliveryResponse(delivery)],
                    counts: { ...emptyClaim.counts, processing: 1 },
                });
            }
            return { data: {} };
        });
        const { session } = createHarness({ onAnalysisDelivery: async () => analysis });

        await session.start(startRequest);
        await flushPromises();
        session.disconnect();
        resolveAnalysis?.();
        await flushPromises();

        expect(vi.mocked(axios.post).mock.calls.some(([url]) => String(url).endsWith('/ack'))).toBe(false);
    });

    it('does not let an old analysis finally clear a restarted session guard', async () => {
        let resolveOld: (() => void) | undefined;
        let resolveNew: (() => void) | undefined;
        const oldAnalysis = new Promise<void>((resolve) => {
            resolveOld = resolve;
        });
        const newAnalysis = new Promise<void>((resolve) => {
            resolveNew = resolve;
        });
        const newDelivery = {
            ...delivery,
            id: 18,
            leaseToken: '57b47ab1-0f21-47ef-8a98-592fe7739aa7',
            providerItemId: 'item-18',
        };
        let claimIndex = 0;
        const claims: AnalysisClaimApiResponse[] = [
            { deliveries: [deliveryResponse(delivery)], counts: { ...emptyClaim.counts, processing: 1 } },
            { deliveries: [deliveryResponse(newDelivery)], counts: { ...emptyClaim.counts, processing: 1 } },
            emptyClaim,
        ];
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                return claimResponse(claims[claimIndex++] ?? emptyClaim);
            }
            return { data: {} };
        });
        const { session } = createHarness({
            onAnalysisDelivery: async (item) => (item.id === delivery.id ? oldAnalysis : newAnalysis),
        });

        await session.start(startRequest);
        await flushPromises();
        session.disconnect();
        await session.start(startRequest);
        await flushPromises();

        resolveOld?.();
        await flushPromises();
        void session.claimAnalysisDeliveries();
        await flushPromises();

        expect(vi.mocked(axios.post).mock.calls.filter(([url]) => String(url).endsWith('/analysis-deliveries/claim'))).toHaveLength(2);

        resolveNew?.();
        await flushPromises();
        session.disconnect();
    });

    it('acks analysis failures with a safe code', async () => {
        let claimIndex = 0;
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                return claimResponse(
                    claimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery)],
                              counts: { ...emptyClaim.counts, processing: 1 },
                          }
                        : emptyClaim,
                );
            }
            return { data: {} };
        });
        const { session } = createHarness({
            onAnalysisDelivery: async () => {
                throw new Error('secret renderer details');
            },
        });

        await session.start(startRequest);
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/meeting-captures/capture-1/analysis-deliveries/17/ack',
            {
                lease_token: delivery.leaseToken,
                status: 'failed',
                error_code: 'copilot_analysis_failed',
            },
            expect.any(Object),
        );
        expect(JSON.stringify(vi.mocked(axios.post).mock.calls)).not.toContain('secret renderer details');
        session.disconnect();
    });

    it('leaves the lease recoverable when a completed ack fails', async () => {
        vi.mocked(axios.post).mockImplementation(async (url: string, payload?: unknown) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                return claimResponse({
                    deliveries: [deliveryResponse(delivery)],
                    counts: { ...emptyClaim.counts, processing: 1 },
                });
            }
            if (url.endsWith('/ack') && (payload as { status?: unknown }).status === 'completed') {
                throw new Error('ack offline');
            }
            return { data: {} };
        });
        const { session } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        const ackPayloads = vi
            .mocked(axios.post)
            .mock.calls.filter(([url]) => String(url).endsWith('/ack'))
            .map(([, payload]) => payload);
        expect(ackPayloads).toEqual([
            {
                lease_token: delivery.leaseToken,
                status: 'completed',
            },
        ]);
        session.disconnect();
    });

    it('marks analysis delayed when pending turns are not claimable', async () => {
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                return claimResponse({ deliveries: [], counts: { ...emptyClaim.counts, pending: 2 } });
            }
            return { data: {} };
        });
        const { session } = createHarness();

        await session.start(startRequest);
        await flushPromises();

        expect(session.isAnalysisDelayed.value).toBe(true);
        session.disconnect();
    });

    it('parks terminal polling for unknown-role analysis and drains after live assignment', async () => {
        const unknownParticipantEvent = {
            ...participantEvent,
            participant: {
                ...participantEvent.participant,
                sales_role: 'unknown',
            },
        };
        let assigned = false;
        let delivered = false;
        vi.mocked(axios.get).mockResolvedValue(eventPage([unknownParticipantEvent], 1, { status: 'ended' }));
        vi.mocked(axios.patch).mockImplementation(async () => {
            assigned = true;
            return {
                data: {
                    participants: [
                        {
                            ...unknownParticipantEvent.participant,
                            sales_role: 'salesperson',
                        },
                    ],
                },
            };
        });
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                if (!assigned) {
                    return claimResponse({
                        deliveries: [],
                        counts: { ...emptyClaim.counts, pending: 1 },
                    });
                }
                if (!delivered) {
                    delivered = true;
                    return claimResponse({
                        deliveries: [deliveryResponse(delivery)],
                        counts: { ...emptyClaim.counts, processing: 1 },
                    });
                }
                return claimResponse({ deliveries: [], counts: { ...emptyClaim.counts, completed: 1 } });
            }
            return { data: {} };
        });
        const { session, callbacks } = createHarness();

        await session.start(startRequest);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();

        expect(session.isAnalysisDelayed.value).toBe(true);
        expect(session.isPolling.value).toBe(false);
        expect(axios.get).toHaveBeenCalledTimes(2);
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"recovery":true');
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"salesRole":"unknown"');
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"cursor":1');

        await session.assignSalesperson(9);
        await flushPromises();

        expect(callbacks.onAnalysisDelivery).toHaveBeenCalledWith(expect.objectContaining({ id: delivery.id }));
        expect(callbacks.onParticipants).toHaveBeenLastCalledWith([expect.objectContaining({ salesRole: 'salesperson' })]);
        expect(session.isPolling.value).toBe(false);
        expect(localStorage.getItem('clueless.recall.active-capture')).toBeNull();
        session.disconnect();
    });

    it('starts a fresh post-role claim after a deferred pre-assignment worker', async () => {
        const unknownParticipantEvent = {
            ...participantEvent,
            participant: {
                ...participantEvent.participant,
                sales_role: 'unknown',
            },
        };
        let resolvePreRoleClaim: ((value: ReturnType<typeof claimResponse>) => void) | undefined;
        const preRoleClaim = new Promise<ReturnType<typeof claimResponse>>((resolve) => {
            resolvePreRoleClaim = resolve;
        });
        let claimIndex = 0;
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([unknownParticipantEvent], 1, { status: 'ended' }))
            .mockImplementation(() => new Promise(() => undefined));
        vi.mocked(axios.patch).mockResolvedValue({
            data: {
                participants: [
                    {
                        ...unknownParticipantEvent.participant,
                        sales_role: 'salesperson',
                    },
                ],
            },
        });
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                claimIndex += 1;
                if (claimIndex === 1) {
                    return preRoleClaim;
                }
                if (claimIndex === 2) {
                    return claimResponse({
                        deliveries: [deliveryResponse(delivery)],
                        counts: { ...emptyClaim.counts, processing: 1 },
                    });
                }
                return claimResponse({ deliveries: [], counts: { ...emptyClaim.counts, completed: 1 } });
            }
            return { data: {} };
        });
        const { session, callbacks } = createHarness();
        await session.start(startRequest);
        await flushPromises();

        expect(claimIndex).toBe(1);
        const assigning = session.assignSalesperson(9);
        await flushPromises();
        expect(axios.patch).toHaveBeenCalledTimes(1);
        expect(claimIndex).toBe(1);

        resolvePreRoleClaim?.(
            claimResponse({
                deliveries: [],
                counts: { ...emptyClaim.counts, pending: 1 },
            }),
        );
        await assigning;
        await flushPromises();

        expect(claimIndex).toBe(3);
        expect(callbacks.onAnalysisDelivery).toHaveBeenCalledWith(expect.objectContaining({ id: delivery.id }));
        expect(localStorage.getItem('clueless.recall.active-capture')).toBeNull();
        session.disconnect();
    });

    it('assigns the salesperson then claims newly eligible analysis', async () => {
        vi.mocked(axios.patch).mockResolvedValue({
            data: {
                participants: [
                    {
                        id: 9,
                        provider_participant_id: 'teams-9',
                        display_name: 'Grace Hopper',
                        is_bot: false,
                        sales_role: 'salesperson',
                        email_present: false,
                    },
                ],
            },
        });
        const { session, callbacks } = createHarness();
        await session.start(startRequest);
        await flushPromises();
        vi.mocked(axios.post).mockClear();

        await session.assignSalesperson(9);

        expect(callbacks.onParticipants).toHaveBeenLastCalledWith([expect.objectContaining({ id: 9, salesRole: 'salesperson' })]);
        expect(axios.post).toHaveBeenCalledWith('/meeting-captures/capture-1/analysis-deliveries/claim', { through_cursor: 0 }, expect.any(Object));
        session.disconnect();
    });

    it('stops after terminal state and a one-second quiet drain', async () => {
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([], 0))
            .mockResolvedValue(eventPage([], 0, { status: 'ended' }));
        const { session } = createHarness();
        await session.start(startRequest);
        await flushPromises();

        const stopping = session.stop();
        await vi.advanceTimersByTimeAsync(1_250);
        await stopping;

        expect(session.isPolling.value).toBe(false);
        expect(localStorage.getItem('clueless.recall.active-capture')).toBeNull();
    });

    it('never completes a stop quiet drain across a malformed terminal event gap', async () => {
        const terminalEvent = {
            type: 'capture.status',
            cursor: 1,
            status: 'ended',
        };
        const malformedFinal = {
            ...finalEvent,
            cursor: 2,
            turn: {
                ...finalEvent.turn,
                provider_item_id: undefined,
            },
        };
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([], 0, { status: 'active' }))
            .mockResolvedValue(eventPage([terminalEvent, malformedFinal], 2, { status: 'ended' }));
        const { session, callbacks } = createHarness();
        await session.start(startRequest);
        await flushPromises();

        let stopped = false;
        const stopping = session.stop().then(() => {
            stopped = true;
        });
        await vi.advanceTimersByTimeAsync(1_250);
        await flushPromises();

        expect(stopped).toBe(false);
        expect(session.cursor.value).toBe(1);
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"recovery":true');
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"cursor":1');

        await vi.advanceTimersByTimeAsync(8_750);
        await stopping;

        expect(callbacks.onError).toHaveBeenCalledWith(expect.stringContaining('resume'));
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"recovery":true');
        expect(localStorage.getItem('clueless.recall.active-capture')).toContain('"cursor":1');
    });

    it('preserves recoverable state on drain timeout', async () => {
        vi.mocked(axios.get).mockResolvedValue(eventPage([], 0, { status: 'active' }));
        const { session, callbacks } = createHarness();
        await session.start(startRequest);
        await flushPromises();

        const stopping = session.stop();
        await vi.advanceTimersByTimeAsync(10_500);
        await stopping;

        expect(callbacks.onError).toHaveBeenCalledWith(expect.stringContaining('resume'));
        expect(localStorage.getItem('clueless.recall.active-capture')).not.toBeNull();
        expect(session.isPolling.value).toBe(false);
    });

    it('enforces the drain timeout while an event request is hung', async () => {
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([], 0, { status: 'active' }))
            .mockImplementationOnce(() => new Promise(() => undefined));
        const { session, callbacks } = createHarness();
        await session.start(startRequest);
        await flushPromises();

        let stopped = false;
        const stopping = session.stop().then(() => {
            stopped = true;
        });
        await vi.advanceTimersByTimeAsync(10_000);
        await flushPromises();

        expect(stopped).toBe(true);
        expect(callbacks.onError).toHaveBeenCalledWith(expect.stringContaining('resume'));
        expect(localStorage.getItem('clueless.recall.active-capture')).not.toBeNull();
        await stopping;
    });

    it('resumes terminal recovery after a timeout and drains outstanding analysis', async () => {
        let firstClaimIndex = 0;
        vi.mocked(axios.get)
            .mockResolvedValueOnce(eventPage([participantEvent], 1, { status: 'active' }))
            .mockResolvedValueOnce(eventPage([], 1, { status: 'ended' }))
            .mockImplementationOnce(() => new Promise(() => undefined));
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url === '/meeting-captures') {
                return { data: startResponse };
            }
            if (url.endsWith('/analysis-deliveries/claim')) {
                firstClaimIndex += 1;
                return claimResponse({
                    deliveries: [],
                    counts: firstClaimIndex === 1 ? emptyClaim.counts : { ...emptyClaim.counts, processing: 1 },
                });
            }
            return { data: {} };
        });
        const first = createHarness();
        await first.session.start(startRequest);
        await flushPromises();

        const stopping = first.session.stop();
        await vi.advanceTimersByTimeAsync(10_000);
        await stopping;

        const persisted = localStorage.getItem('clueless.recall.active-capture');
        expect(persisted).toContain('"recovery":true');
        expect(persisted).toContain('"displayName":"Grace Hopper"');

        let resumedClaimIndex = 0;
        vi.mocked(axios.get).mockResolvedValue(eventPage([], 1, { status: 'ended' }));
        vi.mocked(axios.post).mockImplementation(async (url: string) => {
            if (url.endsWith('/analysis-deliveries/claim')) {
                resumedClaimIndex += 1;
                return claimResponse(
                    resumedClaimIndex === 1
                        ? {
                              deliveries: [deliveryResponse(delivery)],
                              counts: { ...emptyClaim.counts, processing: 1 },
                          }
                        : { deliveries: [], counts: { ...emptyClaim.counts, completed: 1 } },
                );
            }
            return { data: {} };
        });
        const second = createHarness();

        await expect(second.session.resume()).resolves.toBe(true);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(1_000);
        await flushPromises();

        expect(second.callbacks.onParticipants).toHaveBeenCalledWith([expect.objectContaining({ displayName: 'Grace Hopper' })]);
        expect(second.callbacks.onAnalysisDelivery).toHaveBeenCalledWith(expect.objectContaining({ id: delivery.id }));
        expect(localStorage.getItem('clueless.recall.active-capture')).toBeNull();
        second.session.disconnect();
    });
});
