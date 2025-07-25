import { defineStore } from 'pinia';
import { 
    type RealtimeAgent,
    type RealtimeSession,
    type RealtimeSessionOptions,
} from '@openai/agents-realtime';

interface AgentInfo {
    agent: RealtimeAgent | null;
    session: RealtimeSession | null;
    isConnected: boolean;
}

export const useOpenAIStore = defineStore('openai', {
    state: () => ({
        // Agent instances
        salespersonAgent: null as AgentInfo | null,
        coachAgent: null as AgentInfo | null,
        
        // API key
        ephemeralKey: null as string | null,
        keyExpiresAt: null as number | null,
        
        // Session configuration
        sessionConfig: {
            model: 'gpt-4o-mini-realtime-preview-2024-12-17',
            instructions: '',
            voice: 'verse' as const,
            input_audio_format: 'pcm16' as const,
            output_audio_format: 'pcm16' as const,
            input_audio_transcription: {
                model: 'gpt-4o-mini-transcribe',
            },
            turn_detection: null,
            tools: [] as any[],
            tool_choice: 'auto' as const,
            temperature: 0.7,
            max_response_output_tokens: 4096,
        } as RealtimeSessionOptions,
    }),
    
    getters: {
        isSalespersonConnected: (state) => state.salespersonAgent?.isConnected ?? false,
        isCoachConnected: (state) => state.coachAgent?.isConnected ?? false,
        isAnyAgentConnected: (state) => {
            return (state.salespersonAgent?.isConnected ?? false) || 
                   (state.coachAgent?.isConnected ?? false);
        },
        hasValidKey: (state) => {
            if (!state.ephemeralKey || !state.keyExpiresAt) return false;
            return Date.now() < state.keyExpiresAt;
        },
    },
    
    actions: {
        // Key management
        setEphemeralKey(key: string, expiresIn: number) {
            this.ephemeralKey = key;
            this.keyExpiresAt = Date.now() + (expiresIn * 1000);
        },
        
        clearEphemeralKey() {
            this.ephemeralKey = null;
            this.keyExpiresAt = null;
        },
        
        // Agent management
        setSalespersonAgent(agent: RealtimeAgent, session: RealtimeSession) {
            this.salespersonAgent = {
                agent,
                session,
                isConnected: false,
            };
        },
        
        setCoachAgent(agent: RealtimeAgent, session: RealtimeSession) {
            this.coachAgent = {
                agent,
                session,
                isConnected: false,
            };
        },
        
        updateAgentConnectionStatus(agentType: 'salesperson' | 'coach', connected: boolean) {
            const agentInfo = agentType === 'salesperson' ? this.salespersonAgent : this.coachAgent;
            if (agentInfo) {
                agentInfo.isConnected = connected;
            }
        },
        
        // Session configuration
        updateSessionConfig(updates: Partial<RealtimeSessionOptions>) {
            Object.assign(this.sessionConfig, updates);
        },
        
        setSessionInstructions(instructions: string) {
            this.sessionConfig.instructions = instructions;
        },
        
        setSessionTools(tools: any[]) {
            this.sessionConfig.tools = tools;
        },
        
        // Cleanup
        disconnectAllAgents() {
            // Access raw objects to avoid Vue proxy issues
            const rawSalespersonSession = this.salespersonAgent?.session;
            const rawCoachSession = this.coachAgent?.session;
            
            if (rawSalespersonSession && typeof rawSalespersonSession.close === 'function') {
                try {
                    rawSalespersonSession.close();
                } catch (error) {
                    console.error('Error closing salesperson session:', error);
                }
            }
            
            if (rawCoachSession && typeof rawCoachSession.close === 'function') {
                try {
                    rawCoachSession.close();
                } catch (error) {
                    console.error('Error closing coach session:', error);
                }
            }
            
            // Update connection status
            if (this.salespersonAgent) {
                this.salespersonAgent.isConnected = false;
            }
            if (this.coachAgent) {
                this.coachAgent.isConnected = false;
            }
        },
        
        clearAgents() {
            this.salespersonAgent = null;
            this.coachAgent = null;
        },
        
        reset() {
            this.disconnectAllAgents();
            this.clearAgents();
            this.clearEphemeralKey();
            // Reset session config to defaults
            this.sessionConfig = {
                model: 'gpt-4o-mini-realtime-preview-2024-12-17',
                instructions: '',
                voice: 'verse',
                input_audio_format: 'pcm16',
                output_audio_format: 'pcm16',
                input_audio_transcription: {
                    model: 'gpt-4o-mini-transcribe',
                },
                turn_detection: null,
                tools: [],
                tool_choice: 'auto',
                temperature: 0.7,
                max_response_output_tokens: 4096,
            };
        },
    },
});