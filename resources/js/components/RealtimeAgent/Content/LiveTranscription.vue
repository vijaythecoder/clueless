<template>
    <div class="col-span-1 h-full">
        <div class="flex h-full flex-col rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <!-- Transcription Header -->
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Live Transcription</h3>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <div class="h-2 w-2 rounded-full bg-green-500"></div>
                            <span class="text-xs text-gray-600 dark:text-gray-400">You</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <div class="h-2 w-2 rounded-full bg-green-500"></div>
                            <span class="text-xs text-gray-600 dark:text-gray-400">Customer</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transcription Content - Reversed order, newest first -->
            <div
                ref="transcriptContainer"
                class="transcript-scrollbar flex flex-1 min-h-0 flex-col-reverse overflow-y-auto p-4 pb-8"
            >
                <div v-if="transcriptGroups.length === 0" class="py-12 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Waiting for conversation to begin...</p>
                </div>

                <div
                    v-for="group in [...transcriptGroups].reverse()"
                    :key="group.id"
                    class="mb-3 flex"
                    :class="[
                        group.role === 'salesperson' ? 'justify-end' : '',
                        group.role === 'customer' ? 'justify-start' : '',
                        group.role === 'system' ? 'justify-center' : '',
                    ]"
                >
                    <div
                        :class="[
                            'animate-fadeIn inline-block max-w-[70%] rounded-lg border p-3',
                            group.role === 'salesperson' ? 'border-blue-50 bg-blue-50 text-left dark:border-blue-900/20 dark:bg-blue-900/20' : '',
                            group.role === 'customer' ? 'border-gray-200 bg-gray-100 text-left dark:border-gray-700 dark:bg-gray-700' : '',
                            group.role === 'system' ? 'border-gray-200 bg-gray-50 text-center text-sm dark:border-gray-700 dark:bg-gray-800' : ''
                        ]"
                    >
                        <div class="min-w-0">
                            <p class="whitespace-pre-wrap break-words">{{ group.messages.map(msg => msg.text).join(' ') }}</p>
                        </div>
                        <span class="mt-2 block text-xs text-gray-500 dark:text-gray-400">
                            {{ formatTime(group.startTime) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';

// Store
const realtimeStore = useRealtimeAgentStore();

// Refs
const transcriptContainer = ref<HTMLElement>();

// Computed
const transcriptGroups = computed(() => realtimeStore.transcriptGroups);

// Watch for new messages to auto-scroll
watch(
    () => transcriptGroups.value.length,
    async () => {
        await nextTick();
        if (transcriptContainer.value) {
            // Since we're using flex-col-reverse, scrollTop = 0 is the bottom
            transcriptContainer.value.scrollTop = 0;
        }
    }
);

// Methods
const formatTime = (timestamp: number) => {
    const date = new Date(timestamp);
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    const seconds = date.getSeconds().toString().padStart(2, '0');
    return `${hours}:${minutes}:${seconds}`;
};
</script>