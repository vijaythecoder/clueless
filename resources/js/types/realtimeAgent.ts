export interface Template {
    id: string;
    name: string;
    prompt: string;
    created_at: string;
    is_system?: boolean;
    icon?: string;
    talking_points?: string[];
    variables?: Record<string, string>;
}

export type SalesRole = 'salesperson' | 'customer' | 'unknown' | 'bot';

export interface AnalysisTurn {
    itemId: string;
    participantId?: number;
    speakerName: string;
    role: 'salesperson' | 'customer';
    transcript: string;
    recentContext: string;
    attempts?: number;
    analysisDeliveryId?: number;
    allowedEvidenceItemIds?: string[];
}

export interface TranscriptGroup {
    id: string;
    role: SalesRole | 'system';
    messages: Array<{ text: string; timestamp: number; itemId?: string; status?: 'partial' | 'final' }>;
    startTime: number;
    endTime?: number;
    systemCategory?: 'error' | 'warning' | 'info' | 'success';
    sourceStream?: 'salesperson' | 'customer' | 'copilot' | 'recall';
    itemId?: string;
    utteranceKey?: string;
    participantId?: number;
    displayName?: string;
    cursor?: number;
}

export function formatTranscriptTimestamp(group: Pick<TranscriptGroup, 'sourceStream' | 'startTime'>): string {
    if (group.sourceStream === 'recall') {
        const totalSeconds = Math.floor(Math.max(0, group.startTime) / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const elapsed = [minutes, seconds].map((part) => part.toString().padStart(2, '0')).join(':');

        return hours > 0 ? `${hours.toString().padStart(2, '0')}:${elapsed}` : elapsed;
    }

    const date = new Date(group.startTime);
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    const seconds = date.getSeconds().toString().padStart(2, '0');

    return `${hours}:${minutes}:${seconds}`;
}

export interface CustomerIntelligence {
    intent: 'research' | 'evaluation' | 'decision' | 'implementation' | 'unknown';
    buyingStage: string;
    engagementLevel: number;
    sentiment: 'positive' | 'negative' | 'neutral';
}

export interface Insight {
    id: string;
    type: 'pain_point' | 'objection' | 'positive_signal' | 'concern' | 'question' | 'knowledge_card' | 'talk_track';
    text: string;
    importance: 'high' | 'medium' | 'low';
    timestamp: number;
    source?: string;
    category?: string;
    evidenceItemIds?: string[];
    toolCallId?: string;
}

export interface KnowledgeCard {
    id: string;
    title: string;
    content: string;
    source?: string;
    confidence?: number;
    timestamp: number;
}

export interface TalkTrack {
    id: string;
    text: string;
    reason?: string;
    priority: 'high' | 'medium' | 'low';
    timestamp: number;
}

export interface Objection {
    id: string;
    text: string;
    category?: string;
    severity: 'high' | 'medium' | 'low';
    timestamp: number;
}

export interface ToolApprovalRequest {
    id: string;
    name: string;
    arguments: Record<string, unknown>;
    serverLabel?: string;
    timestamp: number;
}

export interface Topic {
    id: string;
    name: string;
    sentiment: 'positive' | 'negative' | 'neutral' | 'mixed';
    mentions: number;
    lastMentioned: number;
    context?: string;
    evidenceItemIds?: string[];
    toolCallId?: string;
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
