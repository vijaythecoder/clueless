interface HistoryInsight {
    insight_type: string;
    card_type: string | null;
}

const historyCardTypeAliases: Record<string, string> = {
    local_pain_point: 'pain_point',
    local_discussion_topic: 'discussion_topic',
};

export function groupHistoryInsights<T extends HistoryInsight>(insights: readonly T[]): Record<string, T[]> {
    return insights.reduce<Record<string, T[]>>((groups, insight) => {
        const cardType = insight.card_type || insight.insight_type;
        const groupType = historyCardTypeAliases[cardType] ?? cardType;
        (groups[groupType] ??= []).push(insight);

        return groups;
    }, {});
}
