// Realtime Agent Types

export interface TranscriptMessage {
    id: string;
    text: string;
    timestamp: number;
}

export interface TranscriptGroup {
    id: string;
    role: 'salesperson' | 'customer' | 'system';
    messages: TranscriptMessage[];
    startTime: number;
    endTime: number;
}

export interface CustomerInsight {
    id: string;
    type: 'pain_point' | 'objection' | 'positive_signal' | 'buying_signal' | 'concern';
    content: string;
    confidence: number;
    timestamp: number;
}

export interface ContextualCoaching {
    id: string;
    type: 'tip' | 'suggestion' | 'warning' | 'positive';
    content: string;
    priority: 'high' | 'medium' | 'low';
    timestamp: number;
}

export interface CustomerProfile {
    name: string;
    company: string;
    role: string;
    industry: string;
    employeeCount: string;
    annualRevenue: string;
}

export interface PerformanceMetrics {
    talkRatio: number;
    sentiment: string;
    engagementScore: number;
    objectionsHandled: number;
    painPointsIdentified: number;
    nextStepsSecured: boolean;
}