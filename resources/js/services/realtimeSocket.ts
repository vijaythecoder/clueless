interface RealtimeSocketOptions {
    WebSocketImpl?: typeof WebSocket;
    timeoutMs?: number;
}

export function openRealtimeSocket(clientSecret: string, model?: string, options: RealtimeSocketOptions = {}): Promise<WebSocket> {
    const WebSocketImpl = options.WebSocketImpl ?? WebSocket;
    const query = model ? `?model=${encodeURIComponent(model)}` : '';
    const socket = new WebSocketImpl(`wss://api.openai.com/v1/realtime${query}`, ['realtime', `openai-insecure-api-key.${clientSecret}`]);

    return new Promise((resolve, reject) => {
        const timeout = globalThis.setTimeout(() => {
            cleanup();
            socket.close();
            reject(new Error('Realtime connection timed out'));
        }, options.timeoutMs ?? 10_000);

        const cleanup = () => {
            globalThis.clearTimeout(timeout);
            socket.removeEventListener('open', handleOpen);
            socket.removeEventListener('error', handleError);
        };
        const handleOpen = () => {
            cleanup();
            resolve(socket);
        };
        const handleError = () => {
            cleanup();
            reject(new Error('Realtime connection failed'));
        };

        socket.addEventListener('open', handleOpen, { once: true });
        socket.addEventListener('error', handleError, { once: true });
    });
}
