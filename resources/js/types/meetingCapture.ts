export type MeetingCaptureStatus = 'creating' | 'joining' | 'waiting_room' | 'active' | 'stopping' | 'ended' | 'failed';

export type CaptureMode = 'local' | 'recall';

export type MeetingProvider = 'recall' | 'local';

export type RecallAnalysisDriver = 'responses' | 'realtime';

export type SalesRole = 'salesperson' | 'customer' | 'unknown' | 'bot';

export interface CaptureFailure {
    code: string;
    message: string;
}

export interface MeetingCapture {
    id: string;
    conversationId: number;
    provider: MeetingProvider;
    status: MeetingCaptureStatus;
    failure?: CaptureFailure;
}

export interface MeetingParticipant {
    id: number;
    providerParticipantId: string;
    displayName: string;
    isHost?: boolean;
    isBot: boolean;
    salesRole: SalesRole;
    emailPresent: boolean;
}

export interface RecallTranscriptTurn {
    eventId: number;
    providerItemId?: string;
    utteranceKey: string;
    participantId: number;
    displayName: string;
    salesRole: SalesRole;
    text: string;
    startedAt: number;
    endedAt?: number;
    status: 'partial' | 'final';
}

export type MeetingEvent =
    | { type: 'capture.status'; cursor: number; status: MeetingCaptureStatus }
    | { type: 'participant.upsert'; cursor: number; participant: MeetingParticipant }
    | { type: 'transcript.partial'; cursor: number; turn: RecallTranscriptTurn }
    | { type: 'transcript.final'; cursor: number; turn: RecallTranscriptTurn }
    | { type: 'capture.error'; cursor: number; code: string; message: string };

export interface MeetingCaptureStartRequest {
    provider: MeetingProvider;
    meetingUrl?: string;
    idempotencyKey: string;
    templateUsed?: string;
    customerName?: string;
    customerCompany?: string;
}

export interface MeetingCaptureResponse {
    id: string;
    conversation_id: number;
    provider: MeetingProvider;
    status: MeetingCaptureStatus;
    failure_code: string | null;
    failure_message: string | null;
}

export interface MeetingCaptureLinksResponse {
    events: string;
    stop: string;
    claim_analysis: string;
    insights?: string;
}

export interface MeetingCaptureStartResponse {
    analysis_driver: RecallAnalysisDriver;
    capture: MeetingCaptureResponse;
    links: MeetingCaptureLinksResponse;
}

export interface MeetingEventPageResponse {
    events: unknown[];
    next_cursor: number;
    has_more: boolean;
    capture: Pick<MeetingCaptureResponse, 'id' | 'status' | 'failure_code' | 'failure_message'>;
}

export interface AnalysisContextTurn {
    providerItemId: string;
    participantId: number;
    displayName: string;
    salesRole: Exclude<SalesRole, 'unknown' | 'bot'>;
    text: string;
    startedAt: number;
    endedAt?: number;
}

export interface AnalysisDelivery extends AnalysisContextTurn {
    id: number;
    leaseToken: string;
    context: AnalysisContextTurn[];
}

export interface AnalysisDeliveryResponse {
    id: number;
    lease_token: string;
    provider_item_id: string;
    participant_id: number;
    display_name: string;
    sales_role: Exclude<SalesRole, 'unknown' | 'bot'>;
    text: string;
    started_at?: number;
    ended_at?: number | null;
    started_offset_ms?: number;
    ended_offset_ms?: number | null;
    context: AnalysisContextTurnResponse[];
}

export interface AnalysisContextTurnResponse {
    provider_item_id: string;
    participant_id: number;
    display_name: string;
    sales_role: Exclude<SalesRole, 'unknown' | 'bot'>;
    text: string;
    started_at?: number;
    ended_at?: number | null;
    started_offset_ms?: number;
    ended_offset_ms?: number | null;
}

export interface AnalysisDeliveryCounts {
    pending: number;
    processing: number;
    completed: number;
    failed: number;
}

export interface AnalysisClaimResponse {
    deliveries: AnalysisDelivery[];
    counts: AnalysisDeliveryCounts;
}

export interface AnalysisClaimApiResponse {
    deliveries: AnalysisDeliveryResponse[];
    counts: AnalysisDeliveryCounts;
}

export interface AnalysisClaimRequest {
    through_cursor: number;
}

export type AnalysisAckStatus = 'completed' | 'failed';

export interface AnalysisAckRequest {
    lease_token: string;
    status: AnalysisAckStatus;
    error_code?: string;
}

export interface AnalysisAckResponse {
    id: number;
    status: AnalysisAckStatus;
}

export interface ServerCopilotUiTool {
    name: string;
    call_id: string;
    arguments: Record<string, unknown>;
    context: {
        analysisDeliveryId?: number;
        evidenceItemIds: string[];
        evidenceItemDeliveryIds: Record<string, number>;
    };
}

export interface MeetingInsightPageResponse {
    ui_tools: ServerCopilotUiTool[];
    next_cursor: number;
    has_more: boolean;
    counts: AnalysisDeliveryCounts;
}

export interface ParticipantAssignmentResponse {
    participants: unknown[];
}
