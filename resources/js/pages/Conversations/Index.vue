<script setup lang="ts">
import BaseCard from '@/components/design/BaseCard.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import PageContainer from '@/components/design/PageContainer.vue';
import Button from '@/components/ui/button/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { format } from 'date-fns';
import { MessageCircle } from 'lucide-vue-next';

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
}

interface PaginatedSessions {
    data: ConversationSession[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

defineProps<{
    sessions: PaginatedSessions;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Call History',
        href: '/conversations',
    },
];

const formatDate = (dateString: string) => {
    return format(new Date(dateString), 'MMM d, yyyy');
};

const formatTime = (dateString: string) => {
    return format(new Date(dateString), 'h:mm a');
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

const navigateToConversation = (sessionId: number) => {
    router.visit(`/conversations/${sessionId}`);
};

const startNewCall = () => {
    router.visit('/realtime-agent');
};

const goToPage = (page: number) => {
    router.visit(`/conversations?page=${page}`);
};
</script>

<template>
    <Head title="Call History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <PageContainer>
            <!-- Header with action button -->
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Call History</h1>
                <Button @click="startNewCall">Start New Call</Button>
            </div>

            <!-- Empty state -->
            <div v-if="sessions.data.length === 0">
                <BaseCard>
                    <EmptyState
                        :icon="MessageCircle"
                        title="No call history yet"
                        description="Start a new call to begin tracking your sales conversations"
                    >
                        <template #action>
                            <Button @click="startNewCall">Start New Call</Button>
                        </template>
                    </EmptyState>
                </BaseCard>
            </div>

            <!-- Conversations list -->
            <div v-else class="space-y-4">
                <BaseCard v-for="session in sessions.data" :key="session.id" hover clickable @click="navigateToConversation(session.id)">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <!-- Title and template -->
                            <div class="mb-2 flex items-center gap-3">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                                    {{ session.title || `Call on ${formatDate(session.started_at)}` }}
                                </h3>
                                <span
                                    v-if="session.template_used"
                                    class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-200"
                                >
                                    {{ session.template_used }}
                                </span>
                            </div>

                            <!-- Customer info and duration -->
                            <div class="mb-2 flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
                                <span v-if="session.customer_name">
                                    <span class="text-gray-500 dark:text-gray-500">Customer:</span>
                                    {{ session.customer_name }}
                                </span>
                                <span v-if="session.customer_company">
                                    <span class="text-gray-500 dark:text-gray-500">Company:</span>
                                    {{ session.customer_company }}
                                </span>
                                <span>
                                    <span class="text-gray-500 dark:text-gray-500">Duration:</span>
                                    {{ formatDuration(session.duration_seconds) }}
                                </span>
                            </div>

                            <!-- Metrics -->
                            <div class="flex items-center gap-6 text-xs text-gray-600 dark:text-gray-400">
                                <div class="flex items-center gap-1">
                                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"
                                        />
                                    </svg>
                                    <span>{{ session.total_transcripts }} messages</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
                                        />
                                    </svg>
                                    <span>{{ session.total_insights }} insights</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
                                        />
                                    </svg>
                                    <span>{{ session.total_action_items }} actions</span>
                                </div>
                            </div>

                            <!-- Intelligence Summary -->
                            <div v-if="session.final_intent" class="mt-3 flex items-center gap-4 text-xs">
                                <span
                                    class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 font-medium dark:bg-gray-800"
                                    :class="getIntentColor(session.final_intent)"
                                >
                                    {{ session.final_intent }}
                                </span>
                                <span v-if="session.final_buying_stage" class="text-gray-600 dark:text-gray-400">
                                    Stage: {{ session.final_buying_stage }}
                                </span>
                                <span v-if="session.final_engagement_level" class="text-gray-600 dark:text-gray-400">
                                    Engagement: {{ session.final_engagement_level }}%
                                </span>
                            </div>
                        </div>

                        <!-- Date/time -->
                        <div class="text-right text-sm text-gray-500 dark:text-gray-400">
                            <div>{{ formatDate(session.started_at) }}</div>
                            <div>{{ formatTime(session.started_at) }}</div>
                        </div>
                    </div>
                </BaseCard>
            </div>

            <!-- Pagination -->
            <div v-if="sessions.last_page > 1" class="mt-6 flex justify-center">
                <nav class="flex gap-2">
                    <Button
                        v-for="page in sessions.last_page"
                        :key="page"
                        :variant="page === sessions.current_page ? 'default' : 'outline'"
                        size="sm"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </Button>
                </nav>
            </div>
        </PageContainer>
    </AppLayout>
</template>
