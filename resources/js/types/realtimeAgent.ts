export interface Template {
    id: string;
    name: string;
    prompt: string;
    created_at: string;
    is_system?: boolean;
    icon?: string;
    talking_points?: string[];
}

export interface TranscriptGroup {
    id: string;
    role: 'salesperson' | 'customer' | 'system';
    messages: Array<{ text: string; timestamp: number }>;
    startTime: number;
    endTime?: number;
    systemCategory?: 'error' | 'warning' | 'info' | 'success';
}

export interface CustomerIntelligence {
    intent: 'research' | 'evaluation' | 'decision' | 'implementation' | 'unknown';
    buyingStage: string;
    engagementLevel: number;
    sentiment: 'positive' | 'negative' | 'neutral';
}

export interface Insight {
    id: string;
    type: 'pain_point' | 'objection' | 'positive_signal' | 'concern' | 'question';
    text: string;
    importance: 'high' | 'medium' | 'low';
    timestamp: number;
}

export interface Topic {
    id: string;
    name: string;
    sentiment: 'positive' | 'negative' | 'neutral' | 'mixed';
    mentions: number;
    lastMentioned: number;
    context?: string;
}

export interface Commitment {
    id: string;
    speaker: 'salesperson' | 'customer';
    text: string;
    type: 'promise' | 'next_step' | 'deliverable';
    deadline?: string;
    timestamp: number;
}

export interface ActionItem {
    id: string;
    text: string;
    owner: 'salesperson' | 'customer' | 'both';
    type: 'follow_up' | 'send_info' | 'schedule' | 'internal';
    completed: boolean;
    deadline?: string;
    relatedCommitment?: string;
}

export interface CustomerInfo {
    name: string;
    company: string;
}