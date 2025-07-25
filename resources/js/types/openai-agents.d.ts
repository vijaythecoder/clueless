declare module '@openai/agents' {
    export interface AgentOptions {
        name: string;
        instructions?: string;
        modalities?: string[];
        tools?: any[];
        turnDetection?: TurnDetectionOptions;
    }

    export interface TurnDetectionOptions {
        type: 'server_vad' | 'none';
        threshold?: number;
        prefix_padding_ms?: number;
        silence_duration_ms?: number;
    }

    export interface AgentEvent {
        type: string;
        data?: any;
    }

    export class RealtimeAgent {
        constructor(options: AgentOptions);
        
        connect(transport: any): Promise<void>;
        disconnect(): Promise<void>;
        
        on(event: 'transcription', handler: (transcript: string) => void): void;
        on(event: 'function_call', handler: (data: { name: string; arguments: any }) => void): void;
        on(event: 'error', handler: (error: any) => void): void;
        on(event: string, handler: (data: any) => void): void;
        
        off(event: string, handler?: (...args: any[]) => void): void;
        
        sendAudio(audio: ArrayBuffer | Int16Array): void;
    }
}

declare module '@openai/agents/realtime' {
    export { RealtimeAgent } from '@openai/agents';
}

declare module '@openai/agents/realtime/websocket' {
    export interface WebSocketRealtimeTransportOptions {
        url: string;
        model: string;
        apiKey: string;
        headers?: Record<string, string>;
    }

    export class WebSocketRealtimeTransport {
        constructor(options: WebSocketRealtimeTransportOptions);
        
        connect(): Promise<void>;
        close(): void;
        
        isConnected(): boolean;
        
        send(data: any): void;
        
        on(event: string, handler: (...args: any[]) => void): void;
        off(event: string, handler?: (...args: any[]) => void): void;
    }
}

declare module '@openai/agents-realtime' {
    export * from '@openai/agents/realtime';
    export * from '@openai/agents/realtime/websocket';
}