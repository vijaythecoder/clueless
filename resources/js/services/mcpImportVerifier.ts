import { parseRealtimeEvent } from '@/services/realtimeEventNormalizer';
import { openRealtimeSocket } from '@/services/realtimeSocket';

interface VerifyOptions {
    timeoutMs?: number;
    openSocket?: typeof openRealtimeSocket;
}

export async function verifyMcpImport(clientSecret: string, model: string, options: VerifyOptions = {}): Promise<string[]> {
    const socket = await (options.openSocket ?? openRealtimeSocket)(clientSecret, model);

    return new Promise<string[]>((resolve, reject) => {
        const timeout = globalThis.setTimeout(() => {
            finish(() => reject(new Error('Timed out while importing MCP tools')));
        }, options.timeoutMs ?? 10_000);

        const finish = (callback: () => void) => {
            globalThis.clearTimeout(timeout);
            socket.removeEventListener('message', handleMessage);
            socket.removeEventListener('close', handleClose);
            socket.close();
            callback();
        };
        const handleMessage = (event: MessageEvent) => {
            const payload = parseRealtimeEvent(event);
            if (!payload) return;

            if (payload.type === 'mcp_list_tools.failed') {
                finish(() => reject(new Error(payload.error?.message ?? 'OpenAI could not import MCP tools')));
                return;
            }

            if (payload.type === 'conversation.item.done' && payload.item?.type === 'mcp_list_tools') {
                const toolNames = (payload.item.tools ?? [])
                    .map((tool: { name?: string }) => tool.name)
                    .filter((name: unknown): name is string => typeof name === 'string' && name.length > 0);

                if (toolNames.length === 0) {
                    finish(() => reject(new Error('The MCP server did not expose any allowed tools')));
                    return;
                }

                finish(() => resolve(toolNames));
            }
        };
        const handleClose = () => {
            finish(() => reject(new Error('Realtime connection closed before MCP tools loaded')));
        };

        socket.addEventListener('message', handleMessage);
        socket.addEventListener('close', handleClose, { once: true });
    });
}
