import { defineStore } from 'pinia';
import type { 
    ActionItem, 
    Commitment, 
    CustomerInfo, 
    CustomerIntelligence, 
    Insight, 
    Template, 
    Topic, 
    TranscriptGroup 
} from '@/types/realtimeAgent';
import { mockRealtimeDataService } from '@/services/mockRealtimeData';
import type { CustomerInsight, ContextualCoaching } from '@/types/realtime';

export const useRealtimeAgentStore = defineStore('realtimeAgent', {
    state: () => ({
        // Connection
        connectionStatus: 'disconnected' as 'disconnected' | 'connecting' | 'connected',
        isActive: false,
        isMockMode: false,
        mockInterval: null as NodeJS.Timeout | null,
        
        // Templates
        selectedTemplate: null as Template | null,
        templates: [] as Template[],
        
        // Transcript
        transcriptGroups: [] as TranscriptGroup[],
        
        // Intelligence
        customerIntelligence: {
            intent: 'unknown',
            buyingStage: 'Discovery',
            engagementLevel: 50,
            sentiment: 'neutral',
        } as CustomerIntelligence,
        insights: [] as Insight[],
        topics: [] as Topic[],
        
        // Actions
        commitments: [] as Commitment[],
        actionItems: [] as ActionItem[],
        
        // Coaching
        coachingItems: [] as ContextualCoaching[],
        
        // UI State
        coveredPoints: [] as number[],
        showCustomerModal: false,
        customerInfo: {
            name: '',
            company: '',
        } as CustomerInfo,
        
        // Audio
        audioLevel: 0,
        systemAudioLevel: 0,
        microphoneStatus: 'inactive',
        isSystemAudioActive: false,
        
        // Other
        conversationContext: '',
        lastCustomerMessage: '',
        intelligenceUpdating: false,
    }),
    
    getters: {
        talkingPointsProgress: (state) => {
            if (!state.selectedTemplate?.talking_points?.length) return 0;
            return Math.round((state.coveredPoints.length / state.selectedTemplate.talking_points.length) * 100);
        },
        
        recentInsights: (state) => {
            return state.insights.slice(0, 5);
        },
        
        hasActiveCall: (state) => {
            return state.isActive && state.connectionStatus === 'connected';
        },
        
        sortedTopics: (state) => {
            return [...state.topics].sort((a, b) => b.mentions - a.mentions);
        },
    },
    
    actions: {
        // Connection Actions
        setConnectionStatus(status: 'disconnected' | 'connecting' | 'connected') {
            this.connectionStatus = status;
        },
        
        setActiveState(active: boolean) {
            this.isActive = active;
        },
        
        // Template Actions
        setSelectedTemplate(template: Template | null) {
            this.selectedTemplate = template;
            if (template) {
                this.coveredPoints = [];
            }
        },
        
        setTemplates(templates: Template[]) {
            this.templates = templates;
        },
        
        // Transcript Actions
        addTranscriptGroup(group: TranscriptGroup) {
            this.transcriptGroups.push(group);
        },
        
        appendToLastTranscriptGroup(role: string, text: string, maxTimeGapMs: number = 5000): boolean {
            // Check if we have any transcript groups
            if (this.transcriptGroups.length === 0) {
                return false;
            }
            
            // Get the last transcript group
            const lastGroup = this.transcriptGroups[this.transcriptGroups.length - 1];
            
            // Check if it's the same speaker and within time window
            if (lastGroup.role === role) {
                const now = Date.now();
                const lastMessageTime = lastGroup.messages[lastGroup.messages.length - 1]?.timestamp || lastGroup.startTime;
                
                if (now - lastMessageTime <= maxTimeGapMs) {
                    // Append to existing group
                    lastGroup.messages.push({ text, timestamp: now });
                    lastGroup.endTime = now;
                    return true;
                }
            }
            
            return false;
        },
        
        updateTranscriptGroup(groupId: string, updates: Partial<TranscriptGroup>) {
            const group = this.transcriptGroups.find(g => g.id === groupId);
            if (group) {
                Object.assign(group, updates);
            }
        },
        
        clearTranscript() {
            this.transcriptGroups = [];
        },
        
        // Topic Actions
        trackDiscussionTopic(name: string, sentiment: string, context?: string) {
            const normalizedName = name.trim().toLowerCase();
            const existingTopic = this.topics.find(t => t.name.toLowerCase() === normalizedName);
            
            if (existingTopic) {
                existingTopic.mentions++;
                existingTopic.lastMentioned = Date.now();
                if (sentiment && sentiment !== existingTopic.sentiment) {
                    existingTopic.sentiment = 'mixed';
                }
            } else {
                this.topics.push({
                    id: `topic-${Date.now()}`,
                    name,
                    sentiment: sentiment as any,
                    mentions: 1,
                    lastMentioned: Date.now(),
                    context,
                });
            }
        },
        
        // Customer Intelligence Actions
        updateCustomerIntelligence(updates: Partial<CustomerIntelligence>) {
            this.intelligenceUpdating = true;
            Object.assign(this.customerIntelligence, updates);
            setTimeout(() => {
                this.intelligenceUpdating = false;
            }, 500);
        },
        
        // Insight Actions
        addKeyInsight(type: string, text: string, importance: string) {
            this.insights.unshift({
                id: `insight-${Date.now()}`,
                type: type as any,
                text,
                importance: importance as any,
                timestamp: Date.now(),
            });
            
            // Keep only last 20 insights
            if (this.insights.length > 20) {
                this.insights.pop();
            }
        },
        
        // Commitment Actions
        captureCommitment(speaker: string, text: string, type: string, deadline?: string) {
            this.commitments.push({
                id: `commitment-${Date.now()}`,
                speaker: speaker as any,
                text,
                type: type as any,
                deadline,
                timestamp: Date.now(),
            });
        },
        
        // Action Item Actions
        addActionItem(text: string, owner: string, type: string, deadline?: string, relatedCommitment?: string) {
            this.actionItems.push({
                id: `action-${Date.now()}`,
                text,
                owner: owner as any,
                type: type as any,
                completed: false,
                deadline,
                relatedCommitment,
            });
        },
        
        toggleActionItemComplete(id: string) {
            const item = this.actionItems.find(a => a.id === id);
            if (item) {
                item.completed = !item.completed;
            }
        },
        
        // Talking Points Actions
        toggleTalkingPoint(index: number) {
            const idx = this.coveredPoints.indexOf(index);
            if (idx > -1) {
                this.coveredPoints.splice(idx, 1);
            } else {
                this.coveredPoints.push(index);
            }
        },
        
        // Audio Actions
        setAudioLevel(level: number) {
            this.audioLevel = level;
        },
        
        setSystemAudioLevel(level: number) {
            this.systemAudioLevel = level;
        },
        
        setMicrophoneStatus(status: string) {
            this.microphoneStatus = status;
        },
        
        setSystemAudioActive(active: boolean) {
            this.isSystemAudioActive = active;
        },
        
        // Customer Info Actions
        setCustomerInfo(info: CustomerInfo) {
            this.customerInfo = info;
        },
        
        setShowCustomerModal(show: boolean) {
            this.showCustomerModal = show;
        },
        
        // Context Actions
        setConversationContext(context: string) {
            this.conversationContext = context;
        },
        
        setLastCustomerMessage(message: string) {
            this.lastCustomerMessage = message;
        },
        
        // Coaching Actions
        addCoachingItem(coaching: ContextualCoaching) {
            this.coachingItems.unshift(coaching);
            // Keep only last 10 coaching items
            if (this.coachingItems.length > 10) {
                this.coachingItems.pop();
            }
        },
        
        // Reset Actions
        resetSession() {
            // Keep templates and selected template
            const { templates, selectedTemplate } = this;
            
            // Reset everything else
            this.$reset();
            
            // Restore templates
            this.templates = templates;
            this.selectedTemplate = selectedTemplate;
        },
        
        resetIntelligence() {
            this.customerIntelligence = {
                intent: 'unknown',
                buyingStage: 'Discovery',
                engagementLevel: 50,
                sentiment: 'neutral',
            };
            this.insights = [];
            this.topics = [];
            this.commitments = [];
            this.actionItems = [];
            this.conversationContext = '';
            this.lastCustomerMessage = '';
        },
        
        // Mock Data Actions
        enableMockMode() {
            this.isMockMode = true;
            this.resetSession();
            
            // Load all mock data at once
            const mockData = mockRealtimeDataService.getAllMockData();
            
            // Set connection status
            this.connectionStatus = 'connected';
            this.isActive = true;
            
            // Set customer info
            this.customerInfo = {
                name: mockData.customerProfile.name,
                company: mockData.customerProfile.company,
            };
            
            // Load transcripts
            mockData.transcripts.forEach(transcript => {
                this.addTranscriptGroup(transcript);
            });
            
            // Load insights
            mockData.insights.forEach(insight => {
                this.addKeyInsight(
                    insight.type,
                    insight.content,
                    insight.confidence > 0.9 ? 'high' : insight.confidence > 0.7 ? 'medium' : 'low'
                );
            });
            
            // Update customer intelligence
            this.updateCustomerIntelligence({
                intent: 'evaluation',
                buyingStage: 'Discovery',
                engagementLevel: mockData.metrics.engagementScore,
                sentiment: mockData.metrics.sentiment,
            });
            
            // Add some topics
            this.trackDiscussionTopic('Integration Issues', 'negative', 'Systems don\'t talk to each other');
            this.trackDiscussionTopic('Manual Data Entry', 'negative', 'Spending hours on manual work');
            this.trackDiscussionTopic('Automation Platform', 'positive', 'Interested in our solution');
            this.trackDiscussionTopic('Demo Scheduled', 'positive', 'Thursday 2 PM demo');
            
            // Add action items
            this.addActionItem('Send proposal and demo invite', 'salesperson', 'followup', 'Today');
            this.addActionItem('Include Sarah Chen (CTO) in demo', 'salesperson', 'meeting');
            this.addActionItem('Include Mike Johnson (Operations) in demo', 'salesperson', 'meeting');
            
            // Add commitments
            this.captureCommitment('salesperson', 'Send detailed proposal today', 'deadline', 'Today');
            this.captureCommitment('salesperson', 'Schedule 30-minute demo for Thursday 2 PM', 'meeting', 'Thursday');
            this.captureCommitment('customer', 'Review proposal with team', 'review');
            
            // Add coaching items
            mockData.coaching.forEach(coaching => {
                this.addCoachingItem(coaching);
            });
        },
        
        disableMockMode() {
            this.isMockMode = false;
            if (this.mockInterval) {
                mockRealtimeDataService.stopMockConversation();
                this.mockInterval = null;
            }
            this.resetSession();
        },
        
        startMockSimulation() {
            if (!this.isMockMode) return;
            
            this.resetSession();
            this.connectionStatus = 'connected';
            this.isActive = true;
            
            mockRealtimeDataService.startMockConversation((update) => {
                switch (update.type) {
                    case 'transcript':
                        const group: TranscriptGroup = {
                            id: `group-${Date.now()}`,
                            role: update.data.role,
                            messages: update.data.messages.map((text: string, index: number) => ({
                                id: `msg-${Date.now()}-${index}`,
                                text,
                                timestamp: update.data.timestamp,
                            })),
                            startTime: update.data.timestamp,
                            endTime: update.data.timestamp + 1000,
                        };
                        this.addTranscriptGroup(group);
                        break;
                        
                    case 'insight':
                        const insight = update.data as CustomerInsight;
                        this.addKeyInsight(
                            insight.type,
                            insight.content,
                            insight.confidence > 0.9 ? 'high' : 'medium'
                        );
                        break;
                        
                    case 'metrics':
                        this.updateCustomerIntelligence({
                            engagementLevel: Math.max(0, Math.min(100, update.data.engagementScore)),
                        });
                        break;
                }
            });
        },
    },
});