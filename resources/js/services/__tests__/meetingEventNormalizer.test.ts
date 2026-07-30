import { normalizeMeetingEvent } from '@/services/meetingEventNormalizer';
import { describe, expect, it } from 'vitest';

describe('normalizeMeetingEvent', () => {
    it('normalizes every supported event without any', () => {
        expect(
            normalizeMeetingEvent({
                type: 'capture.status',
                cursor: 1,
                status: 'active',
            }),
        ).toEqual({
            type: 'capture.status',
            cursor: 1,
            status: 'active',
        });

        expect(
            normalizeMeetingEvent({
                type: 'participant.upsert',
                cursor: 2,
                participant: {
                    id: 8,
                    provider_participant_id: 'teams-8',
                    display_name: 'Ada Lovelace',
                    is_host: true,
                    is_bot: false,
                    sales_role: 'salesperson',
                    email_present: true,
                },
            }),
        ).toEqual({
            type: 'participant.upsert',
            cursor: 2,
            participant: {
                id: 8,
                providerParticipantId: 'teams-8',
                displayName: 'Ada Lovelace',
                isHost: true,
                isBot: false,
                salesRole: 'salesperson',
                emailPresent: true,
            },
        });

        const rawTurn = {
            event_id: 3,
            provider_item_id: 'item-3',
            utterance_key: 'utterance-3',
            participant_id: 8,
            display_name: 'Ada Lovelace',
            sales_role: 'salesperson',
            text: 'The implementation takes two weeks.',
            started_at: 1200,
            ended_at: 2400,
        };

        expect(normalizeMeetingEvent({ type: 'transcript.partial', cursor: 3, turn: rawTurn })).toEqual({
            type: 'transcript.partial',
            cursor: 3,
            turn: {
                eventId: 3,
                providerItemId: 'item-3',
                utteranceKey: 'utterance-3',
                participantId: 8,
                displayName: 'Ada Lovelace',
                salesRole: 'salesperson',
                text: 'The implementation takes two weeks.',
                startedAt: 1200,
                endedAt: 2400,
                status: 'partial',
            },
        });

        expect(normalizeMeetingEvent({ type: 'transcript.final', cursor: 4, turn: rawTurn })).toEqual({
            type: 'transcript.final',
            cursor: 4,
            turn: {
                eventId: 3,
                providerItemId: 'item-3',
                utteranceKey: 'utterance-3',
                participantId: 8,
                displayName: 'Ada Lovelace',
                salesRole: 'salesperson',
                text: 'The implementation takes two weeks.',
                startedAt: 1200,
                endedAt: 2400,
                status: 'final',
            },
        });

        expect(
            normalizeMeetingEvent({
                type: 'capture.error',
                cursor: 5,
                code: 'bot_failed',
                message: 'The meeting bot could not join.',
            }),
        ).toEqual({
            type: 'capture.error',
            cursor: 5,
            code: 'bot_failed',
            message: 'The meeting bot could not join.',
        });
    });

    it('rejects malformed blank and regressing events', () => {
        expect(normalizeMeetingEvent({ type: 'unknown', cursor: 1 })).toBeNull();
        expect(normalizeMeetingEvent({ type: 'capture.status', cursor: 0, status: 'active' })).toBeNull();
        expect(normalizeMeetingEvent({ type: 'capture.status', cursor: 3, status: 'invalid' })).toBeNull();
        expect(normalizeMeetingEvent({ type: 'participant.upsert', cursor: 3, participant: { id: 0 } })).toBeNull();
        expect(
            normalizeMeetingEvent({
                type: 'transcript.partial',
                cursor: 4,
                turn: {
                    event_id: 4,
                    utterance_key: 'u-4',
                    participant_id: 2,
                    display_name: 'Customer',
                    sales_role: 'customer',
                    text: '   ',
                    started_at: 10,
                },
            }),
        ).toBeNull();
        expect(
            normalizeMeetingEvent({
                type: 'transcript.final',
                cursor: 5,
                turn: {
                    event_id: 5,
                    utterance_key: 'u-5',
                    participant_id: 2,
                    display_name: 'Customer',
                    sales_role: 'customer',
                    text: 'Final text',
                    started_at: 10,
                },
            }),
        ).toBeNull();
        expect(normalizeMeetingEvent({ type: 'capture.status', cursor: 7, status: 'active' }, 7)).toBeNull();
        expect(normalizeMeetingEvent(null)).toBeNull();
    });
});
