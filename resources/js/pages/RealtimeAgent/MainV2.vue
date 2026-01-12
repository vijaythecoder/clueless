<template>
    <div
        class="bg-dot-pattern flex h-screen flex-col bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100"
        :class="{
            'screen-protection-active': isProtectionEnabled,
            'screen-protection-filter': isProtectionEnabled,
            'overlay-mode-active': isOverlayMode,
        }"
    >

        <!-- Professional Navigation Title Bar -->
        <TitleBar 
            title="Clueless"
            @dashboard-click="handleDashboardClick"
            @toggle-session="toggleSession"
        >
            <template #recording-indicator>
                <RecordingIndicator 
                    :is-recording="isRecording"
                    :duration="recordingDuration"
                    :show-file-size="false"
                />
            </template>
        </TitleBar>

        <!-- Mobile Menu Dropdown -->
        <MobileMenu @dashboard-click="handleDashboardClick" />

        <!-- Main Container with scrollable layout -->
        <div class="flex flex-1 flex-col p-4 pb-6 relative min-h-0">
            <!-- Screen Protection Overlay (content area only) -->
            <div v-if="isProtectionEnabled" class="screen-protection-content-overlay" aria-hidden="true"></div>
            
            <!-- Main Content Area with Responsive Columns -->
            <!-- Mobile/Tablet: Stack all columns vertically -->
            <!-- Desktop: 3 columns side by side -->
            <div class="flex h-full flex-col gap-3 lg:grid lg:grid-cols-3">
                <!-- Column 1: Live Transcription -->
                <LiveTranscription class="min-h-[300px] lg:min-h-0" />

                <!-- Column 2: Real-time Intelligence -->
                <div class="flex flex-col gap-3">
                    <!-- Customer Intelligence Card -->
                    <CustomerIntelligence class="flex-shrink-0" />

                    <!-- Key Insights Card -->
                    <KeyInsights class="min-h-[200px] flex-1 lg:min-h-0" />

                    <!-- Post-Call Actions Card (moved from column 3) -->
                    <PostCallActions class="min-h-[150px] flex-1 lg:min-h-0" />

                    <!-- Talking Points Card -->
                    <TalkingPoints class="flex-shrink-0" />
                </div>

                <!-- Column 3: Contextual & Actions -->
                <div class="flex flex-col gap-3">
                    <!-- Contextual Information Card (50%) -->
                    <ContextualInformation
                        :prompt="selectedTemplate?.prompt || ''"
                        :conversation-context="conversationContext"
                        :last-customer-message="lastCustomerMessage"
                        class="min-h-[250px] flex-[5] lg:min-h-0"
                    />

                    <!-- Commitments Made Card (30%) -->
                    <CommitmentsList class="min-h-[150px] flex-[3] lg:min-h-0" />

                    <!-- Discussion Topics Card (20%) -->
                    <DiscussionTopics class="min-h-[120px] flex-[2] lg:min-h-0" />
                </div>
            </div>
        </div>

        <!-- Onboarding Modal -->
        <OnboardingModal 
            v-model:open="showOnboardingModal"
            @complete="handleOnboardingComplete"
        />
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { 
    RealtimeAgent,
    RealtimeSession,
    type RealtimeAgentConfiguration,
} from '@openai/agents-realtime';
import { tool } from '@openai/agents-core';
import { z } from 'zod';

// Components
import TitleBar from '@/components/RealtimeAgent/Navigation/TitleBar.vue';
import MobileMenu from '@/components/RealtimeAgent/Navigation/MobileMenu.vue';
import LiveTranscription from '@/components/RealtimeAgent/Content/LiveTranscription.vue';
import CustomerIntelligence from '@/components/RealtimeAgent/Content/CustomerIntelligence.vue';
import KeyInsights from '@/components/RealtimeAgent/Content/KeyInsights.vue';
import DiscussionTopics from '@/components/RealtimeAgent/Content/DiscussionTopics.vue';
import TalkingPoints from '@/components/RealtimeAgent/Content/TalkingPoints.vue';
import CommitmentsList from '@/components/RealtimeAgent/Actions/CommitmentsList.vue';
import PostCallActions from '@/components/RealtimeAgent/Actions/PostCallActions.vue';
import ContextualInformation from '@/components/ContextualInformation.vue';
import OnboardingModal from '@/components/RealtimeAgent/OnboardingModal.vue';
import RecordingIndicator from '@/components/RecordingIndicator.vue';

// Utils
import { AudioRecorderService } from '@/services/audioRecorder';


// Stores
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';
import { useSettingsStore } from '@/stores/settings';
import { useOpenAIStore } from '@/stores/openai';

// Composables
import { useOverlayMode } from '@/composables/useOverlayMode';
import { useScreenProtection } from '@/composables/useScreenProtection';

// Store instances
const realtimeStore = useRealtimeAgentStore();
const settingsStore = useSettingsStore();
const openaiStore = useOpenAIStore();

// Onboarding state
const showOnboardingModal = ref(false);
const hasApiKey = ref(false);
const hasMicPermission = ref(false);
const hasScreenPermission = ref(false);

// Composables
const overlayMode = useOverlayMode();
const screenProtection = useScreenProtection();

// Set composable support in settings after they initialize
onMounted(() => {
    // Wait for composables to initialize
    setTimeout(() => {
        settingsStore.setOverlaySupported(overlayMode.isSupported.value);
        settingsStore.setProtectionSupported(screenProtection.isProtectionSupported.value);
        
        // Sync initial overlay mode state
        if (overlayMode.isOverlayMode.value !== settingsStore.isOverlayMode) {
            settingsStore.setOverlayMode(overlayMode.isOverlayMode.value);
        }
    }, 200);
    
    // Listen for overlay mode changes to keep store in sync
    const handleOverlayModeChange = (event: CustomEvent) => {
        if (event.detail && typeof event.detail.enabled === 'boolean') {
            settingsStore.setOverlayMode(event.detail.enabled);
        }
    };
    
    window.addEventListener('overlayModeChanged', handleOverlayModeChange as EventListener);
    
    // Cleanup on unmount
    onUnmounted(() => {
        window.removeEventListener('overlayModeChanged', handleOverlayModeChange as EventListener);
    });
});

// Computed
const selectedTemplate = computed(() => realtimeStore.selectedTemplate);
const conversationContext = computed(() => realtimeStore.conversationContext);
const lastCustomerMessage = computed(() => realtimeStore.lastCustomerMessage);
const isProtectionEnabled = computed(() => screenProtection.isProtectionEnabled.value);
// Use the overlay mode state from the composable which is the source of truth
const isOverlayMode = computed(() => overlayMode.isOverlayMode.value);

