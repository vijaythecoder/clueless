<template>
    <div class="col-span-1 h-full">
        <div class="flex h-full flex-col rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
            <!-- Transcription Header -->
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="flex min-h-8 items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Live Transcription</h3>
                    <div v-if="!hasRecallGroups" class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <div class="h-2 w-2 rounded-full bg-green-500"></div>
                            <span class="text-xs text-gray-600 dark:text-gray-400">You</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <div class="h-2 w-2 rounded-full bg-green-500"></div>
                            <span class="text-xs text-gray-600 dark:text-gray-400">Customer</span>
                        </div>
                    </div>
                    <div v-else class="flex max-h-12 max-w-[68%] min-w-0 flex-wrap items-center justify-end gap-x-3 gap-y-1 overflow-y-auto py-0.5">
                        <div
                            v-for="participant in namedRecallParticipants"
                            :key="participant.id"
                            class="flex max-w-full min-w-0 items-center gap-1.5"
                        >
                            <div class="h-2 w-2 shrink-0 rounded-full" :class="legendDotClass(participant.role)"></div>
                            <span class="max-w-40 text-right text-xs leading-tight break-words text-gray-600 dark:text-gray-400">
                                {{ participant.displayName }}
                            </span>
                            <span v-if="participant.role === 'salesperson'" class="shrink-0 text-[10px] font-medium text-blue-600 dark:text-blue-400">
                                You
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transcription Content - Reversed order, newest first -->
            <div
                ref="transcriptContainer"
                class="transcript-scrollbar flex min-h-0 flex-1 flex-col-reverse overflow-y-auto p-4 pb-8"
                role="log"
                aria-live="polite"
                aria-relevant="additions text"
                aria-atomic="false"
            >
                <div v-if="transcriptGroups.length === 0" class="py-12 text-center">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Waiting for conversation to begin...</p>
                </div>

                <div
                    v-for="group in [...transcriptGroups].reverse()"
                    :key="group.id"
                    class="mb-3 flex"
                    role="group"
                    :aria-label="transcriptAriaLabel(group)"
                    :aria-busy="isPartial(group)"
                    :class="[
                        group.role === 'salesperson' ? 'justify-end' : '',
                        group.role === 'customer' || group.role === 'unknown' ? 'justify-start' : '',
                        group.role === 'bot' || group.role === 'system' ? 'justify-center' : '',
                    ]"
                >
                    <div
                        :class="[
                            'animate-fadeIn flex max-w-[70%] min-w-0 flex-col',
                            group.role === 'salesperson' ? 'items-end' : '',
                            group.role === 'customer' || group.role === 'unknown' ? 'items-start' : '',
                            group.role === 'bot' || group.role === 'system' ? 'items-center' : '',
                        ]"
                    >
                        <div v-if="group.sourceStream === 'recall' && group.displayName" class="mb-1 flex max-w-full items-baseline gap-1.5 px-1">
                            <span class="truncate text-xs font-medium text-gray-700 dark:text-gray-300">{{ group.displayName }}</span>
                            <span v-if="group.role === 'salesperson'" class="text-[10px] font-medium text-blue-600 dark:text-blue-400">You</span>
                        </div>
                        <div
                            :class="[
                                'inline-block max-w-full rounded-lg border p-3',
                                group.role === 'salesperson' ? 'border-blue-50 bg-blue-50 text-left dark:border-blue-900/20 dark:bg-blue-900/20' : '',
                                group.role === 'customer' || group.role === 'unknown'
                                    ? 'border-gray-200 bg-gray-100 text-left dark:border-gray-700 dark:bg-gray-700'
                                    : '',
                                group.role === 'bot' || group.role === 'system'
                                    ? 'border-gray-200 bg-gray-50 text-center text-sm dark:border-gray-700 dark:bg-gray-800'
                                    : '',
                                isPartial(group) ? 'border-dashed opacity-75' : '',
                            ]"
                        >
                            <div class="min-w-0">
                                <p class="break-words whitespace-pre-wrap">{{ group.messages.map((msg) => msg.text).join(' ') }}</p>
                            </div>
                            <span class="mt-2 block text-xs text-gray-500 dark:text-gray-400">
                                {{ formatTranscriptTimestamp(group) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';
import type { SalesRole, TranscriptGroup } from '@/types/realtimeAgent';
import { formatTranscriptTimestamp } from '@/types/realtimeAgent';
import { computed, nextTick, ref, watch } from 'vue';

// Store
const realtimeStore = useRealtimeAgentStore();

// Refs
const transcriptContainer = ref<HTMLElement>();

// Computed
const transcriptGroups = computed(() => realtimeStore.transcriptGroups);
const hasRecallGroups = computed(() => transcriptGroups.value.some((group) => group.sourceStream === 'recall'));
const namedRecallParticipants = computed(() => {
    const participants = new Map<number, { id: number; displayName: string; role: SalesRole }>();

    for (const group of transcriptGroups.value) {
        if (group.sourceStream === 'recall' && group.participantId !== undefined && group.displayName && group.role !== 'system') {
            participants.set(group.participantId, {
                id: group.participantId,
                displayName: group.displayName,
                role: group.role,
            });
        }
    }

    return [...participants.values()];
});

// Watch for new messages to auto-scroll
watch(
    () => transcriptGroups.value.length,
    async () => {
        await nextTick();
        if (transcriptContainer.value) {
            // Since we're using flex-col-reverse, scrollTop = 0 is the bottom
            transcriptContainer.value.scrollTop = 0;
        }
    },
);

const isPartial = (group: TranscriptGroup) => group.messages.some((message) => message.status === 'partial');

const transcriptAriaLabel = (group: TranscriptGroup) => {
    if (group.role === 'system') {
        return 'System message';
    }

    const speaker =
        group.sourceStream === 'recall'
            ? group.displayName || 'Participant'
            : group.role === 'salesperson'
              ? 'You'
              : group.role === 'customer'
                ? 'Customer'
                : group.role === 'bot'
                  ? 'Bot'
                  : 'Participant';
    const source = group.sourceStream === 'recall' ? 'Recall ' : '';
    const state = isPartial(group) ? 'transcript in progress' : 'final transcript';

    return `${speaker}, ${source}${state}`;
};

const legendDotClass = (role: SalesRole) => {
    if (role === 'salesperson') return 'bg-blue-500';
    if (role === 'bot') return 'bg-gray-400 dark:bg-gray-500';
    if (role === 'unknown') return 'bg-amber-400';
    return 'bg-green-500';
};
</script>
