import { useCallLifecycleAdapter, type InertiaLifecycleRouter } from '@/composables/useCallLifecycleAdapter';
import type { CallLifecycleBridge, LifecycleCloseRequest } from '@/composables/useCallLifecycleGuard';
import { describe, expect, it, vi } from 'vitest';

interface TestVisit {
    url: string;
    method?: string;
}

function createHarness(stopSession: () => Promise<boolean>) {
    let beforeListener: ((event: { detail: { visit: TestVisit } }) => boolean | void) | null = null;
    let closeListener: ((request: LifecycleCloseRequest) => void | Promise<void>) | null = null;
    const replayResults: Array<boolean | void> = [];
    const router: InertiaLifecycleRouter<TestVisit> = {
        on: (_event, listener) => {
            beforeListener = listener;
            return () => {
                beforeListener = null;
            };
        },
        visit: vi.fn((url, options) => {
            replayResults.push(beforeListener?.({ detail: { visit: { url, ...options } } }));
        }),
    };
    const bridge: CallLifecycleBridge = {
        setBusy: vi.fn(async () => ({ success: true })),
        completeClose: vi.fn(async () => ({ success: true })),
        cancelClose: vi.fn(async () => ({ success: true })),
        onCloseRequested: (listener) => {
            closeListener = listener;
            return () => {
                closeListener = null;
            };
        },
    };
    const guard = useCallLifecycleAdapter({
        bridge,
        router,
        stopSession,
    });

    return {
        bridge,
        guard,
        replayResults,
        router,
        emitClose: async (request: LifecycleCloseRequest) => closeListener?.(request),
        navigate: (visit: TestVisit) => beforeListener?.({ detail: { visit } }),
    };
}

describe('useCallLifecycleAdapter', () => {
    it('binds the real Inertia before event to stop and one replay without recursion', async () => {
        const stopSession = vi.fn(async () => true);
        const harness = createHarness(stopSession);
        await harness.guard.markBusy(true);

        expect(harness.navigate({ url: '/dashboard', method: 'get' })).toBe(false);
        await vi.waitFor(() => expect(harness.router.visit).toHaveBeenCalledOnce());

        expect(stopSession).toHaveBeenCalledOnce();
        expect(harness.router.visit).toHaveBeenCalledWith('/dashboard', { method: 'get' });
        expect(harness.replayResults).toEqual([true]);
    });

    it('forwards the native one-time request to completion after a successful stop', async () => {
        const request = { generationToken: 'generation-1', requestToken: 'request-1' };
        const harness = createHarness(async () => true);
        await harness.guard.markBusy(true);

        await harness.emitClose(request);

        expect(harness.bridge.completeClose).toHaveBeenCalledWith(request);
        expect(harness.bridge.cancelClose).not.toHaveBeenCalled();
    });

    it('forwards the same native request to cancellation when stop fails', async () => {
        const request = { generationToken: 'generation-1', requestToken: 'request-1' };
        const harness = createHarness(async () => false);
        await harness.guard.markBusy(true);

        await harness.emitClose(request);

        expect(harness.bridge.cancelClose).toHaveBeenCalledWith(request);
        expect(harness.bridge.completeClose).not.toHaveBeenCalled();
        expect(harness.guard.isBusy()).toBe(true);
    });
});