// Store SDK objects outside of Vue's reactivity to avoid proxy issues
let salespersonAgent: any = null;
let coachAgent: any = null;
let salespersonSession: any = null;
let coachSession: any = null;

// Audio capture
let audioContext: AudioContext | null = null;
let micStream: MediaStream | null = null;
let systemStream: MediaStream | null = null;
let sessionsReady = false;

// Audio recording
let audioRecorder: AudioRecorderService | null = null;
let recordingDurationInterval: NodeJS.Timeout | null = null;
const isRecordingEnabled = ref(false);
const isRecording = ref(false);
const recordingDuration = ref(0);
const recordingPath = ref('');

// Conversation session tracking
const currentSessionId = ref<number | null>(null);
const isSavingData = ref(false);
const transcriptQueue: Array<{ speaker: string; text: string; timestamp: number; groupId?: string; systemCategory?: string }> = [];
const insightQueue: Array<{ type: string; data: any; timestamp: number }> = [];
let saveInterval: NodeJS.Timeout | null = null;
const callStartTime = ref<Date | null>(null);
const callDurationSeconds = ref(0);
let durationInterval: NodeJS.Timeout | null = null;

// Tool definitions using SDK
const coachingTools = [
    tool({
        name: 'track_discussion_topic',
        description: 'Track or update a discussion topic',
        parameters: z.object({
            name: z.string().describe('Topic name (e.g., "Pricing", "Integration", "Timeline")'),
            sentiment: z.enum(['positive', 'negative', 'neutral', 'mixed']).describe('Sentiment of the discussion'),
            context: z.string().nullable().describe('Brief context about the discussion'),
        }),
        execute: async (args: { name: string; sentiment: string; context?: string | null }) => {
            handleFunctionCall('track_discussion_topic', args);
            return `Tracked topic: ${args.name} with ${args.sentiment} sentiment`;
        },
    }),
    
    tool({
        name: 'detect_commitment',
        description: 'Log a commitment made by either party',
        parameters: z.object({
            speaker: z.enum(['salesperson', 'customer']).describe('Who made the commitment'),
            text: z.string().describe('What was committed'),
            type: z.enum(['promise', 'next_step', 'deliverable']).describe('Type of commitment'),
            deadline: z.string().nullable().describe('When it should be done'),
            context: z.string().nullable().describe('Additional context'),
        }),
        execute: async (args: { speaker: string; text: string; type: string; deadline?: string | null; context?: string | null }) => {
            handleFunctionCall('detect_commitment', args);
            return `Captured ${args.speaker}'s commitment: ${args.text}`;
        },
    }),
    
    tool({
        name: 'analyze_customer_intent',
        description: 'Update customer intelligence analysis',
        parameters: z.object({
            intent: z.enum(['research', 'evaluation', 'decision', 'implementation', 'unknown']).describe('Customer intent'),
            buyingStage: z.string().nullable().describe('Current stage in buying process'),
            sentiment: z.enum(['positive', 'negative', 'neutral']).describe('Overall sentiment'),
            engagementLevel: z.number().min(0).max(100).describe('Engagement level percentage'),
        }),
        execute: async (args: { intent: string; buyingStage?: string | null; sentiment: string; engagementLevel: number }) => {
            handleFunctionCall('analyze_customer_intent', args);
            return `Updated customer intelligence`;
        },
    }),
    
    tool({
        name: 'highlight_insight',
        description: 'Capture a key insight from the conversation',
        parameters: z.object({
            type: z.enum(['pain_point', 'objection', 'positive_signal', 'concern', 'question']).describe('Type of insight'),
            text: z.string().describe('The insight text'),
            importance: z.enum(['high', 'medium', 'low']).describe('Importance level'),
        }),
        execute: async (args: { type: string; text: string; importance: string }) => {
            handleFunctionCall('highlight_insight', args);
            return `Captured ${args.type}: ${args.text}`;
        },
    }),
    
    tool({
        name: 'create_action_item',
        description: 'Create a post-call action item',
        parameters: z.object({
            text: z.string().describe('What needs to be done'),
            owner: z.enum(['salesperson', 'customer', 'both']).describe('Who owns this action'),
            type: z.enum(['follow_up', 'send_info', 'schedule', 'internal']).describe('Type of action'),
            deadline: z.string().nullable().describe('When it should be done'),
            relatedCommitment: z.string().nullable().describe('ID of related commitment if any'),
        }),
        execute: async (args: { text: string; owner: string; type: string; deadline?: string | null; relatedCommitment?: string | null }) => {
            handleFunctionCall('create_action_item', args);
            return `Created action item: ${args.text}`;
        },
    }),
    
    tool({
        name: 'detect_information_need',
        description: 'Detect when customer is asking about or discussing topics that require specific information',
        parameters: z.object({
            topic: z.string().describe('The main topic being discussed (e.g., "pricing", "features", "integration")'),
            context: z.string().describe('The specific context or question from the customer'),
            urgency: z.enum(['high', 'medium', 'low']).describe('How urgently the information is needed'),
        }),
        execute: async (args: { topic: string; context: string; urgency: string }) => {
            handleFunctionCall('detect_information_need', args);
            return `Detected information need about ${args.topic}`;
        },
    }),
];

// Function call handler (same as original)
const handleFunctionCall = (name: string, args: any) => {
    const timestamp = Date.now();
    
    switch (name) {
        case 'track_discussion_topic':
            realtimeStore.trackDiscussionTopic(args.name, args.sentiment, args.context);
            // Queue for saving
            if (currentSessionId.value) {
                insightQueue.push({
                    type: 'topic',
                    data: {
                        name: args.name,
                        sentiment: args.sentiment,
                        context: args.context,
                    },
                    timestamp: timestamp,
                });
            }
            break;
            
        case 'analyze_customer_intent':
            realtimeStore.updateCustomerIntelligence({
                intent: args.intent,
                buyingStage: args.buyingStage || realtimeStore.customerIntelligence.buyingStage,
                sentiment: args.sentiment,
                engagementLevel: args.engagementLevel,
            });
            break;
            
        case 'highlight_insight':
            realtimeStore.addKeyInsight(args.type, args.text, args.importance);
            // Queue for saving
            if (currentSessionId.value) {
                insightQueue.push({
                    type: 'key_insight',
                    data: {
                        type: args.type,
                        text: args.text,
                        importance: args.importance,
                    },
                    timestamp: timestamp,
                });
            }
            break;
            
        case 'detect_commitment':
            realtimeStore.captureCommitment(args.speaker, args.text, args.type, args.deadline);
            // Queue for saving
            if (currentSessionId.value) {
                insightQueue.push({
                    type: 'commitment',
                    data: {
                        speaker: args.speaker,
                        text: args.text,
                        type: args.type,
                        deadline: args.deadline,
                    },
                    timestamp: timestamp,
                });
            }
            break;
            
        case 'create_action_item':
            realtimeStore.addActionItem(args.text, args.owner, args.type, args.deadline, args.relatedCommitment);
            // Queue for saving
            if (currentSessionId.value) {
                insightQueue.push({
                    type: 'action_item',
                    data: {
                        text: args.text,
                        owner: args.owner,
                        type: args.type,
                        deadline: args.deadline,
                        relatedCommitment: args.relatedCommitment,
                    },
                    timestamp: timestamp,
                });
            }
            break;
            
        case 'detect_information_need':
            // Could update conversation context or trigger specific responses
            realtimeStore.setConversationContext(`Customer asking about ${args.topic}: ${args.context}`);
            break;
    }
};

