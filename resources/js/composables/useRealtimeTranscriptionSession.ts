import { normalizeRealtimeEvents, parseRealtimeEvent } from '@/services/realtimeEventNormalizer';
import { openRealtimeSocket } from '@/services/realtimeSocket';
import { TranscriptCommitTracker } from '@/services/transcriptCommitTracker';
import axios from 'axios';
import { ref } from 'vue';

interface Options {
    purpose: 'salesperson_transcription' | 'customer_transcription';
    speaker: 'salesperson' | 'customer';
    templateId?: string | null | (() => string | null | undefined);
    onDelta: (itemId: string, text: string) => void;
    onCompleted: (itemId: string, text: string) => void;
    onError: (message: string) => void;
}

const MAX_BUFFERED_AUDIO_FRAMES = 80;
const MAX_SOCKET_BUFFER_BYTES = 1_000_000;
const MAX_RECONNECT_ATTEMPTS = 3;
const MIN_COMMIT_PCM16_BYTES = 4_800;

export function useRealtimeTranscriptionSession(options: Options) {
    const status = ref<'disconnected' | 'connecting' | 'connected'>('disconnected');
    const socket = ref<WebSocket | null>(null);
    const deltas = new Map<string, string>();
    const currentFrames: string[] = [];
    const commitTracker = new TranscriptCommitTracker();
    let connectPromise: Promise<void> | null = null;
    let desiredConnected = false;
    let reconnectAttempts = 0;
    let sentFrameCount = 0;
    let needsReplayCommit = false;
    let flushTimer: ReturnType<typeof setTimeout> | null = null;

    const connect = async () => {
        desiredConnected = true;
        reconnectAttempts = 0;
        await ensureConnected();
    };

    const ensureConnected = () => {
        if (socket.value?.readyState === WebSocket.OPEN && status.value === 'connected') {
            return Promise.resolve();
        }

        if (!connectPromise) {
            connectPromise = connectSocket().finally(() => {
                connectPromise = null;
            });
        }

        return connectPromise;
    };

    const connectSocket = async () => {
        status.value = 'connecting';
        const { data } = await axios.post('/api/realtime/client-secret', {
            purpose: options.purpose,
            template_id: resolveTemplateId(options.templateId),
        });
        const ws = await openRealtimeSocket(data.clientSecret);
        socket.value = ws;

        const ready = waitForSessionReady(ws);

        ws.addEventListener('message', (event) => {
            const parsed = parseRealtimeEvent(event);
            if (!parsed) return;

            if (parsed.type === 'input_audio_buffer.committed' && typeof parsed.item_id === 'string') {
                commitTracker.acknowledge(parsed.item_id);
            }

            const normalizedEvents = normalizeRealtimeEvents(parsed);
            for (const normalized of normalizedEvents) {
                if (normalized.type === 'transcript.delta') {
                    const next = `${deltas.get(normalized.itemId) ?? ''}${normalized.delta}`;
                    deltas.set(normalized.itemId, next);
                    options.onDelta(normalized.itemId, next);
                }

                if (normalized.type === 'transcript.completed') {
                    deltas.delete(normalized.itemId);
                    options.onCompleted(normalized.itemId, normalized.transcript);
                    commitTracker.complete(normalized.itemId);
                }

                if (normalized.type === 'error') {
                    options.onError(normalized.message);
                }
            }

            if (
                parsed.type === 'conversation.item.input_audio_transcription.completed' &&
                !normalizedEvents.some((item) => item.type === 'transcript.completed')
            ) {
                commitTracker.complete(parsed.item_id);
            }
        });

        ws.addEventListener('close', () => {
            if (socket.value !== ws) return;
            socket.value = null;
            replayUnfinishedAudio();
            status.value = desiredConnected ? 'connecting' : 'disconnected';
            if (desiredConnected) scheduleReconnect();
        });

        await ready;
        reconnectAttempts = 0;
        status.value = 'connected';
        flushAudioFrames();
        if (needsReplayCommit && sentFrameCount === currentFrames.length) {
            needsReplayCommit = false;
            commit();
        }
    };

    const appendPcm16 = (base64Audio: string) => {
        if (!base64Audio) return;
        currentFrames.push(base64Audio);
        if (currentFrames.length > MAX_BUFFERED_AUDIO_FRAMES) {
            currentFrames.shift();
            sentFrameCount = Math.max(0, sentFrameCount - 1);
        }

        const ws = socket.value;
        if (ws?.readyState === WebSocket.OPEN && status.value === 'connected' && ws.bufferedAmount < MAX_SOCKET_BUFFER_BYTES) {
            sendAudio(ws, base64Audio);
            sentFrameCount++;
            return;
        }

        scheduleAudioFlush();
    };

    const commit = () => {
        const ws = socket.value;
        flushAudioFrames();
        if (
            currentFrames.length === 0 ||
            sentFrameCount !== currentFrames.length ||
            ws?.readyState !== WebSocket.OPEN ||
            status.value !== 'connected'
        ) {
            return false;
        }

        ws.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
        commitTracker.enqueue(currentFrames.splice(0));
        sentFrameCount = 0;
        return true;
    };

    const commitAndWait = async (timeoutMs = 5_000): Promise<boolean> => {
        discardShortAudioTail();
        const deadline = Date.now() + timeoutMs;
        while (currentFrames.length > 0 && !commit() && Date.now() < deadline) {
            await new Promise((resolve) => globalThis.setTimeout(resolve, 50));
        }

        const remainingMs = Math.max(0, deadline - Date.now());
        return currentFrames.length === 0 && commitTracker.waitUntilEmpty(remainingMs);
    };

    const discardShortAudioTail = () => {
        if (currentFrames.length === 0 || bufferedPcm16Bytes(currentFrames) >= MIN_COMMIT_PCM16_BYTES) {
            return;
        }

        const ws = socket.value;
        if (ws?.readyState === WebSocket.OPEN && status.value === 'connected' && sentFrameCount > 0) {
            ws.send(JSON.stringify({ type: 'input_audio_buffer.clear' }));
        }

        currentFrames.length = 0;
        sentFrameCount = 0;
        needsReplayCommit = false;
    };

    const disconnect = () => {
        desiredConnected = false;
        socket.value?.close();
        socket.value = null;
        deltas.clear();
        currentFrames.length = 0;
        commitTracker.reset();
        if (flushTimer) globalThis.clearTimeout(flushTimer);
        flushTimer = null;
        sentFrameCount = 0;
        needsReplayCommit = false;
        status.value = 'disconnected';
    };

    const flushAudioFrames = () => {
        const ws = socket.value;
        if (!ws || ws.readyState !== WebSocket.OPEN) return;

        while (sentFrameCount < currentFrames.length && ws.bufferedAmount < MAX_SOCKET_BUFFER_BYTES) {
            sendAudio(ws, currentFrames[sentFrameCount++]);
        }

        if (sentFrameCount < currentFrames.length) {
            scheduleAudioFlush();
        } else if (needsReplayCommit && status.value === 'connected') {
            needsReplayCommit = false;
            commit();
        }
    };

    const scheduleAudioFlush = () => {
        if (flushTimer) return;
        flushTimer = globalThis.setTimeout(() => {
            flushTimer = null;
            flushAudioFrames();
        }, 50);
    };

    const replayUnfinishedAudio = () => {
        const replayFrames = commitTracker.takeFramesForReplay().concat(currentFrames);
        currentFrames.splice(0, currentFrames.length, ...replayFrames.slice(-MAX_BUFFERED_AUDIO_FRAMES));
        sentFrameCount = 0;
        needsReplayCommit = currentFrames.length > 0;
    };

    const scheduleReconnect = () => {
        if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
            desiredConnected = false;
            status.value = 'disconnected';
            options.onError(`${options.speaker} transcription disconnected after retrying`);
            return;
        }

        const delay = 500 * 2 ** reconnectAttempts++;
        globalThis.setTimeout(() => {
            if (!desiredConnected) return;
            void ensureConnected().catch(() => scheduleReconnect());
        }, delay);
    };

    return {
        status,
        connect,
        appendPcm16,
        commit,
        commitAndWait,
        disconnect,
    };
}

