import type { TranscriptGroup, CustomerInsight, ContextualCoaching } from '@/types/realtime';

// Mock conversation data
const mockConversationFlow = [
    {
        role: 'system',
        messages: ['Call connected'],
        timestamp: Date.now() - 300000, // 5 minutes ago
    },
    {
        role: 'salesperson',
        messages: ["Hi! Thanks for taking my call. I'm calling from TechSolutions. How are you doing today?"],
        timestamp: Date.now() - 295000,
    },
    {
        role: 'customer',
        messages: ["I'm doing well, thanks. What's this about?"],
        timestamp: Date.now() - 290000,
    },
    {
        role: 'salesperson',
        messages: ["I noticed your company has been growing rapidly. We help businesses like yours streamline their operations with our automation platform. Are you currently using any automation tools?"],
        timestamp: Date.now() - 285000,
    },
    {
        role: 'customer',
        messages: ["We use a few different tools, but honestly, they don't talk to each other very well. It's been a pain point for our team."],
        timestamp: Date.now() - 280000,
    },
    {
        role: 'salesperson',
        messages: ["I hear that a lot. Integration issues can really slow things down. What specific challenges are you facing with your current setup?"],
        timestamp: Date.now() - 275000,
    },
    {
        role: 'customer',
        messages: [
            "Well, our sales team uses one CRM, marketing uses a different platform, and our customer service is on another system entirely.",
            "We spend hours every week just moving data between systems manually."
        ],
        timestamp: Date.now() - 270000,
    },
    {
        role: 'salesperson',
        messages: ["That sounds frustrating and time-consuming. Our platform actually specializes in connecting disparate systems. We've helped companies reduce manual data entry by up to 80%."],
        timestamp: Date.now() - 265000,
    },
    {
        role: 'customer',
        messages: ["That's impressive. But we've looked at integration platforms before. They always seem too complex or too expensive for what we need."],
        timestamp: Date.now() - 260000,
    },
    {
        role: 'salesperson',
        messages: ["I understand your concern. What's your typical monthly volume for data transfers between systems?"],
        timestamp: Date.now() - 255000,
    },
    {
        role: 'customer',
        messages: ["Probably around 10,000 records across all systems. But it varies month to month."],
        timestamp: Date.now() - 250000,
    },
    {
        role: 'salesperson',
        messages: ["Based on that volume, you'd fit perfectly into our Growth tier, which is $299/month. That includes all the connectors you'd need plus our visual workflow builder."],
        timestamp: Date.now() - 245000,
    },
    {
        role: 'customer',
        messages: ["How long does implementation typically take? We can't afford a long disruption."],
        timestamp: Date.now() - 240000,
    },
    {
        role: 'salesperson',
        messages: ["Most clients are up and running within 2 weeks. We provide a dedicated onboarding specialist and can start with just one integration to prove the value."],
        timestamp: Date.now() - 235000,
    },
    {
        role: 'customer',
        messages: ["That's better than I expected. Can you send me some more information? I'd need to discuss this with my team."],
        timestamp: Date.now() - 230000,
    },
    {
        role: 'salesperson',
        messages: ["Absolutely! I can send you a detailed proposal today. Would it make sense to schedule a 30-minute demo for you and your team later this week?"],
        timestamp: Date.now() - 225000,
    },
    {
        role: 'customer',
        messages: ["Yes, that would be helpful. How about Thursday afternoon?"],
        timestamp: Date.now() - 220000,
    },
    {
        role: 'salesperson',
        messages: ["Thursday afternoon works great. I have slots at 2 PM or 3:30 PM. Which works better for you?"],
        timestamp: Date.now() - 215000,
    },
    {
        role: 'customer',
        messages: ["Let's do 2 PM. Send me the calendar invite."],
        timestamp: Date.now() - 210000,
    },
    {
        role: 'salesperson',
        messages: ["Perfect! I'll send that right after our call along with the proposal. Just to confirm, I have your email as john.smith@techcorp.com, correct?"],
        timestamp: Date.now() - 205000,
    },
    {
        role: 'customer',
        messages: ["That's correct. Looking forward to the demo."],
        timestamp: Date.now() - 200000,
    },
    {
        role: 'salesperson',
        messages: ["Excellent! One last thing - who else from your team should I include in the demo invite?"],
        timestamp: Date.now() - 195000,
    },
    {
        role: 'customer',
        messages: ["Include Sarah Chen, our CTO, and Mike Johnson from Operations. They'll need to sign off on any new integrations."],
        timestamp: Date.now() - 190000,
    },
    {
        role: 'salesperson',
        messages: ["Got it. I'll include them both. Thanks for your time today, John. I'm excited to show you how we can solve those integration challenges!"],
        timestamp: Date.now() - 185000,
    },
    {
        role: 'customer',
        messages: ["Thanks. Talk to you Thursday."],
        timestamp: Date.now() - 180000,
    },
    {
        role: 'system',
        messages: ['Call ended'],
        timestamp: Date.now() - 175000,
    },
];