// Helper to add system messages and queue them for saving
const addSystemMessage = (messages: string | string[], systemCategory?: string) => {
    const timestamp = Date.now();
    const groupId = `system-${timestamp}`;
    const messageArray = Array.isArray(messages) ? messages : [messages];
    
    realtimeStore.addTranscriptGroup({
        id: groupId,
        role: 'system',
        messages: messageArray.map(text => ({ text, timestamp })),
        startTime: timestamp,
        systemCategory,
    });
    
    // Queue for database saving if we have a session
    if (currentSessionId.value) {
        messageArray.forEach(text => {
            transcriptQueue.push({
                speaker: 'system',
                text,
                timestamp,
                groupId,
                systemCategory,
            });
        });
    }
};

// Check onboarding requirements
const checkOnboardingRequirements = async () => {
    // Check if onboarding was already completed
    const onboardingCompleted = localStorage.getItem('onboarding_completed') === 'true';
    
    // Check API key
    try {
        const apiResponse = await axios.get('/api/openai/status');
        hasApiKey.value = apiResponse.data.hasApiKey || false;
    } catch (error) {
        console.error('Failed to check API key status:', error);
        hasApiKey.value = false;
    }
    
    // Check permissions if macPermissions API is available
    if ((window as any).macPermissions) {
        try {
            const micResult = await (window as any).macPermissions.checkPermission('microphone');
            hasMicPermission.value = micResult.success && micResult.status === 'authorized';
            
            const screenResult = await (window as any).macPermissions.checkPermission('screen');
            hasScreenPermission.value = screenResult.success && screenResult.status === 'authorized';
        } catch (error) {
            console.error('Failed to check permissions:', error);
        }
    }
    
    // Show onboarding modal if any requirement is missing
    if (!onboardingCompleted || !hasApiKey.value || !hasMicPermission.value) {
        showOnboardingModal.value = true;
    }
};

// Handle onboarding completion
const handleOnboardingComplete = async () => {
    // Re-check requirements
    await checkOnboardingRequirements();
    
    // If everything is good, modal will close automatically
    showOnboardingModal.value = false;
};

// Initialize function
const initialize = async () => {
    try {
        // Check onboarding requirements first
        await checkOnboardingRequirements();
        
        // Fetch templates
        const response = await axios.get('/templates');
        // The response has templates wrapped in a templates property
        const templates = response.data.templates || [];
        realtimeStore.setTemplates(templates);
        
        // Select first template by default
        if (templates.length > 0 && !realtimeStore.selectedTemplate) {
            realtimeStore.setSelectedTemplate(templates[0]);
        }
    } catch (error) {
        console.error('Failed to fetch templates:', error);
        // Set empty array on error to prevent filter issues
        realtimeStore.setTemplates([]);
    }
};

// Session management
const toggleSession = () => {
    if (realtimeStore.isActive) {
        endCall();
    } else {
        startCall();
    }
};


const startCall = async () => {
    // First check if all requirements are met
    await checkOnboardingRequirements();
    
    // If onboarding modal is shown, don't start the call
    if (showOnboardingModal.value) {
        realtimeStore.setConnectionStatus('disconnected');
        return;
    }
    
    let permissions;
    try {
        realtimeStore.setConnectionStatus('connecting');
        
        // Check and request permissions first
        permissions = await checkAndRequestPermissions();
        
        // If microphone permission not granted, stop here
        if (!permissions || !permissions.microphone || !permissions.microphone.granted) {
            realtimeStore.setConnectionStatus('disconnected');
            realtimeStore.addTranscriptGroup({
                id: `system-info-${Date.now()}`,
                role: 'system',
                messages: [{
                    text: 'Please grant microphone permission and try starting the call again.',
                    timestamp: Date.now()
                }],
                startTime: Date.now(),
                systemCategory: 'info',
            });
            return;
        }
        
        // Get ephemeral key
        const { data } = await axios.post('/api/realtime/ephemeral-key');
        
        if (data.status === 'error') {
            throw new Error(data.message || 'Failed to get ephemeral key');
        }
        
        openaiStore.setEphemeralKey(data.ephemeralKey, 60);
        
        // Initialize agents
        await initializeAgents(data.ephemeralKey);
        
        // Start audio capture (pass permissions for conditional system audio)
        await startAudioCapture(permissions.screenCapture?.granted || false);
        
        // Start audio recording if enabled
        if (isRecordingEnabled.value && window.remote) {
            try {
                audioRecorder = new AudioRecorderService({
                    sampleRate: 24000, // Match the audio context sample rate
                    channels: 2,
                    bitDepth: 16
                });
                recordingPath.value = await audioRecorder.start();
                isRecording.value = true;
                recordingDuration.value = 0;
                
                // Start recording duration timer
                recordingDurationInterval = setInterval(() => {
                    if (isRecording.value && audioRecorder) {
                        const status = audioRecorder.getStatus();
                        recordingDuration.value = status.duration;
                    }
                }, 1000);
                
                // Recording started successfully - no need to show in transcript
            } catch (error) {
                console.error('Failed to start recording:', error);
                // Clean up any partial state
                isRecording.value = false;
                recordingPath.value = '';
                audioRecorder = null;
                
                // Determine error type and provide helpful message
                let errorMessage = 'Failed to start recording';
                if (error.message.includes('Invalid recording path')) {
                    errorMessage = 'Invalid recording path - check file permissions';
                } else if (error.message.includes('No space left')) {
                    errorMessage = 'Not enough disk space for recording';
                } else if (error.message.includes('Permission denied')) {
                    errorMessage = 'Permission denied - check app file access';
                } else {
                    errorMessage = `Failed to start recording: ${error.message}`;
                }
                
                realtimeStore.addTranscriptGroup({
                    id: `system-recording-error-${Date.now()}`,
                    role: 'system',
                    messages: [{
                        text: `⚠️ ${errorMessage}`,
                        timestamp: Date.now()
                    }],
                    startTime: Date.now(),
                    systemCategory: 'warning',
                });
                
                // Continue with call even if recording fails
            }
        }
        
        realtimeStore.setActiveState(true);
        realtimeStore.setConnectionStatus('connected');
        
        // Auto-enable screen protection during calls
        screenProtection.enableForCall();
        
        // Start conversation session in database
        await startConversationSession();
        
        // Add initial system message
        addSystemMessage('📞 Call started');
        
    } catch (error) {
        console.error('Failed to start call:', error);
        realtimeStore.setConnectionStatus('disconnected');
        
        // Show user-friendly error message
        realtimeStore.addTranscriptGroup({
            id: `system-error-${Date.now()}`,
            role: 'system',
            messages: [{
                text: `❌ Failed to start call: ${error instanceof Error ? error.message : 'Unknown error'}`,
                timestamp: Date.now()
            }],
            startTime: Date.now(),
            systemCategory: 'error',
        });
    }
};