function sendAudio(socket: WebSocket, audio: string) {
    socket.send(
        JSON.stringify({
            type: 'input_audio_buffer.append',
            audio,
        }),
    );
}

function bufferedPcm16Bytes(frames: string[]): number {
    return frames.reduce((total, frame) => {
        const padding = frame.endsWith('==') ? 2 : frame.endsWith('=') ? 1 : 0;
        return total + Math.max(0, Math.floor((frame.length * 3) / 4) - padding);
    }, 0);
}

function waitForSessionReady(socket: WebSocket, timeoutMs = 5_000) {
    return new Promise<void>((resolve, reject) => {
        const timeout = globalThis.setTimeout(() => {
            cleanup();
            reject(new Error('Realtime transcription session did not become ready'));
        }, timeoutMs);

        const cleanup = () => {
            globalThis.clearTimeout(timeout);
            socket.removeEventListener('message', handleMessage);
            socket.removeEventListener('close', handleClose);
        };
        const handleMessage = (event: MessageEvent) => {
            const parsed = parseRealtimeEvent(event);
            if (!parsed || !normalizeRealtimeEvents(parsed).some((item) => item.type === 'session.ready')) return;
            cleanup();
            resolve();
        };
        const handleClose = () => {
            cleanup();
            reject(new Error('Realtime transcription session closed during setup'));
        };

        socket.addEventListener('message', handleMessage);
        socket.addEventListener('close', handleClose, { once: true });
    });
}

function resolveTemplateId(templateId: Options['templateId']) {
    return typeof templateId === 'function' ? templateId() : templateId;
}
