import { normalizeRealtimeEvents } from '@/services/realtimeEventNormalizer';
import { describe, expect, it } from 'vitest';

describe('normalizeRealtimeEvents', () => {
    it('returns every UI function call from a completed response', () => {
        expect(
            normalizeRealtimeEvents({
                type: 'response.done',
                response: {
                    id: 'response-1',
                    status: 'completed',
                    output: [
                        {
                            type: 'function_call',
                            call_id: 'call-1',
                            name: 'show_knowledge_card',
                            arguments: '{"title":"Security","content":"SOC 2"}',
                        },
                        {
                            type: 'function_call',
                            call_id: 'call-2',
                            name: 'suggest_talk_track',
                            arguments: '{"text":"Lead with risk reduction"}',
                        },
                    ],
                },
            }),
        ).toEqual([
            {
                type: 'function.call',
                callId: 'call-1',
                name: 'show_knowledge_card',
                arguments: { title: 'Security', content: 'SOC 2' },
                responseId: 'response-1',
            },
            {
                type: 'function.call',
                callId: 'call-2',
                name: 'suggest_talk_track',
                arguments: { text: 'Lead with risk reduction' },
                responseId: 'response-1',
            },
            {
                type: 'response.done',
                responseId: 'response-1',
                status: 'completed',
            },
        ]);
    });

    it.each(['cancelled', 'failed', 'incomplete'] as const)('keeps the final %s response status and does not emit its output calls', (status) => {
        expect(
            normalizeRealtimeEvents({
                type: 'response.done',
                response: {
                    id: `response-${status}`,
                    status,
                    output: [
                        {
                            type: 'function_call',
                            call_id: `call-${status}`,
                            name: 'capture_pain_point',
                            arguments: '{"text":"Must not apply"}',
                        },
                    ],
                },
            }),
        ).toEqual([
            {
                type: 'response.done',
                responseId: `response-${status}`,
                status,
            },
        ]);
    });

    it('treats a response.done without a final status as unknown rather than successful', () => {
        expect(
            normalizeRealtimeEvents({
                type: 'response.done',
                response: {
                    id: 'response-unknown',
                    output: [],
                },
            }),
        ).toEqual([
            {
                type: 'response.done',
                responseId: 'response-unknown',
                status: 'unknown',
            },
        ]);
    });

    it('distinguishes the client event referenced by an error from the server error event id', () => {
        expect(
            normalizeRealtimeEvents({
                type: 'error',
                event_id: 'server-error-event',
                response_id: 'response-7',
                error: {
                    type: 'invalid_request_error',
                    code: 'invalid_value',
                    message: 'The response request was invalid.',
                    event_id: 'client-response-create',
                },
            }),
        ).toEqual([
            {
                type: 'error',
                message: 'The response request was invalid.',
                clientEventId: 'client-response-create',
                serverEventId: 'server-error-event',
                responseId: 'response-7',
            },
        ]);
    });

    it('normalizes GA MCP tool-import completion and failures', () => {
        expect(
            normalizeRealtimeEvents({
                type: 'mcp_list_tools.completed',
                server_label: 'crm',
                item_id: 'mcp-list-1',
            }),
        ).toEqual([
            {
                type: 'mcp.status',
                status: 'ready',
                message: 'crm tools ready',
                serverLabel: 'crm',
                itemId: 'mcp-list-1',
            },
        ]);

        expect(
            normalizeRealtimeEvents({
                type: 'mcp_list_tools.failed',
                server_label: 'crm',
                item_id: 'mcp-list-1',
                error: { message: 'unavailable' },
            }),
        ).toEqual([
            {
                type: 'mcp.status',
                status: 'failed',
                message: 'unavailable',
                serverLabel: 'crm',
                itemId: 'mcp-list-1',
            },
        ]);
    });

    it('uses the same MCP item id for the lifecycle event and completed item', () => {
        const lifecycle = normalizeRealtimeEvents({
            type: 'mcp_list_tools.completed',
            item_id: 'mcp-list-1',
        })[0];
        const completedItem = normalizeRealtimeEvents({
            type: 'conversation.item.done',
            item: {
                id: 'mcp-list-1',
                type: 'mcp_list_tools',
                server_label: 'crm',
            },
        })[0];

        expect(lifecycle).toMatchObject({ type: 'mcp.status', itemId: 'mcp-list-1' });
        expect(completedItem).toMatchObject({ type: 'mcp.status', itemId: 'mcp-list-1' });
    });

    it('does not emit empty final transcripts', () => {
        expect(
            normalizeRealtimeEvents({
                type: 'conversation.item.input_audio_transcription.completed',
                item_id: 'item-1',
                transcript: '   ',
            }),
        ).toEqual([]);
    });
});
