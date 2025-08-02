<template>
    <div class="flex items-center gap-1.5">
        <div class="relative">
            <div
                :class="[
                    'h-2 w-2 rounded-full transition-all duration-300',
                    connectionStatus === 'connected'
                        ? 'bg-green-500'
                        : connectionStatus === 'connecting'
                          ? 'bg-yellow-500'
                          : 'bg-gray-400',
                ]"
            ></div>
            <!-- Pulse animation for connecting state -->
            <div
                v-if="connectionStatus === 'connecting'"
                class="absolute inset-0 h-2 w-2 animate-ping rounded-full bg-yellow-500 opacity-75"
            ></div>
        </div>
        <span class="text-xs font-medium capitalize">
            <span v-if="connectionStatus === 'connected'" class="text-green-600 dark:text-green-400">
                Connected
            </span>
            <span v-else-if="connectionStatus === 'connecting'" class="text-yellow-600 dark:text-yellow-400">
                Connecting...
            </span>
            <span v-else class="text-gray-500 dark:text-gray-400">
                Not Connected
            </span>
        </span>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';

// Store
const realtimeStore = useRealtimeAgentStore();

// Computed
const connectionStatus = computed(() => realtimeStore.connectionStatus);
</script>