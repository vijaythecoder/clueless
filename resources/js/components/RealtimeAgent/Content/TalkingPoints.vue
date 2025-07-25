<template>
    <div
        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
        v-if="selectedTemplate?.talking_points?.length > 0"
    >
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Talking Points</h3>
            <div class="flex items-center gap-2">
                <div class="text-xs text-gray-600 dark:text-gray-400">
                    {{ coveredPoints.length }}/{{ selectedTemplate.talking_points.length }} covered
                </div>
                <div class="h-1.5 w-16 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div class="h-full bg-blue-500 transition-all duration-300 ease-out" :style="{ width: `${talkingPointsProgress}%` }"></div>
                </div>
            </div>
        </div>

        <div
            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent max-h-48 space-y-2 overflow-y-auto"
        >
            <div v-for="(point, index) in selectedTemplate.talking_points" :key="index" class="flex items-start gap-2">
                <input
                    type="checkbox"
                    :id="`point-${index}`"
                    :checked="coveredPoints.includes(index)"
                    @change="togglePoint(index)"
                    class="mt-0.5 cursor-pointer rounded border-gray-200 dark:border-gray-700"
                />
                <label
                    :for="`point-${index}`"
                    class="flex-1 cursor-pointer text-xs text-gray-900 dark:text-gray-100"
                    :class="{ 'text-gray-600 line-through dark:text-gray-400': coveredPoints.includes(index) }"
                >
                    {{ point }}
                </label>
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
const selectedTemplate = computed(() => realtimeStore.selectedTemplate);
const coveredPoints = computed(() => realtimeStore.coveredPoints);
const talkingPointsProgress = computed(() => realtimeStore.talkingPointsProgress);

// Methods
const togglePoint = (index: number) => {
    realtimeStore.toggleTalkingPoint(index);
};
</script>