const endCall = async () => {
    try {
        // Save any remaining data before stopping
        if (currentSessionId.value) {
            await saveQueuedData(true); // Force save
            // Don't end conversation session yet - need to save recording first
        }
        
        // Clear save interval
        if (saveInterval) {
            clearInterval(saveInterval);
            saveInterval = null;
        }
        
        // Clear duration interval
        if (durationInterval) {
            clearInterval(durationInterval);
            durationInterval = null;
        }
        
        // Reset call tracking
        callStartTime.value = null;
        callDurationSeconds.value = 0;
        
        // Stop recording if active
        if (isRecording.value && audioRecorder) {
            let recordingInfo = null;
            try {
                recordingInfo = await audioRecorder.stop();
                isRecording.value = false;
                
                // Update session with recording info if we have a session
                if (currentSessionId.value && recordingInfo) {
                    try {
                        console.log('Updating recording info for session:', currentSessionId.value, recordingInfo);
                        const response = await axios.patch(`/conversations/${currentSessionId.value}/recording`, {
                            has_recording: true,
                            recording_path: recordingInfo.path,
                            recording_duration: recordingInfo.duration,
                            recording_size: recordingInfo.size,
                        });
                        console.log('Recording info updated successfully:', response.data);
                        
                        // Recording saved successfully - no need to show in transcript
                    } catch (error) {
                        console.error('Failed to update session with recording info:', error);
                        // Recording saved but not linked - log silently
                    }
                } else if (recordingInfo) {
                    console.warn('No session ID available to save recording info');
                    // Recording saved but not linked - log silently
                }
            } catch (error) {
                console.error('Failed to stop recording:', error);
                isRecording.value = false;
                
                // Add error message
                realtimeStore.addTranscriptGroup({
                    id: `system-recording-stop-error-${Date.now()}`,
                    role: 'system',
                    messages: [{
                        text: `❌ Failed to stop recording properly: ${error.message}. Recording may be corrupted.`,
                        timestamp: Date.now()
                    }],
                    startTime: Date.now(),
                    systemCategory: 'error',
                });
            } finally {
                // Always clean up resources
                audioRecorder = null;
                
                // Clear recording duration interval
                if (recordingDurationInterval) {
                    clearInterval(recordingDurationInterval);
                    recordingDurationInterval = null;
                }
                recordingDuration.value = 0;
            }
        }
        
        // Stop microphone stream
        if (micStream) {
            micStream.getTracks().forEach(track => track.stop());
            micStream = null;
        }
        
        // Stop system audio stream
        if (systemStream) {
            systemStream.getTracks().forEach(track => track.stop());
            systemStream = null;
        }
        
        // Disable audio loopback if enabled
        if ((window as any).audioLoopback) {
            try {
                await (window as any).audioLoopback.disableLoopbackAudio();
            } catch {
                // Ignore errors on cleanup
            }
        }
        
        // Close audio context
        if (audioContext) {
            await audioContext.close();
            audioContext = null;
        }
        
        // Disconnect sessions directly to avoid Vue proxy issues
        if (salespersonSession) {
            try {
                salespersonSession.close();
            } catch (error) {
                console.error('Error closing salesperson session:', error);
            }
            salespersonSession = null;
        }
        
        if (coachSession) {
            try {
                coachSession.close();
            } catch (error) {
                console.error('Error closing coach session:', error);
            }
            coachSession = null;
        }
        
        // Clear agents
        salespersonAgent = null;
        coachAgent = null;
        
        // Reset sessions ready flag
        sessionsReady = false;
        
        // Clear from store
        openaiStore.clearAgents();
        
        // End conversation session after recording has been saved
        if (currentSessionId.value) {
            await endConversationSession();
        }
        
        realtimeStore.setActiveState(false);
        realtimeStore.setConnectionStatus('disconnected');
        
        // Add end message
        addSystemMessage('Call ended.');
        
    } catch (error) {
        console.error('Failed to end call:', error);
    }
};

