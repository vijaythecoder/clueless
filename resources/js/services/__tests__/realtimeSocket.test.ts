import { openRealtimeSocket } from '@/services/realtimeSocket';
import { describe, expect, it, vi } from 'vitest';

class FakeWebSocket extends EventTarget {
    static OPEN = 1;
    static CONNECTING = 0;
    static instances: FakeWebSocket[] = [];
    readyState = FakeWebSocket.CONNECTING;
    sent: string[] = [];

    constructor(
        public readonly url: string,
        public readonly protocols: string[],
    ) {
        super();
        FakeWebSocket.instances.push(this);
    }

    open() {
        this.readyState = FakeWebSocket.OPEN;
        this.dispatchEvent(new Event('open'));
    }

    fail() {
        this.dispatchEvent(new Event('error'));
    }

    send(payload: string) {
        this.sent.push(payload);
    }

    close() {}
}

describe('openRealtimeSocket', () => {
    it('does not resolve until the WebSocket is open', async () => {
        FakeWebSocket.instances = [];
        const promise = openRealtimeSocket('secret', undefined, {
            WebSocketImpl: FakeWebSocket as unknown as typeof WebSocket,
            timeoutMs: 100,
        });
        const settled = vi.fn();
        void promise.then(settled);

        await Promise.resolve();
        expect(settled).not.toHaveBeenCalled();

        FakeWebSocket.instances[0].open();
        await expect(promise).resolves.toBe(FakeWebSocket.instances[0]);
    });

    it('rejects failed handshakes', async () => {
        FakeWebSocket.instances = [];
        const promise = openRealtimeSocket('secret', 'gpt-realtime-2.1-mini', {
            WebSocketImpl: FakeWebSocket as unknown as typeof WebSocket,
            timeoutMs: 100,
        });
        FakeWebSocket.instances[0].fail();

        await expect(promise).rejects.toThrow('Realtime connection failed');
    });
});
