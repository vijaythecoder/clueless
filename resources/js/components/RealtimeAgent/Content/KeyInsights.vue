<template>
    <div
        class="flex h-full flex-col overflow-hidden rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
    >
        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Key Insights</h3>

        <div v-if="insights.length === 0" class="py-4 text-center">
            <p class="text-xs text-gray-600 dark:text-gray-400">Listening for insights...</p>
        </div>

        <div
            v-else
            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent flex-1 min-h-0 space-y-2 overflow-y-auto"
        >
            <div v-for="insight in recentInsights" :key="insight.id" class="animate-fadeIn flex items-start gap-2">
                <div class="flex items-start gap-2">
                    <span
                        :class="[
                            'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                            insightTypeClass(insight.type),
                        ]"
                    >
                        {{ formatInsightType(insight.type) }}
                    </span>
                    <p class="flex-1 text-xs text-gray-900 dark:text-gray-100">{{ insight.text }}</p>
                </div>
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
const insights = computed(() => realtimeStore.insights);
const recentInsights = computed(() => realtimeStore.recentInsights);

// Methods
const formatInsightType = (type: string) => {
    return type.replace('_', ' ');
};

const insightTypeClass = (type: string) => {
    const classMap: Record<string, string> = {
        'pain_point': 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400',
        'objection': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400',
        'positive_signal': 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400',
        'concern': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400',
        'question': 'bg-blue-100 text-blue-800 dark:bg-blue-900/20 dark:text-blue-400',
    };
    
    return classMap[type] || 'bg-gray-100 text-gray-800 dark:bg-gray-900/20 dark:text-gray-400';
};
</script>