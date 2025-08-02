<template>
    <div
        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
    >
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Commitments Made</h3>

        <div v-if="commitments.length === 0" class="text-xs text-gray-600 dark:text-gray-400">
            No commitments captured yet...
        </div>

        <div v-else class="scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent dark:scrollbar-thumb-gray-600 flex-1 min-h-0 space-y-1.5 overflow-y-auto">
            <div v-for="commitment in commitments" :key="commitment.id" class="flex items-start gap-2 py-2 text-xs">
                <div class="flex items-start gap-2">
                    <span
                        :class="[
                            'mt-1 h-2 w-2 flex-shrink-0 rounded-full',
                            commitment.speaker === 'salesperson' ? 'bg-green-500' : 'bg-gray-400',
                        ]"
                    ></span>
                    <div class="flex-1">
                        <span
                            :class="[
                                'font-medium',
                                commitment.speaker === 'salesperson'
                                    ? 'text-gray-900 dark:text-gray-100'
                                    : 'text-gray-900 dark:text-gray-100',
                            ]"
                        >
                            {{ commitment.speaker === 'salesperson' ? 'You:' : 'Customer:' }}
                        </span>
                        <span class="ml-1 text-gray-600 dark:text-gray-400">{{ commitment.text }}</span>
                        <span v-if="commitment.deadline" class="ml-1 text-gray-600 dark:text-gray-400">
                            ({{ commitment.deadline }})
                        </span>
                    </div>
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
const commitments = computed(() => realtimeStore.commitments);
</script>