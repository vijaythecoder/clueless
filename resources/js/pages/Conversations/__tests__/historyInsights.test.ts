import { describe, expect, it } from 'vitest';
import { groupHistoryInsights } from '../historyInsights';

interface TestInsight {
    id: number;
    insight_type: string;
    card_type: string | null;
}

describe('conversation history insight grouping', () => {
    it('groups Local card aliases with their canonical history sections', () => {
        const localPain: TestInsight = {
            id: 1,
            insight_type: 'pain_point',
            card_type: 'local_pain_point',
        };
        const localTopic: TestInsight = {
            id: 2,
            insight_type: 'discussion_topic',
            card_type: 'local_discussion_topic',
        };

        const groups = groupHistoryInsights([localPain, localTopic]);

        expect(groups.pain_point).toEqual([localPain]);
        expect(groups.discussion_topic).toEqual([localTopic]);
        expect(groups.local_pain_point).toBeUndefined();
        expect(groups.local_discussion_topic).toBeUndefined();
    });

    it('preserves Recall and legacy card grouping behavior', () => {
        const recallPain: TestInsight = {
            id: 1,
            insight_type: 'pain_point',
            card_type: 'pain_point',
        };
        const recallTopic: TestInsight = {
            id: 2,
            insight_type: 'discussion_topic',
            card_type: 'discussion_topic',
        };
        const legacyTopic: TestInsight = {
            id: 3,
            insight_type: 'topic',
            card_type: null,
        };

        const groups = groupHistoryInsights([recallPain, recallTopic, legacyTopic]);

        expect(groups.pain_point).toEqual([recallPain]);
        expect(groups.discussion_topic).toEqual([recallTopic]);
        expect(groups.topic).toEqual([legacyTopic]);
    });
});
