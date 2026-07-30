import type { MeetingCaptureStatus } from '@/types/meetingCapture';
import { describe, expect, it } from 'vitest';
import copilotSource from '../../../../pages/RealtimeAgent/Copilot.vue?raw';
import titleBarSource from '../../Navigation/TitleBar.vue?raw';
import modalSource from '../MeetingCaptureModal.vue?raw';
import participantModalSource from '../ParticipantRoleModal.vue?raw';

describe('MeetingCaptureModal contract', () => {
    it('requires an explicit mode and Teams URL', () => {
        expect(modalSource).toContain("selectedMode === 'local'");
        expect(modalSource).toContain("selectedMode === 'recall'");
        expect(modalSource).toContain('meetingUrl.value.trim()');
        expect(modalSource).toContain('disabled');
    });

    it('renders every Recall capture status', () => {
        const statuses: MeetingCaptureStatus[] = ['creating', 'joining', 'waiting_room', 'active', 'stopping', 'ended', 'failed'];

        for (const status of statuses) {
            expect(modalSource).toContain(status);
        }
    });

    it('offers explicit fallback and settings actions', () => {
        expect(modalSource).toContain('Use Local capture');
        expect(modalSource).toContain('Open Recall settings');
        expect(modalSource).toContain("emit('selectLocal')");
        expect(modalSource).toContain("emit('openSettings')");
    });

    it('lists each human participant with a This is me action', () => {
        expect(participantModalSource).toContain('v-for="participant in humanParticipants"');
        expect(participantModalSource).toContain('participant.displayName');
        expect(participantModalSource).toContain('This is me');
        expect(participantModalSource).toContain("emit('assign', participant.id)");
    });

    it('hides and skips permission checks in Recall mode', () => {
        expect(titleBarSource).toContain("captureMode === 'local'");
        expect(titleBarSource).toContain('shouldRequestLocalPermissions(props.captureMode)');
    });

    it('keeps Recall display events separate from leased analysis', () => {
        const finalHandler = copilotSource.slice(copilotSource.indexOf('onFinal:'), copilotSource.indexOf('onParticipants:'));
        const analysisHandler = copilotSource.slice(copilotSource.indexOf('onAnalysisDelivery:'), copilotSource.indexOf('onError: addRecallWarning'));

        expect(finalHandler).toContain('realtimeStore.finalizeRecallTranscript(turn)');
        expect(finalHandler).not.toContain('analyzeTurn');
        expect(analysisHandler).toContain('await copilotSession.analyzeTurn');
        expect(analysisHandler).toContain('analysisDeliveryId: delivery.id');
    });

    it('passes each delivery immutable evidence snapshot and preserves Local persistence', () => {
        expect(copilotSource).toContain('allowedEvidenceItemIds:');
        expect(copilotSource).toContain("card_type: evidence.analysisDeliveryId === undefined ? 'local_pain_point' : 'pain_point'");
        expect(copilotSource).toContain("card_type: evidence.analysisDeliveryId === undefined ? 'local_discussion_topic' : 'discussion_topic'");
    });
});