const initializeAgents = async (apiKey: string) => {
    // Create salesperson agent (transcription only)
    const salespersonConfig: RealtimeAgentConfiguration = {
        name: 'Salesperson',
        description: 'Transcribes the salesperson audio',
        instructions: 'You are a transcription assistant. Your only job is to transcribe what the salesperson says.',
        tools: [],
    };
    
    salespersonAgent = new RealtimeAgent(salespersonConfig);
    salespersonSession = new RealtimeSession(salespersonAgent, {
        transport: 'websocket',
        model: 'gpt-4o-mini-realtime-preview-2024-12-17',
        apiKey: apiKey,
    });
    
    // Create coach agent with tools
    const coachConfig: RealtimeAgentConfiguration = {
        name: 'Customer Coach',
        description: 'Analyzes conversations and provides real-time coaching',
        instructions: selectedTemplate.value?.prompt || 'You are a helpful sales coach.',
        tools: coachingTools,
    };
    
    coachAgent = new RealtimeAgent(coachConfig);
    coachSession = new RealtimeSession(coachAgent, {
        transport: 'websocket',
        model: 'gpt-4o-mini-realtime-preview-2024-12-17',
        apiKey: apiKey,
    });
    
    // Set up event handlers
    setupSessionHandlers();
    
    // Connect sessions
    await salespersonSession.connect({});
    await coachSession.connect({});
    
    // Configure sessions after connection
    // Configure salesperson session for transcription
    if (salespersonSession?.transport) {
        salespersonSession.transport.sendEvent({
            type: 'session.update',
            session: {
                modalities: ['audio', 'text'],
                instructions: 'You are a transcription assistant. Your only job is to transcribe what you hear.',
                input_audio_format: 'pcm16',
                output_audio_format: 'pcm16',
                input_audio_transcription: {
                    model: 'whisper-1',
                },
                turn_detection: {
                    type: 'server_vad',
                    threshold: 0.5,
                    prefix_padding_ms: 300,
                    silence_duration_ms: 200,
                },
                voice: 'alloy',
                temperature: 0.8,
                max_response_output_tokens: 4096,
            },
        });
    }
    
    // Configure coach session with tools
    if (coachSession?.transport) {
        const basePrompt = selectedTemplate.value?.prompt || 'You are a helpful sales coach.';
        const coachInstructions = `${basePrompt}

CRITICAL: You must actively and continuously analyze EVERY piece of conversation in real-time:

1. IMMEDIATELY track discussion topics as they are mentioned using track_discussion_topic
2. INSTANTLY detect any commitments using detect_commitment - even tentative ones
3. CONTINUOUSLY highlight insights using highlight_insight for any important points
4. ALWAYS analyze customer intent with analyze_customer_intent after each customer statement
5. PROACTIVELY detect information needs using detect_information_need

Be EXTREMELY aggressive in calling these functions - it's better to over-analyze than miss something.
Call multiple functions per statement if relevant. Don't wait for complete thoughts - analyze partial information.`;
        
        // Convert tools to the format expected by OpenAI
        const toolDefinitions = coachingTools.map(tool => ({
            type: 'function',
            name: tool.name,
            description: tool.description,
            parameters: tool.parameters,
        }));
        
        coachSession.transport.sendEvent({
            type: 'session.update',
            session: {
                modalities: ['audio', 'text'],
                instructions: coachInstructions,
                input_audio_format: 'pcm16',
                output_audio_format: 'pcm16',
                input_audio_transcription: {
                    model: 'whisper-1',
                },
                turn_detection: {
                    type: 'server_vad',
                    threshold: 0.3,              // More sensitive to speech
                    prefix_padding_ms: 200,      // Less padding before speech
                    silence_duration_ms: 100,    // Shorter silence to trigger processing
                },
                voice: 'alloy',
                temperature: 0.8,
                max_response_output_tokens: 4096,
                tools: toolDefinitions,
                tool_choice: 'auto',
            },
        });
    }
    
    // Mark sessions as ready
    sessionsReady = true;
    
    // Store in OpenAI store
    openaiStore.setSalespersonAgent(salespersonAgent, salespersonSession);
    openaiStore.setCoachAgent(coachAgent, coachSession);
};

const setupSessionHandlers = () => {
    if (!salespersonSession || !coachSession) return;
    
    
    // Listen for all events on transport layer for debugging
    if (salespersonSession.transport) {
        salespersonSession.transport.on('*', () => {
        });
    }
    
    if (coachSession.transport) {
        coachSession.transport.on('*', () => {
        });
    }
    
    // Salesperson session handlers
    salespersonSession.on('conversation.updated', () => {
        // Note: Transcript handling moved to conversation.item.input_audio_transcription.completed
    });
    
    // Coach session handlers
    coachSession.on('conversation.updated', () => {
        // Note: Transcript handling moved to conversation.item.input_audio_transcription.completed
    });
    
    // Function call handlers for coach analytics
    coachSession.on('response.function_call_arguments.done', (event: any) => {
        if (event.name && event.arguments) {
            try {
                const args = JSON.parse(event.arguments);
                handleFunctionCall(event.name, args);
            } catch (e) {
                console.error('❌ Failed to parse function arguments:', e);
            }
        }
    });
    
    coachSession.on('response.done', () => {
    });
    
    // Handle transcription events - these are the primary events for capturing audio transcripts
    if (salespersonSession.transport) {
        salespersonSession.transport.on('conversation.item.input_audio_transcription.completed', (event: any) => {
            if (event.transcript) {
                const timestamp = Date.now();
                const groupId = `salesperson-${timestamp}`;
                
                // Try to append to last group if same speaker
                const appended = realtimeStore.appendToLastTranscriptGroup('salesperson', event.transcript);
                
                if (!appended) {
                    // Create new group if not appended
                    realtimeStore.addTranscriptGroup({
                        id: groupId,
                        role: 'salesperson',
                        messages: [{ text: event.transcript, timestamp: timestamp }],
                        startTime: timestamp,
                    });
                }
                
                // Forward to coach for analysis
                if (coachSession && coachSession.transport) {
                    coachSession.transport.sendEvent({
                        type: 'conversation.item.create',
                        item: {
                            type: 'message',
                            role: 'user',
                            content: [{
                                type: 'input_text',
                                text: `Salesperson said: "${event.transcript}"`
                            }]
                        }
                    });
                }
            }
        });
    }
    
    if (coachSession.transport) {
        coachSession.transport.on('conversation.item.input_audio_transcription.completed', (event: any) => {
            if (event.transcript) {
                const timestamp = Date.now();
                const groupId = `customer-${timestamp}`;
                
                // Try to append to last group if same speaker
                const appended = realtimeStore.appendToLastTranscriptGroup('customer', event.transcript);
                
                if (!appended) {
                    // Create new group if not appended
                    realtimeStore.addTranscriptGroup({
                        id: groupId,
                        role: 'customer',
                        messages: [{ text: event.transcript, timestamp: timestamp }],
                        startTime: timestamp,
                    });
                }
                
                // Update last customer message and context
                realtimeStore.setLastCustomerMessage(event.transcript);
                
                // Update conversation context with recent messages
                const recentTranscripts = realtimeStore.transcriptGroups
                    .slice(-5)
                    .map(g => `${g.role}: ${g.messages.map(m => m.text).join(' ')}`)
                    .join('\n');
                realtimeStore.setConversationContext(recentTranscripts);
            }
        });
    }
    
    
    // Error handlers
    salespersonSession.on('error', (error: any) => {
        console.error('Salesperson session error:', error);
        realtimeStore.addTranscriptGroup({
            id: `error-${Date.now()}`,
            role: 'system',
            messages: [{ text: `Salesperson connection error: ${error.message || JSON.stringify(error)}`, timestamp: Date.now() }],
            startTime: Date.now(),
            systemCategory: 'error',
        });
    });
    
    coachSession.on('error', (error: any) => {
        console.error('Coach session error:', error);
        realtimeStore.addTranscriptGroup({
            id: `error-${Date.now()}`,
            role: 'system',
            messages: [{ text: `Coach connection error: ${error.message || JSON.stringify(error)}`, timestamp: Date.now() }],
            startTime: Date.now(),
            systemCategory: 'error',
        });
    });
};

