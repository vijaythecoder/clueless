<template>
    <div class="flex items-center gap-2">
        <label class="text-xs text-gray-600 dark:text-gray-400">Mock Data</label>
        <button
            @click="toggleMockMode"
            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
            :class="isMockMode ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600'"
            :aria-checked="isMockMode"
            role="switch"
        >
            <span class="sr-only">Toggle mock data mode</span>
            <span
                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
                :class="isMockMode ? 'translate-x-4' : 'translate-x-0.5'"
            />
        </button>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';

const realtimeStore = useRealtimeAgentStore();

const isMockMode = computed(() => realtimeStore.isMockMode);

const toggleMockMode = () => {
    if (isMockMode.value) {
        realtimeStore.disableMockMode();
    } else {
        realtimeStore.enableMockMode();
    }
};
</script>