<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="presentation">
        <section
            class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-700 dark:bg-gray-900"
            role="dialog"
            aria-modal="true"
            aria-labelledby="participant-role-title"
        >
            <h2 id="participant-role-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">Which participant are you?</h2>

            <div class="mt-4 divide-y divide-gray-200 dark:divide-gray-700">
                <div v-for="participant in humanParticipants" :key="participant.id" class="flex min-w-0 items-center gap-3 py-3">
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-200">{{ participant.displayName }}</span>
                    <button
                        type="button"
                        class="shrink-0 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('assign', participant.id)"
                    >
                        This is me
                    </button>
                </div>
            </div>

            <p v-if="error" class="mt-3 text-sm text-red-700 dark:text-red-400" role="alert">{{ error }}</p>
        </section>
    </div>
</template>

<script setup lang="ts">
import type { MeetingParticipant } from '@/types/meetingCapture';
import { computed } from 'vue';

const props = defineProps<{
    open: boolean;
    participants: MeetingParticipant[];
    busy: boolean;
    error?: string | null;
}>();

const emit = defineEmits<{
    assign: [participantId: number];
}>();

const humanParticipants = computed(() => props.participants.filter((participant) => !participant.isBot));
</script>
