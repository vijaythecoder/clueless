import { useRealtimeTranscriptionSession } from '@/composables/useRealtimeTranscriptionSession';
import { openRealtimeSocket } from '@/services/realtimeSocket';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
    },
}));
vi.mock('@/services/realtimeSocket', () => ({
    openRealtimeSocket: vi.fn(),
}));

class FakeWebSocket extends EventTarget {
    static OPEN = 1;
    readyState = FakeWebSocket.OPEN;
    bufferedAmount = 0;
    sent: string[] = [];

    send(payload: string) {
        this.sent.push(payload);
    }

    close() {
        this.readyState = 3;
        this.dispatchEvent(new Event('close'));
    }

    emit(payload: Record<string, unknown>) {
        this.dispatchEvent(new MessageEvent('message', { data: JSON.stringify(payload) }));
    }
}

describe('useRealtimeTranscriptionSession', () => {
    beforeEach(() => {
        vi.stubGlobal('WebSocket', FakeWebSocket);
        vi.mocked(axios.post).mockResolvedValue({
            data: { clientSecret: 'ek_test' },
        });
    });

    it('waits for every outstanding item and handles out-of-order completions', async () => {
        const socket = new FakeWebSocket();
        vi.mocked(openRealtimeSocket).mockImplementation(async () => {
            globalThis.setTimeout(() => socket.emit({ type: 'session.created', session: { id: 'session-1' } }), 0);
            return socket as unknown as WebSocket;
        });
        const completed: string[] = [];
        const session = useRealtimeTranscriptionSession({
            purpose: 'salesperson_transcription',
            speaker: 'salesperson',
            onDelta: vi.fn(),
            onCompleted: (itemId) => completed.push(itemId),
            onError: vi.fn(),
        });

        await session.connect();
        session.appendPcm16('first');
        expect(session.commit()).toBe(true);
        session.appendPcm16('second');
        expect(session.commit()).toBe(true);

        let drained = false;
        const draining = session.commitAndWait(1_000).then((result) => {
            drained = result;
        });
        await Promise.resolve();
        expect(drained).toBe(false);

        socket.emit({ type: 'input_audio_buffer.committed', item_id: 'item-1' });
        socket.emit({ type: 'input_audio_buffer.committed', item_id: 'item-2' });
        socket.emit({
            type: 'conversation.item.input_audio_transcription.completed',
            item_id: 'item-2',
            transcript: 'Second',
        });
        expect(drained).toBe(false);
        socket.emit({
            type: 'conversation.item.input_audio_transcription.completed',
            item_id: 'item-1',
            transcript: 'First',
        });

        await draining;
        expect(drained).toBe(true);
        expect(completed).toEqual(['item-2', 'item-1']);
        session.disconnect();
    });

    it('clears a sub-100ms audio tail instead of committing an invalid buffer', async () => {
        const socket = new FakeWebSocket();
        vi.mocked(openRealtimeSocket).mockImplementation(async () => {
            globalThis.setTimeout(() => socket.emit({ type: 'session.created', session: { id: 'session-1' } }), 0);
            return socket as unknown as WebSocket;
        });
        const session = useRealtimeTranscriptionSession({
            purpose: 'salesperson_transcription',
            speaker: 'salesperson',
            onDelta: vi.fn(),
            onCompleted: vi.fn(),
            onError: vi.fn(),
        });

        await session.connect();
        session.appendPcm16(btoa('\0'.repeat(3_840)));

        await expect(session.commitAndWait()).resolves.toBe(true);
        expect(socket.sent.map((payload) => JSON.parse(payload).type)).toEqual(['input_audio_buffer.append', 'input_audio_buffer.clear']);
        session.disconnect();
    });
});
