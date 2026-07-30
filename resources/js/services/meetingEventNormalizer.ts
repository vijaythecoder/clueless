import type { MeetingCaptureStatus, MeetingEvent, MeetingParticipant, RecallTranscriptTurn, SalesRole } from '@/types/meetingCapture';

const captureStatuses = new Set<MeetingCaptureStatus>(['creating', 'joining', 'waiting_room', 'active', 'stopping', 'ended', 'failed']);
const salesRoles = new Set<SalesRole>(['salesperson', 'customer', 'unknown', 'bot']);

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function nonBlankString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function positiveInteger(value: unknown): number | null {
    return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null;
}

function nonnegativeNumber(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
}

function captureStatus(value: unknown): MeetingCaptureStatus | null {
    return typeof value === 'string' && captureStatuses.has(value as MeetingCaptureStatus) ? (value as MeetingCaptureStatus) : null;
}

function salesRole(value: unknown): SalesRole | null {
    return typeof value === 'string' && salesRoles.has(value as SalesRole) ? (value as SalesRole) : null;
}

export function normalizeMeetingParticipant(value: unknown): MeetingParticipant | null {
    if (!isRecord(value)) {
        return null;
    }

    const id = positiveInteger(value.id);
    const providerParticipantId = nonBlankString(value.provider_participant_id);
    const displayName = nonBlankString(value.display_name);
    const role = salesRole(value.sales_role);

    if (
        id === null ||
        providerParticipantId === null ||
        displayName === null ||
        role === null ||
        typeof value.is_bot !== 'boolean' ||
        typeof value.email_present !== 'boolean'
    ) {
        return null;
    }

    const participant: MeetingParticipant = {
        id,
        providerParticipantId,
        displayName,
        isBot: value.is_bot,
        salesRole: role,
        emailPresent: value.email_present,
    };

    if (typeof value.is_host === 'boolean') {
        participant.isHost = value.is_host;
    }

    return participant;
}

function normalizeTurn(value: unknown, status: 'partial' | 'final'): RecallTranscriptTurn | null {
    if (!isRecord(value)) {
        return null;
    }

    const eventId = positiveInteger(value.event_id);
    const utteranceKey = nonBlankString(value.utterance_key);
    const participantId = positiveInteger(value.participant_id);
    const displayName = nonBlankString(value.display_name);
    const role = salesRole(value.sales_role);
    const text = nonBlankString(value.text);
    const startedAt = nonnegativeNumber(value.started_at);
    const providerItemId = nonBlankString(value.provider_item_id);
    const endedAt = value.ended_at === undefined || value.ended_at === null ? undefined : nonnegativeNumber(value.ended_at);

    if (
        eventId === null ||
        utteranceKey === null ||
        participantId === null ||
        displayName === null ||
        role === null ||
        text === null ||
        startedAt === null ||
        (status === 'final' && providerItemId === null) ||
        endedAt === null
    ) {
        return null;
    }

    const turn: RecallTranscriptTurn = {
        eventId,
        utteranceKey,
        participantId,
        displayName,
        salesRole: role,
        text,
        startedAt,
        status,
    };

    if (providerItemId !== null) {
        turn.providerItemId = providerItemId;
    }
    if (endedAt !== undefined) {
        turn.endedAt = endedAt;
    }

    return turn;
}

export function normalizeMeetingEvent(value: unknown, minimumCursor = 0): MeetingEvent | null {
    if (!isRecord(value)) {
        return null;
    }

    const cursor = positiveInteger(value.cursor);
    const type = nonBlankString(value.type);

    if (cursor === null || cursor <= minimumCursor || type === null) {
        return null;
    }

    if (type === 'capture.status') {
        const status = captureStatus(value.status);
        return status === null ? null : { type, cursor, status };
    }

    if (type === 'participant.upsert') {
        const participant = normalizeMeetingParticipant(value.participant);
        return participant === null ? null : { type, cursor, participant };
    }

    if (type === 'transcript.partial' || type === 'transcript.final') {
        const turn = normalizeTurn(value.turn, type === 'transcript.partial' ? 'partial' : 'final');
        return turn === null ? null : { type, cursor, turn };
    }

    if (type === 'capture.error') {
        const code = nonBlankString(value.code);
        const message = nonBlankString(value.message);
        return code === null || message === null ? null : { type, cursor, code, message };
    }

    return null;
}