// Mock customer insights
const mockInsights: CustomerInsight[] = [
    {
        id: '1',
        type: 'pain_point',
        content: 'Systems don\'t integrate well - manual data transfer between CRM, marketing, and customer service',
        confidence: 0.95,
        timestamp: Date.now() - 270000,
    },
    {
        id: '2',
        type: 'objection',
        content: 'Concerned about complexity and cost based on previous experiences',
        confidence: 0.85,
        timestamp: Date.now() - 260000,
    },
    {
        id: '3',
        type: 'positive_signal',
        content: 'Interested in reducing manual work - spending hours weekly on data entry',
        confidence: 0.9,
        timestamp: Date.now() - 265000,
    },
    {
        id: '4',
        type: 'buying_signal',
        content: 'Agreed to demo and wants to include decision makers (CTO and Operations)',
        confidence: 0.92,
        timestamp: Date.now() - 190000,
    },
    {
        id: '5',
        type: 'concern',
        content: 'Worried about implementation time and business disruption',
        confidence: 0.88,
        timestamp: Date.now() - 240000,
    },
];

// Mock coaching suggestions
const mockCoachingData: ContextualCoaching[] = [
    {
        id: '1',
        type: 'tip',
        content: 'Great job uncovering the pain point! Now quantify the impact.',
        priority: 'medium',
        timestamp: Date.now() - 270000,
    },
    {
        id: '2',
        type: 'suggestion',
        content: 'Ask about their budget range to better qualify the opportunity.',
        priority: 'high',
        timestamp: Date.now() - 250000,
    },
    {
        id: '3',
        type: 'warning',
        content: 'Customer mentioned decision makers - make sure to understand the full buying process.',
        priority: 'medium',
        timestamp: Date.now() - 190000,
    },
    {
        id: '4',
        type: 'positive',
        content: 'Excellent close! You secured a demo with key stakeholders.',
        priority: 'low',
        timestamp: Date.now() - 185000,
    },
];

// Customer profile
const mockCustomerProfile = {
    name: 'John Smith',
    company: 'TechCorp Industries',
    role: 'VP of Technology',
    industry: 'Manufacturing',
    employeeCount: '500-1000',
    annualRevenue: '$50M-$100M',
};

// Performance metrics
const mockMetrics = {
    talkRatio: 45, // Salesperson talked 45% of the time
    sentiment: 'positive',
    engagementScore: 78,
    objectionsHandled: 2,
    painPointsIdentified: 3,
    nextStepsSecured: true,
};

class MockRealtimeDataService {
    private conversationIndex = 0;
    private isActive = false;
    private updateInterval: NodeJS.Timeout | null = null;

    // Get initial state
    getInitialState() {
        return {
            customerProfile: mockCustomerProfile,
            metrics: mockMetrics,
            insights: [],
            coaching: [],
            transcripts: [],
        };
    }

    // Start mock conversation simulation
    startMockConversation(onUpdate: (data: any) => void) {
        this.isActive = true;
        this.conversationIndex = 0;

        // Send initial state
        onUpdate({
            type: 'initial',
            data: this.getInitialState(),
        });

        // Simulate real-time updates
        this.updateInterval = setInterval(() => {
            if (!this.isActive || this.conversationIndex >= mockConversationFlow.length) {
                this.stopMockConversation();
                return;
            }

            const currentMessage = mockConversationFlow[this.conversationIndex];
            
            // Send transcript update
            onUpdate({
                type: 'transcript',
                data: {
                    role: currentMessage.role,
                    messages: currentMessage.messages,
                    timestamp: currentMessage.timestamp,
                },
            });

            // Send related insights
            const relatedInsights = mockInsights.filter(
                insight => Math.abs(insight.timestamp - currentMessage.timestamp) < 10000
            );
            
            relatedInsights.forEach(insight => {
                setTimeout(() => {
                    onUpdate({
                        type: 'insight',
                        data: insight,
                    });
                }, 500);
            });

            // Send related coaching
            const relatedCoaching = mockCoachingData.filter(
                coaching => Math.abs(coaching.timestamp - currentMessage.timestamp) < 10000
            );
            
            relatedCoaching.forEach(coaching => {
                setTimeout(() => {
                    onUpdate({
                        type: 'coaching',
                        data: coaching,
                    });
                }, 1000);
            });

            // Update metrics periodically
            if (this.conversationIndex % 5 === 0) {
                onUpdate({
                    type: 'metrics',
                    data: {
                        ...mockMetrics,
                        engagementScore: mockMetrics.engagementScore + Math.random() * 10 - 5,
                        talkRatio: mockMetrics.talkRatio + Math.random() * 10 - 5,
                    },
                });
            }

            this.conversationIndex++;
        }, 3000); // New message every 3 seconds
    }

    // Stop mock conversation
    stopMockConversation() {
        this.isActive = false;
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
    }

    // Get all mock data at once (for testing UI without simulation)
    getAllMockData() {
        const transcripts: TranscriptGroup[] = mockConversationFlow.map((flow, index) => ({
            id: `group-${index}`,
            role: flow.role as 'salesperson' | 'customer' | 'system',
            messages: flow.messages.map((text, msgIndex) => ({
                id: `msg-${index}-${msgIndex}`,
                text,
                timestamp: flow.timestamp,
            })),
            startTime: flow.timestamp,
            endTime: flow.timestamp + 1000,
        }));

        return {
            customerProfile: mockCustomerProfile,
            metrics: mockMetrics,
            insights: mockInsights,
            coaching: mockCoachingData,
            transcripts,
        };
    }
}

export const mockRealtimeDataService = new MockRealtimeDataService();