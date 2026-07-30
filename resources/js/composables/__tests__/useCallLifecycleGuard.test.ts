import { useCallLifecycleGuard, type CallLifecycleBridge, type LifecycleCloseRequest } from '@/composables/useCallLifecycleGuard';
import { describe, expect, it, vi } from 'vitest';

interface TestVisit {
    url: string;
}

function createHarness(stopSession: () => Promise<boolean>) {
    const calls: string[] = [];
    let closeRequested: ((request: LifecycleCloseRequest) => void | Promise<void>) | null = null;
    let beforeNavigation: ((visit: TestVisit) => boolean) | null = null;
    const replayResults: boolean[] = [];

    const bridge: CallLifecycleBridge = {
        setBusy: async (busy) => {
            calls.push(`busy:${String(busy)}`);
        },
        completeClose: async (request) => {
            calls.push(`complete-close:${request.requestToken}`);
        },
        cancelClose: async (request) => {
            calls.push(`cancel-close:${request.requestToken}`);
        },
        onCloseRequested: (listener) => {
            closeRequested = listener;
            return () => {
                closeRequested = null;
            };
        },
    };

    const guard = useCallLifecycleGuard<TestVisit>({
        bridge,
        stopSession: async () => {
            calls.push('stop');
            return stopSession();
        },
        onBeforeNavigation: (listener) => {
            beforeNavigation = listener;
            return () => {
                beforeNavigation = null;
            };
        },
        replayNavigation: (visit) => {
            calls.push(`replay:${visit.url}`);
            replayResults.push(beforeNavigation?.(visit) ?? true);
        },
    });

    return {
        calls,
        guard,
        replayResults,
        emitCloseRequest: async (request: LifecycleCloseRequest = { generationToken: 'generation-1', requestToken: 'request-1' }) => {
            await closeRequested?.(request);
        },
        navigate: (visit: TestVisit) => beforeNavigation?.(visit) ?? true,
    };
}