// Permission checking functions (using the same approach as Onboarding.vue)
const checkMicrophonePermission = async () => {
    try {
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.checkPermission('microphone');
            if (result.success) {
                return result.status === 'authorized';
            }
        }
        return false;
    } catch (error) {
        console.error('Error checking microphone permission:', error);
        return false;
    }
};

const requestMicrophonePermission = async () => {
    try {
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.requestPermission('microphone');
            if (result.success) {
                return result.status === 'authorized';
            }
        }
        return false;
    } catch (error) {
        console.error('Error requesting microphone permission:', error);
        return false;
    }
};

const checkScreenCapturePermission = async () => {
    try {
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.checkPermission('screen');
            if (result.success) {
                return result.status === 'authorized';
            }
        }
        return false;
    } catch (error) {
        console.error('Error checking screen capture permission:', error);
        return false;
    }
};

const requestScreenCapturePermission = async () => {
    try {
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.requestPermission('screen');
            if (result.success) {
                return result.status === 'authorized';
            }
        }
        return false;
    } catch (error) {
        console.error('Error requesting screen capture permission:', error);
        return false;
    }
};

const checkAndRequestPermissions = async () => {
    try {
        
        
        // Check if macPermissions API is available
        if (!(window as any).macPermissions) {
            return { 
                microphone: { granted: false }, 
                screenCapture: { granted: false }, 
                allGranted: false 
            };
        }
        
        // Check microphone permission first
        let microphoneGranted = false;
        try {
            microphoneGranted = await checkMicrophonePermission();
        } catch (error) {
            console.error('Error checking microphone permission:', error);
        }
        
        if (!microphoneGranted) {
            // Show friendly message about microphone permission
            realtimeStore.addTranscriptGroup({
                id: `system-info-${Date.now()}`,
                role: 'system',
                messages: [
                    { text: '🎤 Microphone access is required for this call.', timestamp: Date.now() },
                    { text: 'Please grant microphone permission when prompted.', timestamp: Date.now() }
                ],
                startTime: Date.now(),
                systemCategory: 'info',
            });
            
            // Request microphone permission
            try {
                microphoneGranted = await requestMicrophonePermission();
            } catch (error) {
                console.error('Error requesting microphone permission:', error);
            }
            
            if (!microphoneGranted) {
                // Show error message if still not granted
                realtimeStore.addTranscriptGroup({
                    id: `system-error-${Date.now()}`,
                    role: 'system',
                    messages: [{
                        text: '❌ Microphone permission denied. Please enable it in System Preferences > Privacy & Security > Microphone',
                        timestamp: Date.now()
                    }],
                    startTime: Date.now(),
                    systemCategory: 'error',
                });
                
                return { 
                    microphone: { granted: false }, 
                    screenCapture: { granted: false }, 
                    allGranted: false 
                };
            }
        }
        
        // Check screen capture permission
        let screenGranted = false;
        try {
            screenGranted = await checkScreenCapturePermission();
        } catch (error) {
            console.error('Error checking screen capture permission:', error);
        }
        
        if (!screenGranted) {
            // Show informative message for screen capture
            realtimeStore.addTranscriptGroup({
                id: `system-info-${Date.now()}`,
                role: 'system',
                messages: [
                    { text: '🖥️ Screen recording permission is optional but recommended.', timestamp: Date.now() },
                    { text: 'It allows capturing system audio from other applications.', timestamp: Date.now() }
                ],
                startTime: Date.now(),
                systemCategory: 'info',
            });
            
            // Request screen capture permission
            try {
                screenGranted = await requestScreenCapturePermission();
            } catch (error) {
                console.error('Error requesting screen capture permission:', error);
            }
        }
        
        
        return {
            microphone: { granted: microphoneGranted },
            screenCapture: { granted: screenGranted },
            allGranted: microphoneGranted && screenGranted
        };
    } catch (error) {
        console.error('Error checking permissions:', error);
        
        // Show a friendly error message
        realtimeStore.addTranscriptGroup({
            id: `system-error-${Date.now()}`,
            role: 'system',
            messages: [{
                text: '⚠️ Unable to check permissions. You may need to grant permissions manually in System Preferences.',
                timestamp: Date.now()
            }],
            startTime: Date.now(),
            systemCategory: 'error',
        });
        
        // Don't throw - return default permissions
        return { 
            microphone: { granted: false }, 
            screenCapture: { granted: false }, 
            allGranted: false 
        };
    }
};

