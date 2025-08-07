<template>
    <div v-if="isRecording" class="flex items-center gap-2">
        <!-- Recording dot animation -->
        <div class="relative">
            <div class="h-2 w-2 rounded-full bg-red-500 animate-pulse"></div>
            <div class="absolute inset-0 h-2 w-2 rounded-full bg-red-500 animate-ping"></div>
        </div>
        
        <!-- Recording timer -->
        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
            REC {{ formattedDuration }}
        </span>
        
        <!-- File size (optional) -->
        <span v-if="showFileSize && fileSize > 0" class="text-xs text-gray-600 dark:text-gray-400">
            ({{ formatFileSize(fileSize) }})
        </span>
        
        <!-- Pause/Resume button -->
        <button
            v-if="showControls"
            @click="togglePause"
            class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            :title="isPaused ? 'Resume recording' : 'Pause recording'"
        >
            <svg v-if="!isPaused" class="h-3 w-3 text-gray-600 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <svg v-else class="h-3 w-3 text-gray-600 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </button>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';

interface Props {
    isRecording: boolean;
    isPaused?: boolean;
    duration?: number; // in milliseconds
    fileSize?: number; // in bytes
    showFileSize?: boolean;
    showControls?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isPaused: false,
    duration: 0,
    fileSize: 0,
    showFileSize: false,
    showControls: false
});

const emit = defineEmits<{
    'toggle-pause': [];
}>();

// Local duration tracking for smooth updates
const localDuration = ref(props.duration);
let intervalId: NodeJS.Timeout | null = null;

// Format duration as MM:SS
const formattedDuration = computed(() => {
    const totalSeconds = Math.floor(localDuration.value / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
});

// Start/stop duration counter
const startDurationCounter = () => {
    if (intervalId) {
        clearInterval(intervalId);
    }
    
    intervalId = setInterval(() => {
        if (props.isRecording && !props.isPaused) {
            localDuration.value += 1000;
        }
    }, 1000);
};

const stopDurationCounter = () => {
    if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
    }
};

// Format file size
const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

// Toggle pause
const togglePause = () => {
    emit('toggle-pause');
};

// Watch for recording state changes
onMounted(() => {
    if (props.isRecording) {
        startDurationCounter();
    }
});

onUnmounted(() => {
    stopDurationCounter();
});

// Update local duration when prop changes
watch(() => props.duration, (newDuration) => {
    localDuration.value = newDuration;
});

// Start/stop counter when recording state changes
watch(() => props.isRecording, (isRecording) => {
    if (isRecording) {
        startDurationCounter();
    } else {
        stopDurationCounter();
        localDuration.value = 0;
    }
});
</script>

<style scoped>
@keyframes ping {
    75%, 100% {
        transform: scale(2);
        opacity: 0;
    }
}

.animate-ping {
    animation: ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}
</style>