describe('useCallLifecycleGuard', () => {
    it('completes a native close only after the active call stops successfully', async () => {
        const harness = createHarness(async () => true);
        await harness.guard.markBusy(true);

        await harness.emitCloseRequest();

        expect(harness.calls).toEqual(['busy:true', 'stop', 'complete-close:request-1']);
        expect(harness.guard.isBusy()).toBe(false);
    });

    it('cancels a native close and remains busy when call shutdown fails', async () => {
        const harness = createHarness(async () => false);
        await harness.guard.markBusy(true);

        await harness.emitCloseRequest();

        expect(harness.calls).toEqual(['busy:true', 'stop', 'cancel-close:request-1']);
        expect(harness.guard.isBusy()).toBe(true);
    });

    it('cancels active-call navigation, stops once, and replays exactly once without recursion', async () => {
        const stopSession = vi.fn(async () => true);
        const harness = createHarness(stopSession);
        await harness.guard.markBusy(true);
        const visit = { url: '/dashboard' };

        expect(harness.navigate(visit)).toBe(false);
        await vi.waitFor(() => {
            expect(harness.calls).toContain('replay:/dashboard');
        });

        expect(stopSession).toHaveBeenCalledTimes(1);
        expect(harness.calls).toEqual(['busy:true', 'stop', 'busy:false', 'replay:/dashboard']);
        expect(harness.replayResults).toEqual([true]);
    });

    it('keeps the current page when active-call navigation shutdown fails', async () => {
        const harness = createHarness(async () => false);
        await harness.guard.markBusy(true);

        expect(harness.navigate({ url: '/dashboard' })).toBe(false);
        await vi.waitFor(() => {
            expect(harness.calls).toContain('stop');
        });

        expect(harness.calls).toEqual(['busy:true', 'stop']);
        expect(harness.guard.isBusy()).toBe(true);
    });

    it('allows idle navigation without attempting shutdown', () => {
        const stopSession = vi.fn(async () => true);
        const harness = createHarness(stopSession);

        expect(harness.navigate({ url: '/dashboard' })).toBe(true);
        expect(stopSession).not.toHaveBeenCalled();
    });

    it('remains locally busy when native busy registration fails', async () => {
        const harness = createHarness(async () => true);
        harness.guard.dispose();
        const guard = useCallLifecycleGuard<TestVisit>({
            bridge: {
                setBusy: async () => {
                    throw new Error('IPC unavailable');
                },
                completeClose: async () => undefined,
                cancelClose: async () => undefined,
                onCloseRequested: () => () => undefined,
            },
            stopSession: async () => true,
            onBeforeNavigation: () => () => undefined,
            replayNavigation: () => undefined,
        });

        await expect(guard.markBusy(true)).rejects.toThrow('IPC unavailable');
        expect(guard.isBusy()).toBe(true);
    });

    it('retries native busy registration after an IPC failure', async () => {
        let attempts = 0;
        const guard = useCallLifecycleGuard<TestVisit>({
            bridge: {
                setBusy: async () => {
                    attempts++;
                    if (attempts === 1) throw new Error('IPC unavailable');
                },
                completeClose: async () => undefined,
                cancelClose: async () => undefined,
                onCloseRequested: () => () => undefined,
            },
            stopSession: async () => true,
            onBeforeNavigation: () => () => undefined,
            replayNavigation: () => undefined,
        });

        await expect(guard.markBusy(true)).rejects.toThrow('IPC unavailable');
        await guard.markBusy(true);

        expect(attempts).toBe(2);
        expect(guard.isBusy()).toBe(true);
    });

    it('acknowledges native busy state before capture activation begins', async () => {
        const order: string[] = [];
        let acknowledgeBusy!: () => void;
        const busyAcknowledged = new Promise<void>((resolve) => {
            acknowledgeBusy = resolve;
        });
        const guard = useCallLifecycleGuard<TestVisit>({
            bridge: {
                setBusy: async (busy) => {
                    order.push(`busy:${String(busy)}:start`);
                    await busyAcknowledged;
                    order.push(`busy:${String(busy)}:done`);
                },
                completeClose: async () => undefined,
                cancelClose: async () => undefined,
                onCloseRequested: () => () => undefined,
            },
            stopSession: async () => true,
            onBeforeNavigation: () => () => undefined,
            replayNavigation: () => undefined,
        });

        const activation = guard.runProtectedActivation(
            async () => {
                order.push('activate');
                return true;
            },
            () => true,
        );
        await Promise.resolve();
        expect(order).toEqual(['busy:true:start']);

        acknowledgeBusy();
        await activation;

        expect(order).toEqual(['busy:true:start', 'busy:true:done', 'activate']);
    });

    it('releases lifecycle protection after startup rollback leaves no resumable capture', async () => {
        const harness = createHarness(async () => true);

        const started = await harness.guard.runProtectedActivation(
            async () => false,
            () => false,
        );

        expect(started).toBe(false);
        expect(harness.calls).toEqual(['busy:true', 'busy:false']);
        expect(harness.guard.isBusy()).toBe(false);
    });

    it('waits for in-flight capture activation before handling a close request', async () => {
        let finishActivation!: () => void;
        const activationGate = new Promise<void>((resolve) => {
            finishActivation = resolve;
        });
        const harness = createHarness(async () => true);
        const activation = harness.guard.runProtectedActivation(
            async () => {
                harness.calls.push('activate:start');
                await activationGate;
                harness.calls.push('activate:done');
                return true;
            },
            () => true,
        );
        await Promise.resolve();

        const closing = harness.emitCloseRequest();
        await Promise.resolve();
        expect(harness.calls).toEqual(['busy:true', 'activate:start']);

        finishActivation();
        await activation;
        await closing;

        expect(harness.calls).toEqual(['busy:true', 'activate:start', 'activate:done', 'stop', 'complete-close:request-1']);
    });

    it('tracks activation before native busy acknowledgement returns to the renderer', async () => {
        const order: string[] = [];
        let closeRequested!: (request: LifecycleCloseRequest) => void | Promise<void>;
        let acknowledgeBusy!: () => void;
        const busyAcknowledged = new Promise<void>((resolve) => {
            acknowledgeBusy = resolve;
        });
        const guard = useCallLifecycleGuard<TestVisit>({
            bridge: {
                setBusy: async (busy) => {
                    order.push(`busy:${String(busy)}:start`);
                    if (busy) await busyAcknowledged;
                    order.push(`busy:${String(busy)}:done`);
                },
                completeClose: async () => {
                    order.push('complete-close');
                },
                cancelClose: async () => undefined,
                onCloseRequested: (listener) => {
                    closeRequested = listener;
                    return () => undefined;
                },
            },
            stopSession: async () => {
                order.push('stop');
                return true;
            },
            onBeforeNavigation: () => () => undefined,
            replayNavigation: () => undefined,
        });

        const activation = guard.runProtectedActivation(
            async () => {
                order.push('activate');
                return true;
            },
            () => true,
        );
        await Promise.resolve();
        const closing = Promise.resolve(closeRequested({ generationToken: 'generation-1', requestToken: 'request-1' }));
        await Promise.resolve();
        expect(order).toEqual(['busy:true:start']);

        acknowledgeBusy();
        await activation;
        await closing;

        expect(order).toEqual(['busy:true:start', 'busy:true:done', 'activate', 'stop', 'complete-close']);
    });
});
