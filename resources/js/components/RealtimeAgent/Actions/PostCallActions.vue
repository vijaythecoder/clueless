<template>
    <div
        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
    >
        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Post-Call Actions</h3>

        <div v-if="actionItems.length === 0" class="text-xs text-gray-600 dark:text-gray-400">
            Action items will appear here...
        </div>

        <div v-else class="scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent dark:scrollbar-thumb-gray-600 max-h-48 space-y-1.5 overflow-y-auto">
            <div v-for="item in actionItems" :key="item.id" class="flex items-start gap-2 py-2">
                <div class="flex items-start gap-2">
                    <input 
                        type="checkbox" 
                        :checked="item.completed"
                        @change="toggleComplete(item.id)"
                        class="mt-0.5 rounded border-gray-200 dark:border-gray-700" 
                    />
                    <label class="flex-1 text-xs text-gray-900 dark:text-gray-100">
                        {{ item.text }}
                        <span v-if="item.deadline" class="text-gray-600 dark:text-gray-400">
                            - Due: {{ item.deadline }}
                        </span>
                    </label>
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
const actionItems = computed(() => realtimeStore.actionItems);

// Methods
const toggleComplete = (id: string) => {
    realtimeStore.toggleActionItemComplete(id);
};
</script>