const startAudioCapture = async (hasScreenCapturePermission: boolean = false) => {
    try {
        // Permissions are already checked in startCall, no need to check again
        
        // Setup microphone capture for salesperson's voice
        micStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                sampleRate: 24000,
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
        realtimeStore.setMicrophoneStatus('active');
        
        // Create audio context with 24kHz sample rate (required by OpenAI)
        audioContext = new AudioContext({ sampleRate: 24000 });
        
        // Resume audio context if it's suspended
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }
        
        // Setup microphone processor
        const micSource = audioContext.createMediaStreamSource(micStream);
        const micProcessor = audioContext.createScriptProcessor(4096, 1, 1);
        
        micProcessor.onaudioprocess = (event) => {
            if (realtimeStore.isActive && sessionsReady) {
                const inputData = event.inputBuffer.getChannelData(0);
                
                // Update audio level for visualization
                const sum = inputData.reduce((acc, val) => acc + Math.abs(val), 0);
                realtimeStore.setAudioLevel(Math.min(100, (sum / inputData.length) * 500));
                
                // Convert to PCM16 and send audio
                const pcm16 = convertFloat32ToPCM16(inputData);
                
                // Record audio if enabled (left channel - salesperson)
                if (isRecording.value && audioRecorder) {
                    audioRecorder.appendAudio(pcm16, 'left');
                }
                
                // Send to salesperson session
                if (salespersonSession && salespersonSession.transport) {
                    const base64Audio = arrayBufferToBase64(pcm16.buffer);
                    try {
                        salespersonSession.transport.sendEvent({
                            type: 'input_audio_buffer.append',
                            audio: base64Audio,
                        });
                        
                        // Log occasionally for debugging
                    } catch (error) {
                        console.error('Failed to send audio to salesperson:', error);
                    }
                }
            }
        };
        
        micSource.connect(micProcessor);
        micProcessor.connect(audioContext.destination);
        
        // Try to setup system audio capture using electron-audio-loopback
        // Only attempt if screen capture permission was granted
        if (hasScreenCapturePermission) {
            try {
                // Check if audio loopback is available
                if (!(window as any).audioLoopback) {
                    throw new Error('audioLoopback API not available');
                }
                
                // Get system audio stream using the helper function
                systemStream = await getLoopbackAudioMediaStream();
                
                // Verify we have audio tracks
                const audioTracks = systemStream.getAudioTracks();
                if (audioTracks.length === 0) {
                    throw new Error('No audio tracks found in loopback stream');
                }
                
                // Create audio processor for system audio
                const systemSource = audioContext.createMediaStreamSource(systemStream);
                const systemProcessor = audioContext.createScriptProcessor(4096, 1, 1);
                
                systemProcessor.onaudioprocess = (event) => {
                    if (realtimeStore.isActive && sessionsReady) {
                        const inputData = event.inputBuffer.getChannelData(0);
                        
                        // Update system audio level for visualization
                        const sum = inputData.reduce((acc, val) => acc + Math.abs(val), 0);
                        realtimeStore.setSystemAudioLevel(Math.min(100, (sum / inputData.length) * 500));
                        
                        // Convert to PCM16 and send audio
                        const pcm16 = convertFloat32ToPCM16(inputData);
                        
                        // Record audio if enabled (right channel - customer)
                        if (isRecording.value && audioRecorder) {
                            audioRecorder.appendAudio(pcm16, 'right');
                        }
                        
                        // Send to coach session
                        if (coachSession && coachSession.transport) {
                            const base64Audio = arrayBufferToBase64(pcm16.buffer);
                            try {
                                coachSession.transport.sendEvent({
                                    type: 'input_audio_buffer.append',
                                    audio: base64Audio,
                                });
                                
                            } catch (error) {
                                console.error('Failed to send audio to coach:', error);
                            }
                        }
                    }
                };
                
                systemSource.connect(systemProcessor);
                systemProcessor.connect(audioContext.destination);
                
                // Set system audio as active
                realtimeStore.setSystemAudioActive(true);
                
                // Handle stream end
                systemStream.getTracks().forEach(track => {
                    track.addEventListener('ended', () => {
                        realtimeStore.setSystemAudioActive(false);
                    });
                });
                
                    
            } catch (error) {
                realtimeStore.setSystemAudioActive(false);
                
                // Check if user cancelled screen share
                if (error instanceof Error && error.name === 'NotAllowedError') {
                    realtimeStore.addTranscriptGroup({
                        id: `system-${Date.now()}`,
                        role: 'system',
                        messages: [
                            { text: '⚠️ Screen share cancelled. System audio not captured.', timestamp: Date.now() },
                            { text: '• Using microphone only mode', timestamp: Date.now() },
                            { text: '• To enable system audio, restart the call and share your screen', timestamp: Date.now() },
                        ],
                        startTime: Date.now(),
                        systemCategory: 'warning',
                    });
                } else {
                    // Other errors - fallback to mixed audio mode
                    realtimeStore.addTranscriptGroup({
                        id: `system-${Date.now()}`,
                        role: 'system',
                        messages: [
                            { text: '⚠️ System audio capture unavailable. Using mixed audio mode.', timestamp: Date.now() },
                            { text: '• Use headphones to reduce echo', timestamp: Date.now() },
                            { text: '• AI will identify speakers based on conversation context', timestamp: Date.now() },
                        ],
                        startTime: Date.now(),
                        systemCategory: 'warning',
                    });
                }
            }
        } else {
            // No screen capture permission - inform user
            realtimeStore.addTranscriptGroup({
                id: `system-info-${Date.now()}`,
                role: 'system',
                messages: [
                    { text: 'ℹ️ Using microphone-only mode', timestamp: Date.now() },
                    { text: '• System audio capture requires screen recording permission', timestamp: Date.now() },
                    { text: '• Grant permission in System Preferences to enable', timestamp: Date.now() },
                ],
                startTime: Date.now(),
                systemCategory: 'info',
            });
            realtimeStore.setSystemAudioActive(false);
        }
        
    } catch (error) {
        console.error('❌ Failed to setup audio:', error);
        realtimeStore.setMicrophoneStatus('error');
        throw error;
    }
};

// Helper function to get loopback audio stream (following electron-audio-loopback docs)
const getLoopbackAudioMediaStream = async () => {
    try {
        // Tell the main process to enable system audio loopback.
        // This will override the default getDisplayMedia behavior.
        await (window as any).audioLoopback.enableLoopbackAudio();

        // Get a MediaStream with system audio loopback.
        // getDisplayMedia will fail if you don't request video: true.
        const stream = await navigator.mediaDevices.getDisplayMedia({ 
            video: true,
            audio: true,
        });
        
        // Remove video tracks that we don't need.
        // Note: You may find bugs if you don't remove video tracks.
        const videoTracks = stream.getVideoTracks();

        videoTracks.forEach(track => {
            track.stop();
            stream.removeTrack(track);
        });

        // Tell the main process to disable system audio loopback.
        // This will restore full getDisplayMedia functionality.
        await (window as any).audioLoopback.disableLoopbackAudio();
        
        // Boom! You've got a MediaStream with system audio loopback.
        return stream;
    } catch (error) {
        // Make sure to disable loopback on error
        try {
            await (window as any).audioLoopback.disableLoopbackAudio();
        } catch {
            // Ignore cleanup errors
        }
        throw error;
    }
};

// Helper function to convert Float32Array to Int16Array
const convertFloat32ToPCM16 = (float32Array: Float32Array): Int16Array => {
    const pcm16 = new Int16Array(float32Array.length);
    for (let i = 0; i < float32Array.length; i++) {
        const clamped = Math.max(-1, Math.min(1, float32Array[i]));
        pcm16[i] = Math.floor(clamped * 32767);
    }
    return pcm16;
};

// Helper function to convert ArrayBuffer to base64
const arrayBufferToBase64 = (buffer: ArrayBuffer): string => {
    const uint8Array = new Uint8Array(buffer);
    let base64Audio = '';
    const chunkSize = 8192; // Process in 8KB chunks
    
    for (let i = 0; i < uint8Array.length; i += chunkSize) {
        const chunk = uint8Array.slice(i, Math.min(i + chunkSize, uint8Array.length));
        base64Audio += btoa(String.fromCharCode(...chunk));
    }
    
    return base64Audio;
};

// Helper function to format file size
const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};


const handleDashboardClick = () => {
    if (!realtimeStore.isActive) {
        router.visit('/conversations');
    }
};

