<script setup lang="ts">
import BaseCard from '@/components/design/BaseCard.vue';
import PageContainer from '@/components/design/PageContainer.vue';
import Button from '@/components/ui/button/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AudioPlayer from '@/components/AudioPlayer.vue';
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
    has_recording: boolean;
    recording_path: string | null;
    recording_duration: number | null;
    recording_size: number | null;
}

interface Transcript {
    id: number;
    speaker: string;
    speaker_label: string;
    text: string;
    spoken_at: string;
    group_id: string | null;
    system_category: string | null;
}

interface Insight {
    id: number;
    insight_type: string;
    data: any;
    captured_at: string;
}

const props = defineProps<{
    session: ConversationSession;
    transcripts: Transcript[];
    insights: Record<string, Insight[]>;
}>();

const userNotes = ref(props.session.user_notes || '');

const groupedInsights = computed(() => props.insights);

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

const formatFileSize = (bytes: number) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;
    
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    
    return `${size.toFixed(1)} ${units[unitIndex]}`;
};

const getRecordingUrl = (recordingPath: string) => {
    // Convert file path to URL for audio playback
    // In Electron/NativePHP, we need to handle file:// URLs
    if (recordingPath.startsWith('/')) {
        return `file://${recordingPath}`;
    }
    return recordingPath;
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
                    </div>
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
                            <span class="text-xs text-gray-500 dark:text-gray-400"> {{ transcripts.length }} messages </span>
                        </div>
                        
                        <!-- Audio Player -->
                        <div v-if="session.has_recording && session.recording_path" class="mb-4">
                            <AudioPlayer
                                :src="getRecordingUrl(session.recording_path)"
                                :duration="session.recording_duration || 0"
                                :size="session.recording_size || 0"
                            />
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Left channel: Salesperson | Right channel: Customer
                            </p>
                        </div>

                        <div v-if="transcripts.length === 0" class="flex flex-1 items-center justify-center">
                            <p class="text-gray-500 dark:text-gray-400">No transcript available</p>
                        </div>

                        <div v-else class="flex-1 space-y-2 overflow-y-auto" :style="{ 'min-height': '0', 'max-height': session.has_recording ? '500px' : '600px' }">
                            <div
                                v-for="transcript in transcripts"
                                :key="transcript.id"
                                :class="[
                                    'rounded-lg p-3 text-sm',
                                    transcript.speaker === 'salesperson'
                                        ? 'ml-12 bg-blue-50 dark:bg-blue-900/20'
                                        : transcript.speaker === 'customer'
                                          ? 'mr-12 bg-green-50 dark:bg-green-900/20'
                                          : 'mx-6 bg-gray-50 text-xs dark:bg-gray-800',
                                ]"
                            >
                                <div class="mb-1 flex items-baseline justify-between">
                                    <span
                                        :class="[
                                            'font-medium',
                                            transcript.speaker === 'salesperson'
                                                ? 'text-blue-700 dark:text-blue-400'
                                                : transcript.speaker === 'customer'
                                                  ? 'text-green-700 dark:text-green-400'
                                                  : 'text-gray-600 dark:text-gray-400',
                                        ]"
                                    >
                                        {{ transcript.speaker_label }}
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ formatTime(transcript.spoken_at) }}
                                    </span>
                                </div>
                                <p class="leading-relaxed text-gray-800 dark:text-gray-200">
                                    {{ transcript.text }}
                                </p>
                            </div>
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
                </div>
            </div>
        </PageContainer>
    </AppLayout>
</template>
