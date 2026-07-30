export type NormalizedRealtimeEvent =
    | { type: 'transcript.delta'; itemId: string; delta: string }
    | { type: 'transcript.completed'; itemId: string; transcript: string }
    | { type: 'function.call'; callId: string; name: string; arguments: Record<string, unknown>; responseId?: string }
    | { type: 'mcp.approval'; id: string; name: string; arguments: Record<string, unknown>; serverLabel?: string }
    | {
          type: 'mcp.status';
          status: 'loading' | 'ready' | 'failed' | 'running';
          message: string;
          serverLabel?: string;
          itemId?: string;
      }
    | { type: 'response.text'; text: string; final: boolean }
    | {
          type: 'response.done';
          responseId?: string;
          status: 'completed' | 'cancelled' | 'failed' | 'incomplete' | 'unknown';
      }
    | { type: 'rate_limits.updated'; remainingRequests?: number; remainingTokens?: number }
    | {
          type: 'error';
          message: string;
          clientEventId?: string;
          serverEventId?: string;
          responseId?: string;
      }
    | { type: 'session.ready'; sessionId?: string };

export function parseRealtimeEvent(raw: MessageEvent | string): Record<string, any> | null {
    try {
        const payload = typeof raw === 'string' ? raw : raw.data;
        return JSON.parse(payload);
    } catch {
        return null;
    }
}

export function normalizeRealtimeEvents(event: any): NormalizedRealtimeEvent[] {
    switch (event?.type) {
        case 'session.created':
        case 'session.updated':
            return [{ type: 'session.ready', sessionId: event.session?.id }];

        case 'conversation.item.input_audio_transcription.delta':
            return [
                {
                    type: 'transcript.delta',
                    itemId: event.item_id,
                    delta: event.delta ?? '',
                },
            ];

        case 'conversation.item.input_audio_transcription.completed': {
            const transcript = String(event.transcript ?? '').trim();
            if (!transcript) return [];
            return [
                {
                    type: 'transcript.completed',
                    itemId: event.item_id,
                    transcript,
                },
            ];
        }

        case 'response.output_text.delta':
            return [
                {
                    type: 'response.text',
                    text: event.delta ?? '',
                    final: false,
                },
            ];

        case 'response.output_text.done':
            return [
                {
                    type: 'response.text',
                    text: event.text ?? '',
                    final: true,
                },
            ];

        case 'response.function_call_arguments.done':
            return [
                {
                    type: 'function.call',
                    callId: event.call_id,
                    name: event.name,
                    arguments: safeJson(event.arguments),
                    responseId: event.response_id,
                },
            ];

        case 'response.done': {
            const status = normalizeResponseStatus(event.response?.status);
            const normalized =
                status === 'completed'
                    ? (event.response?.output ?? [])
                          .filter((item: any) => item.type === 'function_call')
                          .map(
                              (functionCall: any): NormalizedRealtimeEvent => ({
                                  type: 'function.call',
                                  callId: functionCall.call_id,
                                  name: functionCall.name,
                                  arguments: safeJson(functionCall.arguments),
                                  responseId: event.response?.id,
                              }),
                          )
                    : [];

            normalized.push({
                type: 'response.done',
                responseId: event.response?.id,
                status,
            });

            return normalized;
        }

        case 'response.output_item.done':
        case 'conversation.item.done':
            if (event.item?.type === 'mcp_approval_request') {
                return [
                    {
                        type: 'mcp.approval',
                        id: event.item.id,
                        name: event.item.name,
                        serverLabel: event.item.server_label,
                        arguments: safeJson(event.item.arguments),
                    },
                ];
            }
            if (event.item?.type === 'mcp_list_tools') {
                return [
                    {
                        type: 'mcp.status',
                        status: 'ready',
                        message: `${event.item.server_label} tools ready`,
                        serverLabel: event.item.server_label,
                        itemId: event.item.id,
                    },
                ];
            }
            return [];

        case 'mcp_list_tools.in_progress':
            return [
                {
                    type: 'mcp.status',
                    status: 'loading',
                    message: 'Loading remote sales tools',
                    serverLabel: event.server_label,
                    itemId: event.item_id,
                },
            ];

        case 'mcp_list_tools.completed':
            return [
                {
                    type: 'mcp.status',
                    status: 'ready',
                    message: `${event.server_label ?? 'Remote'} tools ready`,
                    serverLabel: event.server_label,
                    itemId: event.item_id,
                },
            ];

        case 'mcp_list_tools.failed':
        case 'response.mcp_call.failed':
            return [
                {
                    type: 'mcp.status',
                    status: 'failed',
                    message: event.error?.message ?? 'A remote sales tool failed',
                    serverLabel: event.server_label,
                    itemId: event.item_id,
                },
            ];

        case 'response.mcp_call.in_progress':
            return [
                {
                    type: 'mcp.status',
                    status: 'running',
                    message: 'Running remote sales lookup',
                    serverLabel: event.server_label,
                    itemId: event.item_id,
                },
            ];

        case 'rate_limits.updated': {
            const requestLimit = event.rate_limits?.find((limit: any) => limit.name === 'requests');
            const tokenLimit = event.rate_limits?.find((limit: any) => limit.name === 'tokens');
            return [
                {
                    type: 'rate_limits.updated',
                    remainingRequests: requestLimit?.remaining,
                    remainingTokens: tokenLimit?.remaining,
                },
            ];
        }

        case 'error':
            return [
                {
                    type: 'error',
                    message: event.error?.message ?? event.message ?? 'Realtime session error',
                    ...optionalString('clientEventId', event.error?.event_id),
                    ...optionalString('serverEventId', event.event_id),
                    ...optionalString('responseId', event.response_id ?? event.error?.response_id),
                },
            ];

        case 'invalid_request_error':
            return [
                {
                    type: 'error',
                    message: event.message ?? 'Realtime session error',
                    ...optionalString('clientEventId', event.event_id),
                    ...optionalString('responseId', event.response_id),
                },
            ];

        default:
            return [];
    }
}

export function normalizeRealtimeEvent(event: any): NormalizedRealtimeEvent | null {
    return normalizeRealtimeEvents(event)[0] ?? null;
}

function safeJson(value: unknown): Record<string, unknown> {
    if (!value) return {};
    if (typeof value === 'object') return value as Record<string, unknown>;
    try {
        return JSON.parse(String(value));
    } catch {
        return {};
    }
}

function normalizeResponseStatus(value: unknown): 'completed' | 'cancelled' | 'failed' | 'incomplete' | 'unknown' {
    return value === 'completed' || value === 'cancelled' || value === 'failed' || value === 'incomplete' ? value : 'unknown';
}

function optionalString<Key extends string>(key: Key, value: unknown): Partial<Record<Key, string>> {
    return typeof value === 'string' && value.trim() ? ({ [key]: value.trim() } as Record<Key, string>) : {};
}
