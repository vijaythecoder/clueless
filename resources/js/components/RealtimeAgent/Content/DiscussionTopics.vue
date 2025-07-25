<template>
    <div
        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
    >
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Discussion Topics</h3>

        <div v-if="topics.length === 0" class="py-2 text-center">
            <p class="text-xs text-gray-600 dark:text-gray-400">Topics will appear as discussed...</p>
        </div>

        <div
            v-else
            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent flex flex-1 min-h-0 flex-wrap content-start gap-2 overflow-y-auto"
        >
            <div
                v-for="topic in topics"
                :key="topic.id"
                :class="[
                    'animate-fadeIn inline-flex h-6 items-center rounded-full px-2 text-xs font-medium',
                    topicSentimentClass(topic.sentiment),
                ]"
            >
                <span>{{ topic.name }}</span>
                <span class="ml-1 text-gray-600 dark:text-gray-400">{{ topic.mentions }}x</span>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';

// Store
const realtimeStore = useRealtimeAgentStore();

// Computed
const topics = computed(() => realtimeStore.topics);

// Methods
const topicSentimentClass = (sentiment: string) => {
    const classMap: Record<string, string> = {
        'positive': 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400',
        'negative': 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400',
        'mixed': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400',
        'neutral': 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400',
    };
    
    return classMap[sentiment] || classMap.neutral;
};
</script>