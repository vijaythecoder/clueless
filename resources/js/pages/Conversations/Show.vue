<script setup lang="ts">
import BaseCard from '@/components/design/BaseCard.vue';
import PageContainer from '@/components/design/PageContainer.vue';
import Button from '@/components/ui/button/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { groupHistoryInsights } from '@/pages/Conversations/historyInsights';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { format } from 'date-fns';
import { computed, defineProps, ref } from 'vue';

interface ConversationSession {
    id: number;
    title: string | null;
    customer_name: string | null;
    customer_company: string | null;
    started_at: string;
    ended_at: string | null;
    duration_seconds: number;
    template_used: string | null;
    final_intent: string | null;
    final_buying_stage: string | null;
    final_engagement_level: number;
    final_sentiment: string | null;
    total_transcripts: number;
    total_insights: number;
    total_topics: number;
    total_commitments: number;
    total_action_items: number;
    ai_summary: string | null;
    user_notes: string | null;
}

interface Capture {
    provider: 'local' | 'recall';
    status: string;
    failure_code: string | null;
    failure_message: string | null;
}

interface Transcript {
    id: number;
    speaker: string;
    speaker_label: string;
    participant_display_name: string | null;
    sales_role: 'salesperson' | 'customer' | 'unknown' | 'bot' | 'system';
    is_you: boolean;
    text: string;
    spoken_at: string;
    order_index: number;
    group_id: string | null;
    system_category: string | null;
    source_stream: string | null;
    status: 'partial' | 'final';
    provider: 'local' | 'recall' | null;
    provider_item_id: string | null;
    started_offset_ms: number | null;
    ended_offset_ms: number | null;
}

interface Insight {
    id: number;
    insight_type: string;
    card_type: string | null;
    data: Record<string, any>;
    captured_at: string;
    analysis_delivery_id: number | null;
    evidence_item_ids: string[];
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    session: ConversationSession;
    capture: Capture | null;
    transcripts: Paginator<Transcript>;
    insights: Paginator<Insight>;
}>();

const userNotes = ref(props.session.user_notes || '');

const transcriptItems = computed(() => props.transcripts.data);
const groupedInsights = computed(() => groupHistoryInsights(props.insights.data));

const visitPage = (url: string | null) => {
    if (!url) return;
    router.visit(url, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

// Define formatting functions first
const formatDate = (dateString: string) => {
    return format(new Date(dateString), 'MMM d, yyyy');
};

// Now we can use formatDate in breadcrumbs
const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Conversations',
        href: '/conversations',
    },
    {
        title: props.session.title || `Call on ${formatDate(props.session.started_at)}`,
        href: `/conversations/${props.session.id}`,
    },
];

const formatTime = (dateString: string) => {
    return format(new Date(dateString), 'h:mm:ss a');
};

const formatDateTime = (dateString: string) => {
    return format(new Date(dateString), 'MMM d, yyyy h:mm a');
};

const formatDuration = (seconds: number) => {
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
};

const getIntentColor = (intent: string) => {
    switch (intent) {
        case 'decision':
            return 'text-green-700 dark:text-green-400';
        case 'evaluation':
            return 'text-blue-700 dark:text-blue-400';
        case 'research':
            return 'text-yellow-700 dark:text-yellow-400';
        case 'implementation':
            return 'text-purple-700 dark:text-purple-400';
        default:
            return 'text-gray-700 dark:text-gray-400';
    }
};

const getEngagementColor = (level: number) => {
    if (level >= 80) return 'bg-green-500';
    if (level >= 60) return 'bg-blue-500';
    if (level >= 40) return 'bg-yellow-500';
    return 'bg-red-500';
};

const getInsightTypeLabel = (type: string) => {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());
};

const getInsightColor = (type: string) => {
    switch (type) {
        case 'pain_point':
            return 'text-red-700 dark:text-red-400';
        case 'objection':
            return 'text-orange-700 dark:text-orange-400';
        case 'positive_signal':
            return 'text-green-700 dark:text-green-400';
        case 'concern':
            return 'text-yellow-700 dark:text-yellow-400';
        case 'question':
            return 'text-blue-700 dark:text-blue-400';
        default:
            return 'text-gray-700 dark:text-gray-400';
    }
};

const transcriptRole = (transcript: Transcript) => transcript.sales_role || transcript.speaker;