// Watch for template changes
watch(selectedTemplate, (newTemplate) => {
    if (newTemplate && coachSession?.transport) {
        // Update coach instructions via transport
        coachSession.transport.sendEvent({
            type: 'session.update',
            session: {
                instructions: newTemplate.prompt,
            },
        });
    }
});

// Conversation session management functions
const startConversationSession = async () => {
    try {
        const response = await axios.post('/conversations', {
            template_used: selectedTemplate.value?.name || null,
            customer_name: realtimeStore.customerInfo.name || null,
            customer_company: realtimeStore.customerInfo.company || null,
        });

        console.log('Started conversation session:', response.data);
        currentSessionId.value = response.data.session_id;
        callStartTime.value = new Date();
        console.log('Session ID set to:', currentSessionId.value);

        // Start periodic saving
        saveInterval = setInterval(() => {
            saveQueuedData();
        }, 5000); // Save every 5 seconds

        // Start duration timer
        durationInterval = setInterval(() => {
            if (realtimeStore.isActive) {
                callDurationSeconds.value++;
            }
        }, 1000);

    } catch (error) {
        console.error('Failed to start conversation session:', error);
    }
};

const endConversationSession = async () => {
    if (!currentSessionId.value) return;

    try {
        // Save final state
        await axios.post(`/conversations/${currentSessionId.value}/end`, {
            duration_seconds: callDurationSeconds.value,
            final_intent: realtimeStore.customerIntelligence.intent,
            final_buying_stage: realtimeStore.customerIntelligence.buyingStage,
            final_engagement_level: realtimeStore.customerIntelligence.engagementLevel,
            final_sentiment: realtimeStore.customerIntelligence.sentiment,
            ai_summary: null, // Could generate a summary here if needed
        });

        currentSessionId.value = null;
    } catch (error) {
        console.error('Failed to end conversation session:', error);
    }
};

const saveQueuedData = async (force: boolean = false) => {
    if (!currentSessionId.value || isSavingData.value) return;

    // Only save if we have data or forced
    if (!force && transcriptQueue.length === 0 && insightQueue.length === 0) return;

    isSavingData.value = true;

    try {
        // Save transcripts
        if (transcriptQueue.length > 0) {
            const transcriptsToSave = [...transcriptQueue];
            transcriptQueue.length = 0; // Clear queue

            await axios.post(`/conversations/${currentSessionId.value}/transcripts`, {
                transcripts: transcriptsToSave.map((t) => ({
                    speaker: t.speaker,
                    text: t.text,
                    spoken_at: t.timestamp,
                    group_id: t.groupId || null,
                    system_category: t.systemCategory || null,
                })),
            });
        }

        // Save insights
        if (insightQueue.length > 0) {
            const insightsToSave = [...insightQueue];
            insightQueue.length = 0; // Clear queue

            await axios.post(`/conversations/${currentSessionId.value}/insights`, {
                insights: insightsToSave.map((i) => ({
                    insight_type: i.type,
                    data: i.data,
                    captured_at: i.timestamp,
                })),
            });
        }
    } catch (error) {
        console.error('Failed to save queued data:', error);
        // Consider re-adding failed items back to queue
    } finally {
        isSavingData.value = false;
    }
};

// Developer console methods
const enableMockMode = () => {
    realtimeStore.enableMockMode();
    return 'Mock mode enabled';
};

const disableMockMode = () => {
    realtimeStore.disableMockMode();
    return 'Mock mode disabled';
};

// Expose to window for console access
if (typeof window !== 'undefined') {
    (window as any).clueless = {
        enableMockMode,
        disableMockMode,
    };
}

// Watch for new transcript groups and queue them for saving
watch(() => realtimeStore.transcriptGroups, (newGroups, oldGroups) => {
    // Only process if we have a session and groups were added (not removed)
    if (!currentSessionId.value || !oldGroups) return;
    
    // If new groups were added
    if (newGroups.length > oldGroups.length) {
        // Process only the new groups
        const newGroupsAdded = newGroups.slice(oldGroups.length);
        
        newGroupsAdded.forEach(group => {
            // Queue all messages from this group
            group.messages.forEach(message => {
                transcriptQueue.push({
                    speaker: group.role,
                    text: message.text,
                    timestamp: message.timestamp,
                    groupId: group.id,
                    systemCategory: group.systemCategory,
                });
            });
        });
    }
}, { deep: true });

// Watch for changes to existing transcript groups (appended messages)
watch(() => realtimeStore.transcriptGroups.map(g => g.messages.length), (newLengths, oldLengths) => {
    if (!currentSessionId.value || !oldLengths) return;
    
    // Check each group for new messages
    newLengths.forEach((newLength, index) => {
        const oldLength = oldLengths[index] || 0;
        if (newLength > oldLength) {
            const group = realtimeStore.transcriptGroups[index];
            // Queue only the new messages
            const newMessages = group.messages.slice(oldLength);
            
            newMessages.forEach(message => {
                transcriptQueue.push({
                    speaker: group.role,
                    text: message.text,
                    timestamp: message.timestamp,
                    groupId: group.id,
                    systemCategory: group.systemCategory,
                });
            });
        }
    });
});

// Lifecycle
onMounted(() => {
    initialize();
    
    // Load recording settings
    const recordingSettings = localStorage.getItem('recordingSettings');
    if (recordingSettings) {
        const settings = JSON.parse(recordingSettings);
        isRecordingEnabled.value = settings.enabled ?? false;
    }
    
    // Close dropdowns on outside click
    document.addEventListener('click', () => {
        settingsStore.closeAllDropdowns();
    });
});

onUnmounted(async () => {
    if (realtimeStore.isActive) {
        await endCall();
    }
    
    // Clean up
    openaiStore.reset();
    document.removeEventListener('click', settingsStore.closeAllDropdowns);
});
</script>

<style>
/* Apple-style Glassmorphism */
.glass-card {
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
}

.glass-card-darker {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.2);
}

/* Clean, minimal transitions */
.glass-card {
    transition: all 0.3s ease;
}

.glass-card:hover {
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.25);
    transform: translateY(-1px);
}

/* Dark mode glass cards */
:is(.dark *) .glass-card {
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
}

:is(.dark *) .glass-card-darker {
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.05);
    box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.5);
}

/* Custom scrollbar styles for better visibility */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.1);
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(139, 92, 246, 0.3);
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(139, 92, 246, 0.5);
}

/* Dark mode scrollbar */
:is(.dark *) .custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
}

:is(.dark *) .custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(139, 92, 246, 0.5);
}

:is(.dark *) .custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(139, 92, 246, 0.7);
}

/* Subtle animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fadeIn {
    animation: fadeIn 0.2s ease-out;
}
</style>