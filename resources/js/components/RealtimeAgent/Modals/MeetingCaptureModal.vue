<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="presentation">
        <section
            class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-700 dark:bg-gray-900"
            role="dialog"
            aria-modal="true"
            aria-labelledby="capture-mode-title"
        >
            <div class="flex items-center justify-between gap-3">
                <h2 id="capture-mode-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">Start capture</h2>
                <span
                    v-if="status"
                    class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                    aria-live="polite"
                >
                    {{ statusLabels[status] }}
                </span>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-2" role="radiogroup" aria-label="Capture mode">
                <button
                    type="button"
                    class="rounded-md border px-3 py-3 text-left text-sm transition-colors"
                    :class="
                        selectedMode === 'local'
                            ? 'border-blue-500 bg-blue-50 text-blue-900 dark:bg-blue-950/40 dark:text-blue-100'
                            : 'border-gray-200 text-gray-700 hover:border-gray-300 dark:border-gray-700 dark:text-gray-300'
                    "
                    :aria-checked="selectedMode === 'local'"
                    role="radio"
                    :disabled="busy"
                    @click="selectedMode = 'local'"
                >
                    <span class="block font-medium">Local</span>
                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Microphone and system audio</span>
                </button>
                <button
                    type="button"
                    class="rounded-md border px-3 py-3 text-left text-sm transition-colors"
                    :class="
                        selectedMode === 'recall'
                            ? 'border-blue-500 bg-blue-50 text-blue-900 dark:bg-blue-950/40 dark:text-blue-100'
                            : 'border-gray-200 text-gray-700 hover:border-gray-300 dark:border-gray-700 dark:text-gray-300'
                    "
                    :aria-checked="selectedMode === 'recall'"
                    role="radio"
                    :disabled="busy"
                    @click="selectedMode = 'recall'"
                >
                    <span class="block font-medium">Teams via Recall</span>
                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Named meeting participants</span>
                </button>
            </div>

            <label v-if="selectedMode === 'recall'" class="mt-4 block">
                <span class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Teams meeting URL</span>
                <input
                    v-model="meetingUrl"
                    type="url"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="https://teams.microsoft.com/..."
                    class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-blue-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                    :disabled="busy"
                    @keydown.enter.prevent="startSelectedMode"
                />
            </label>

            <p v-if="error" class="mt-3 text-sm text-red-700 dark:text-red-400" role="alert">{{ error }}</p>

            <div v-if="needsRecallSettings || localFallbackAvailable" class="mt-3 flex flex-wrap gap-2">
                <button
                    v-if="needsRecallSettings"
                    type="button"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    :disabled="busy"
                    @click="emit('openSettings')"
                >
                    Open Recall settings
                </button>
                <button
                    v-if="localFallbackAvailable"
                    type="button"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    :disabled="busy"
                    @click="emit('selectLocal')"
                >
                    Use Local capture
                </button>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    :disabled="busy"
                    @click="close"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!canStart"
                    @click="startSelectedMode"
                >
                    {{ busy ? 'Starting...' : 'Start' }}
                </button>
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import type { CaptureMode, MeetingCaptureStatus } from '@/types/meetingCapture';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    busy: boolean;
    error?: string | null;
    status?: MeetingCaptureStatus | null;
    localFallbackAvailable?: boolean;
    needsRecallSettings?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    startLocal: [];
    startRecall: [meetingUrl: string];
    selectLocal: [];
    openSettings: [];
}>();

const selectedMode = ref<CaptureMode | null>(null);
const meetingUrl = ref('');
const statusLabels: Record<MeetingCaptureStatus, string> = {
    creating: 'Creating',
    joining: 'Joining',
    waiting_room: 'Waiting room',
    active: 'Active',
    stopping: 'Stopping',
    ended: 'Ended',
    failed: 'Failed',
};

const canStart = computed(
    () => !props.busy && (selectedMode.value === 'local' || (selectedMode.value === 'recall' && meetingUrl.value.trim().length > 0)),
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            selectedMode.value = null;
            meetingUrl.value = '';
        }
    },
);

function startSelectedMode(): void {
    if (!canStart.value) {
        return;
    }

    if (selectedMode.value === 'local') {
        emit('startLocal');
        return;
    }

    if (selectedMode.value === 'recall') {
        const url = meetingUrl.value.trim();
        meetingUrl.value = '';
        emit('startRecall', url);
    }
}

function close(): void {
    selectedMode.value = null;
    meetingUrl.value = '';
    emit('close');
}
</script>