const saveNotes = async () => {
    try {
        await axios.patch(`/conversations/${props.session.id}/notes`, {
            user_notes: userNotes.value,
        });
    } catch (error) {
        console.error('Failed to save notes:', error);
    }
};

const deleteConversation = async () => {
    if (confirm('Are you sure you want to delete this conversation? This action cannot be undone.')) {
        try {
            await axios.delete(`/conversations/${props.session.id}`);
            router.visit('/conversations');
        } catch (error) {
            console.error('Failed to delete conversation:', error);
            alert('Failed to delete conversation');
        }
    }
};
</script>

<template>
    <Head :title="session.title || `Call on ${formatDate(session.started_at)}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <PageContainer>
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ session.title || `Call on ${formatDate(session.started_at)}` }}
                    </h1>
                    <div class="mt-1 flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
                        <span v-if="session.customer_name">{{ session.customer_name }}</span>
                        <span v-if="session.customer_company">{{ session.customer_company }}</span>
                        <span>{{ formatDuration(session.duration_seconds) }}</span>
                        <span>{{ formatDateTime(session.started_at) }}</span>
                        <span v-if="capture" class="capitalize"> {{ capture.provider }} · {{ capture.status.replace(/_/g, ' ') }} </span>
                    </div>
                    <p v-if="capture?.failure_message" class="mt-2 text-sm text-red-700 dark:text-red-400">
                        {{ capture.failure_message }}
                        <span v-if="capture.failure_code" class="ml-1 font-mono text-xs">({{ capture.failure_code }})</span>
                    </p>
                </div>
                <Button variant="destructive" size="sm" @click="deleteConversation"> Delete </Button>
            </div>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-12 gap-4">
                <!-- Left Panel - Intelligence Overview (3 cols) -->
                <div class="col-span-3 space-y-4">
                    <!-- Customer Intelligence -->
                    <BaseCard>
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Customer Intelligence</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500 dark:text-gray-400">Intent</span>
                                <span
                                    class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium dark:bg-gray-800"
                                    :class="getIntentColor(session.final_intent || 'unknown')"
                                >
                                    {{ session.final_intent || 'Unknown' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500 dark:text-gray-400">Stage</span>
                                <span class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ session.final_buying_stage || 'N/A' }}
                                </span>
                            </div>
                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Engagement</span>
                                    <span class="text-xs text-gray-600 dark:text-gray-400"> {{ session.final_engagement_level }}% </span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                    <div
                                        class="h-1.5 rounded-full transition-all"
                                        :class="getEngagementColor(session.final_engagement_level)"
                                        :style="`width: ${session.final_engagement_level}%`"
                                    ></div>
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500 dark:text-gray-400">Sentiment</span>
                                <span class="font-medium text-gray-900 capitalize dark:text-gray-100">
                                    {{ session.final_sentiment || 'Neutral' }}
                                </span>
                            </div>
                        </div>
                    </BaseCard>

                    <!-- Topics -->
                    <BaseCard v-if="groupedInsights.topic?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Topics ({{ groupedInsights.topic.length }})</h3>
                        <div class="space-y-1 text-sm">
                            <div v-for="topic in groupedInsights.topic.slice(0, 8)" :key="topic.id" class="flex items-center justify-between">
                                <span class="truncate text-gray-700 dark:text-gray-300">
                                    {{ topic.data.name }}
                                </span>
                                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400"> {{ topic.data.mentions }}x </span>
                            </div>
                            <div v-if="groupedInsights.topic.length > 8" class="pt-1 text-xs text-gray-500 dark:text-gray-400">
                                +{{ groupedInsights.topic.length - 8 }} more...
                            </div>
                        </div>
                    </BaseCard>

                    <!-- Action Items -->
                    <BaseCard v-if="groupedInsights.action_item?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Actions ({{ groupedInsights.action_item.length }})
                        </h3>
                        <div class="space-y-2">
                            <div v-for="item in groupedInsights.action_item.slice(0, 5)" :key="item.id" class="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    :checked="item.data.completed"
                                    disabled
                                    class="mt-0.5 flex-shrink-0 rounded border-gray-300 dark:border-gray-600"
                                />
                                <p class="truncate text-gray-700 dark:text-gray-300">
                                    {{ item.data.text }}
                                </p>
                            </div>
                        </div>
                    </BaseCard>
                </div>

                <!-- Center - Transcript (6 cols) -->
                <div class="col-span-6">
                    <BaseCard class="flex h-full flex-col">
                        <div class="mb-3 flex flex-shrink-0 items-center justify-between">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Transcript</h2>
                            <span class="text-xs text-gray-500 dark:text-gray-400"> {{ transcripts.total }} messages </span>
                        </div>

                        <div v-if="transcriptItems.length === 0" class="flex flex-1 items-center justify-center">
                            <p class="text-gray-500 dark:text-gray-400">No transcript available</p>
                        </div>

                        <div v-else class="flex-1 space-y-2 overflow-y-auto" style="min-height: 0; max-height: 600px">
                            <div
                                v-for="transcript in transcriptItems"
                                :key="transcript.id"
                                :class="[
                                    'rounded-lg p-3 text-sm',
                                    transcriptRole(transcript) === 'salesperson'
                                        ? 'ml-12 bg-blue-50 dark:bg-blue-900/20'
                                        : transcriptRole(transcript) === 'customer'
                                          ? 'mr-12 bg-green-50 dark:bg-green-900/20'
                                          : transcriptRole(transcript) === 'bot' || transcript.speaker === 'system'
                                            ? 'mx-6 bg-gray-100 text-xs dark:bg-gray-800'
                                            : 'mx-6 border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900',
                                ]"
                            >
                                <div class="mb-1 flex items-baseline justify-between">
                                    <span
                                        :class="[
                                            'font-medium',
                                            transcriptRole(transcript) === 'salesperson'
                                                ? 'text-blue-700 dark:text-blue-400'
                                                : transcriptRole(transcript) === 'customer'
                                                  ? 'text-green-700 dark:text-green-400'
                                                  : 'text-gray-600 dark:text-gray-400',
                                        ]"
                                    >
                                        {{ transcript.speaker_label }}
                                        <span v-if="transcript.is_you" class="ml-1 text-xs font-normal text-blue-600 dark:text-blue-400">You</span>
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ formatTime(transcript.spoken_at) }}
                                    </span>
                                </div>
                                <p class="leading-relaxed text-gray-800 dark:text-gray-200">
                                    {{ transcript.text }}
                                </p>
                                <p
                                    v-if="transcript.provider === 'recall' && transcript.provider_item_id"
                                    class="mt-2 font-mono text-[11px] break-all text-gray-500 dark:text-gray-500"
                                >
                                    Evidence {{ transcript.provider_item_id }}
                                </p>
                            </div>
                        </div>
                        <div v-if="transcripts.last_page > 1" class="mt-3 flex items-center justify-between border-t pt-3">
                            <Button variant="outline" size="sm" :disabled="!transcripts.prev_page_url" @click="visitPage(transcripts.prev_page_url)">
                                Previous
                            </Button>
                            <span class="text-xs text-gray-500">Page {{ transcripts.current_page }} of {{ transcripts.last_page }}</span>
                            <Button variant="outline" size="sm" :disabled="!transcripts.next_page_url" @click="visitPage(transcripts.next_page_url)">
                                Next
                            </Button>
                        </div>
                    </BaseCard>
                </div>

                <!-- Right Panel - Insights & Notes (3 cols) -->
                <div class="col-span-3 space-y-4">
                    <!-- Key Insights -->
                    <BaseCard v-if="groupedInsights.key_insight?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Key Insights</h3>
                        <div class="space-y-2">
                            <div v-for="insight in groupedInsights.key_insight.slice(0, 5)" :key="insight.id" class="text-sm">
                                <span
                                    class="mb-1 inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium dark:bg-gray-800"
                                    :class="getInsightColor(insight.data.type)"
                                >
                                    {{ getInsightTypeLabel(insight.data.type) }}
                                </span>
                                <p class="text-xs leading-relaxed text-gray-600 dark:text-gray-400">
                                    {{ insight.data.text }}
                                </p>
                            </div>
                        </div>
                    </BaseCard>

                    <BaseCard v-if="groupedInsights.knowledge_card?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Knowledge</h3>
                        <div class="space-y-3">
                            <div v-for="insight in groupedInsights.knowledge_card" :key="insight.id" class="text-sm">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ insight.data.title }}</p>
                                <p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-400">
                                    {{ insight.data.content }}
                                </p>
                            </div>
                        </div>
                    </BaseCard>

                    <BaseCard v-if="groupedInsights.talk_track?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Talk Tracks</h3>
                        <p
                            v-for="insight in groupedInsights.talk_track"
                            :key="insight.id"
                            class="mb-2 text-xs leading-relaxed text-gray-600 last:mb-0 dark:text-gray-400"
                        >
                            {{ insight.data.text }}
                        </p>
                    </BaseCard>

                    <BaseCard v-if="groupedInsights.objection?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Objections</h3>
                        <div v-for="insight in groupedInsights.objection" :key="insight.id" class="mb-2 text-xs last:mb-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ insight.data.objection || insight.data.text }}</p>
                            <p v-if="insight.data.response" class="mt-1 text-gray-600 dark:text-gray-400">{{ insight.data.response }}</p>
                        </div>
                    </BaseCard>

                    <BaseCard v-if="groupedInsights.pain_point?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Pain Points</h3>
                        <div v-for="insight in groupedInsights.pain_point" :key="insight.id" class="mb-3 text-sm last:mb-0">
                            <p class="text-gray-800 dark:text-gray-200">{{ insight.data.text }}</p>
                            <p v-if="insight.data.category" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ insight.data.category }} · {{ insight.data.severity }}
                            </p>
                            <p v-if="insight.evidence_item_ids.length" class="mt-2 font-mono text-[11px] break-all text-gray-500 dark:text-gray-500">
                                Evidence {{ insight.evidence_item_ids.join(', ') }}
                            </p>
                        </div>
                    </BaseCard>

                    <BaseCard v-if="groupedInsights.discussion_topic?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Discussion Topics</h3>
                        <div v-for="insight in groupedInsights.discussion_topic" :key="insight.id" class="mb-3 text-sm last:mb-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ insight.data.name }}</p>
                            <p class="mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-400">{{ insight.data.context }}</p>
                            <p v-if="insight.evidence_item_ids.length" class="mt-2 font-mono text-[11px] break-all text-gray-500 dark:text-gray-500">
                                Evidence {{ insight.evidence_item_ids.join(', ') }}
                            </p>
                        </div>
                    </BaseCard>

                    <!-- Commitments -->
                    <BaseCard v-if="groupedInsights.commitment?.length > 0">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Commitments</h3>
                        <div class="space-y-2">
                            <div v-for="commitment in groupedInsights.commitment" :key="commitment.id" class="flex items-start gap-2 text-sm">
                                <span
                                    :class="[
                                        'mt-1.5 h-2 w-2 flex-shrink-0 rounded-full',
                                        commitment.data.speaker === 'salesperson' ? 'bg-blue-500' : 'bg-green-500',
                                    ]"
                                ></span>
                                <div class="text-xs">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ commitment.data.speaker === 'salesperson' ? 'You' : 'Customer' }}:
                                    </span>
                                    <span class="ml-1 text-gray-600 dark:text-gray-400">
                                        {{ commitment.data.text }}
                                    </span>
                                    <span v-if="commitment.data.deadline" class="mt-1 block text-gray-500 dark:text-gray-500">
                                        Due: {{ commitment.data.deadline }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </BaseCard>

                    <!-- AI Summary -->
                    <BaseCard v-if="session.ai_summary">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">AI Summary</h3>
                        <p class="text-xs leading-relaxed whitespace-pre-wrap text-gray-600 dark:text-gray-400">
                            {{ session.ai_summary }}
                        </p>
                    </BaseCard>

                    <!-- User Notes -->
                    <BaseCard class="flex flex-1 flex-col">
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Your Notes</h3>
                        <textarea
                            v-model="userNotes"
                            @blur="saveNotes"
                            class="flex-1 resize-none rounded-lg border border-gray-300 bg-white p-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                            placeholder="Add your notes here..."
                        ></textarea>
                    </BaseCard>

                    <div v-if="insights.last_page > 1" class="flex items-center justify-between">
                        <Button variant="outline" size="sm" :disabled="!insights.prev_page_url" @click="visitPage(insights.prev_page_url)">
                            Newer
                        </Button>
                        <span class="text-xs text-gray-500">{{ insights.current_page }} / {{ insights.last_page }}</span>
                        <Button variant="outline" size="sm" :disabled="!insights.next_page_url" @click="visitPage(insights.next_page_url)">
                            Older
                        </Button>
                    </div>
                </div>
            </div>
        </PageContainer>
    </AppLayout>
</template>
