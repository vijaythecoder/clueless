import liveTranscriptionSource from '@/components/RealtimeAgent/Content/LiveTranscription.vue?raw';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';
import type { MeetingParticipant, RecallTranscriptTurn } from '@/types/meetingCapture';
import { formatTranscriptTimestamp } from '@/types/realtimeAgent';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';

function recallTurn(overrides: Partial<RecallTranscriptTurn> = {}): RecallTranscriptTurn {
    return {
        eventId: 10,
        utteranceKey: 'provider-1:1000',
        participantId: 1,
        displayName: 'Ada',
        salesRole: 'unknown',
        text: 'Hello',
        startedAt: 1000,
        status: 'partial',
        ...overrides,
    };
}

function participant(overrides: Partial<MeetingParticipant> = {}): MeetingParticipant {
    return {
        id: 1,
        providerParticipantId: 'provider-1',
        displayName: 'Ada',
        isBot: false,
        salesRole: 'unknown',
        emailPresent: false,
        ...overrides,
    };
}

describe('realtime agent store', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('keeps existing local partial final behavior', () => {
        const store = useRealtimeAgentStore();

        store.upsertTranscriptDelta('customer', 'item-1', 'What is the', 'customer');
        store.upsertTranscriptDelta('customer', 'item-1', 'What is the price?', 'customer');
        store.finalizeTranscript('customer', 'item-1', 'What is the price?', 'customer');

        expect(store.transcriptGroups).toHaveLength(1);
        expect(store.transcriptGroups[0].messages).toEqual([
            expect.objectContaining({
                text: 'What is the price?',
                itemId: 'item-1',
                status: 'final',
            }),
        ]);
        expect(store.transcriptGroups[0]).toMatchObject({
            role: 'customer',
            sourceStream: 'customer',
            itemId: 'item-1',
        });
    });

    it('upserts Recall partials by utterance and participant', () => {
        const store = useRealtimeAgentStore();

        store.upsertRecallPartial(recallTurn({ text: 'What is' }));
        store.upsertRecallPartial(recallTurn({ eventId: 11, text: 'What is the price?' }));

        expect(store.transcriptGroups).toHaveLength(1);
        expect(store.transcriptGroups[0]).toMatchObject({
            role: 'unknown',
            sourceStream: 'recall',
            utteranceKey: 'provider-1:1000',
            participantId: 1,
            displayName: 'Ada',
            cursor: 11,
        });
        expect(store.transcriptGroups[0].messages).toEqual([
            expect.objectContaining({
                text: 'What is the price?',
                status: 'partial',
            }),
        ]);
    });

    it('replaces a Recall partial with one final provider item', () => {
        const store = useRealtimeAgentStore();

        store.upsertRecallPartial(recallTurn({ text: 'We need' }));
        store.finalizeRecallTranscript(
            recallTurn({
                eventId: 12,
                providerItemId: 'final-1',
                text: 'We need SSO.',
                endedAt: 1800,
                status: 'final',
            }),
        );

        expect(store.transcriptGroups).toHaveLength(1);
        expect(store.transcriptGroups[0]).toMatchObject({
            itemId: 'final-1',
            utteranceKey: 'provider-1:1000',
            endTime: 1800,
            cursor: 12,
        });
        expect(store.transcriptGroups[0].messages).toEqual([
            {
                text: 'We need SSO.',
                timestamp: 1000,
                itemId: 'final-1',
                status: 'final',
            },
        ]);
    });

    it('ignores late partials after final', () => {
        const store = useRealtimeAgentStore();
        const finalTurn = recallTurn({
            eventId: 12,
            providerItemId: 'final-1',
            text: 'The final answer.',
            status: 'final',
        });

        store.finalizeRecallTranscript(finalTurn);
        store.upsertRecallPartial(recallTurn({ eventId: 13, text: 'A stale partial' }));

        expect(store.transcriptGroups).toHaveLength(1);
        expect(store.transcriptGroups[0].messages[0]).toMatchObject({
            text: 'The final answer.',
            status: 'final',
        });
        expect(store.transcriptGroups[0].cursor).toBe(12);
    });

    it('deduplicates replayed finals', () => {
        const store = useRealtimeAgentStore();

        store.finalizeRecallTranscript(
            recallTurn({
                eventId: 12,
                providerItemId: 'final-1',
                text: 'Original final.',
                status: 'final',
            }),
        );
        store.finalizeRecallTranscript(
            recallTurn({
                eventId: 14,
                providerItemId: 'final-1',
                text: 'Canonical replay.',
                status: 'final',
            }),
        );

        expect(store.transcriptGroups).toHaveLength(1);
        expect(store.transcriptGroups[0].messages[0].text).toBe('Canonical replay.');
        expect(store.transcriptGroups[0].cursor).toBe(14);
    });

    it('does not merge simultaneous participants', () => {
        const store = useRealtimeAgentStore();

        store.upsertRecallPartial(recallTurn());
        store.upsertRecallPartial(
            recallTurn({
                eventId: 11,
                utteranceKey: 'provider-2:1000',
                participantId: 2,
                displayName: 'Grace',
                text: 'Speaking at the same time',
            }),
        );

        expect(store.transcriptGroups).toHaveLength(2);
        expect(store.transcriptGroups.map((group) => group.participantId)).toEqual([1, 2]);
    });

    it('updates earlier transcript roles only after explicit assignment', () => {
        const store = useRealtimeAgentStore();

        store.finalizeRecallTranscript(
            recallTurn({
                providerItemId: 'final-1',
                text: 'Unknown until assigned.',
                status: 'final',
            }),
        );

        expect(store.transcriptGroups[0].role).toBe('unknown');

        store.applyParticipantRoles([participant({ salesRole: 'salesperson', displayName: 'Ada Lovelace' })]);

        expect(store.transcriptGroups[0]).toMatchObject({
            role: 'salesperson',
            displayName: 'Ada Lovelace',
        });
    });

    it('keeps local You and Customer labels compatible', () => {
        const store = useRealtimeAgentStore();

        store.finalizeTranscript('salesperson', 'local-you', 'Hello there.', 'salesperson');
        store.finalizeTranscript('customer', 'local-customer', 'Hi.', 'customer');
        store.applyParticipantRoles([participant({ id: 1, salesRole: 'salesperson' }), participant({ id: 2, salesRole: 'customer' })]);

        expect(store.transcriptGroups).toEqual([
            expect.objectContaining({ role: 'salesperson', sourceStream: 'salesperson' }),
            expect.objectContaining({ role: 'customer', sourceStream: 'customer' }),
        ]);
    });

    it('formats Recall offsets as stable elapsed time', () => {
        expect(formatTranscriptTimestamp({ sourceStream: 'recall', startTime: 65_000 })).toBe('01:05');
        expect(formatTranscriptTimestamp({ sourceStream: 'recall', startTime: 3_661_000 })).toBe('01:01:01');
    });

    it('keeps local transcript timestamps in wall-clock time', () => {
        const localTimestamp = new Date(2026, 0, 2, 9, 5, 7).getTime();

        expect(formatTranscriptTimestamp({ sourceStream: 'salesperson', startTime: localTimestamp })).toBe('09:05:07');
    });

    it('exposes transcript additions and partial text updates as a polite log', () => {
        expect(liveTranscriptionSource).toContain('role="log"');
        expect(liveTranscriptionSource).toContain('aria-live="polite"');
        expect(liveTranscriptionSource).toContain('aria-relevant="additions text"');
        expect(liveTranscriptionSource).toContain('aria-atomic="false"');
        expect(liveTranscriptionSource).toContain(':aria-label="transcriptAriaLabel(group)"');
        expect(liveTranscriptionSource).toContain(':aria-busy="isPartial(group)"');
    });

    it('wraps the named participant legend without clipping it', () => {
        const legend = liveTranscriptionSource.match(/<div\s+v-else\s+class="([^"]+)"/);

        expect(legend?.[1]).toContain('flex-wrap');
        expect(legend?.[1]).not.toContain('overflow-hidden');
    });

    it('deduplicates approval requests by OpenAI item id', () => {
        const store = useRealtimeAgentStore();
        const request = {
            id: 'approval-1',
            name: 'update_deal',
            arguments: { stage: 'won' },
            timestamp: 1,
        };

        store.addApprovalRequest(request);
        store.addApprovalRequest(request);

        expect(store.pendingApprovals).toEqual([request]);
        store.resolveApprovalRequest('approval-1');
        expect(store.pendingApprovals).toEqual([]);
    });

    it('bounds live guidance collections for long calls', () => {
        const store = useRealtimeAgentStore();

        for (let index = 0; index < 20; index++) {
            store.showKnowledgeCard({
                title: `Card ${index}`,
                content: 'Content',
            });
            store.suggestTalkTrack({
                text: `Track ${index}`,
                priority: 'medium',
            });
        }

        expect(store.knowledgeCards).toHaveLength(12);
        expect(store.talkTracks).toHaveLength(10);
        expect(store.knowledgeCards[0].title).toBe('Card 19');
    });

    it('applies pain points and topics once by tool call id', () => {
        const store = useRealtimeAgentStore();

        expect(
            store.capturePainPoint('call-pain-1', {
                text: 'Manual handoffs delay every implementation.',
                category: 'implementation',
                severity: 'high',
                evidenceItemIds: ['item-1'],
            }),
        ).toBe(true);
        expect(
            store.capturePainPoint('call-pain-1', {
                text: 'This replay must not be applied.',
                severity: 'low',
                evidenceItemIds: ['item-2'],
            }),
        ).toBe(false);

        expect(
            store.captureDiscussionTopic('call-topic-1', {
                name: 'Security review',
                sentiment: 'neutral',
                context: 'The customer needs SSO and audit-log details.',
                evidenceItemIds: ['item-3'],
            }),
        ).toBe(true);
        expect(
            store.captureDiscussionTopic('call-topic-1', {
                name: 'Different replay',
                sentiment: 'negative',
                context: 'This replay must not be applied.',
                evidenceItemIds: ['item-4'],
            }),
        ).toBe(false);

        expect(store.insights).toEqual([
            expect.objectContaining({
                type: 'pain_point',
                text: 'Manual handoffs delay every implementation.',
                importance: 'high',
                category: 'implementation',
                evidenceItemIds: ['item-1'],
                toolCallId: 'call-pain-1',
            }),
        ]);
        expect(store.topics).toEqual([
            expect.objectContaining({
                name: 'Security review',
                sentiment: 'neutral',
                context: 'The customer needs SSO and audit-log details.',
                evidenceItemIds: ['item-3'],
                toolCallId: 'call-topic-1',
            }),
        ]);
        expect(store.appliedUiToolCallIds).toEqual(['call-pain-1', 'call-topic-1']);
    });

    it('bounds processed tool ids without dropping applied cards', () => {
        const store = useRealtimeAgentStore();

        for (let index = 0; index <= 2_000; index++) {
            store.capturePainPoint(`call-${index}`, {
                text: `Pain point ${index}`,
                severity: 'low',
                evidenceItemIds: [`item-${index}`],
            });
        }

        expect(store.appliedUiToolCallIds).toHaveLength(2_000);
        expect(store.appliedUiToolCallIds[0]).toBe('call-1');
        expect(store.appliedUiToolCallIds.at(-1)).toBe('call-2000');
        expect(store.insights).toHaveLength(2_001);
        expect(store.insights[0].toolCallId).toBe('call-2000');
        expect(store.insights.at(-1)?.toolCallId).toBe('call-0');
    });
});
