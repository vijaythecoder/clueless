import { verifyMcpImport } from '@/services/mcpImportVerifier';
import { describe, expect, it, vi } from 'vitest';

class FakeSocket extends EventTarget {
    close = vi.fn();

    message(payload: unknown) {
        this.dispatchEvent(new MessageEvent('message', { data: JSON.stringify(payload) }));
    }
}

describe('verifyMcpImport', () => {
    it('resolves only after OpenAI reports imported tool names', async () => {
        const socket = new FakeSocket();
        const verification = verifyMcpImport('ek_test', 'gpt-realtime-2.1-mini', {
            openSocket: vi.fn().mockResolvedValue(socket),
            timeoutMs: 100,
        });
        await Promise.resolve();

        socket.message({
            type: 'conversation.item.done',
            item: {
                type: 'mcp_list_tools',
                server_label: 'crm',
                tools: [{ name: 'search_company' }, { name: 'read_contact' }],
            },
        });

        await expect(verification).resolves.toEqual(['search_company', 'read_contact']);
        expect(socket.close).toHaveBeenCalledOnce();
    });

    it('rejects failed imports without exposing upstream details in the UI helper', async () => {
        const socket = new FakeSocket();
        const verification = verifyMcpImport('ek_test', 'gpt-realtime-2.1-mini', {
            openSocket: vi.fn().mockResolvedValue(socket),
            timeoutMs: 100,
        });
        await Promise.resolve();

        socket.message({
            type: 'mcp_list_tools.failed',
            error: { message: 'Authentication failed' },
        });

        await expect(verification).rejects.toThrow('Authentication failed');
    });
});
