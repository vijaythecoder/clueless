<template>
    <div class="rounded-lg bg-gradient-to-r from-gray-50 to-gray-100 p-4 shadow-sm dark:from-gray-800 dark:to-gray-900">
        <div class="space-y-3">
            <!-- Top row: Title and metadata -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/50">
                        <svg class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M18 3a1 1 0 00-1.196-.98l-10 2A1 1 0 006 5v9.114A4.369 4.369 0 005 14c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V7.82l8-1.6v5.894A4.37 4.37 0 0015 12c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">Conversation Recording</h3>
                        <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span v-if="duration">{{ formatDuration(duration) }}</span>
                            <span v-if="size">{{ formatFileSize(size) }}</span>
                        </div>
                    </div>
                </div>
                
                <!-- Download button -->
                <button
                    @click="downloadAudio"
                    class="rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                    title="Download recording"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                </button>
            </div>

            <!-- Progress bar -->
            <div class="relative">
                <div class="flex items-center gap-3">
                    <span class="min-w-[40px] text-xs font-medium text-gray-600 dark:text-gray-400">
                        {{ formatTime(currentTime) }}
                    </span>
                    <div class="relative flex-1">
                        <div 
                            class="h-1.5 w-full cursor-pointer rounded-full bg-gray-300 dark:bg-gray-700"
                            @click="seek"
                            ref="progressBar"
                        >
                            <div 
                                class="h-1.5 rounded-full bg-blue-500 transition-all dark:bg-blue-400"
                                :style="{ width: `${progress}%` }"
                            ></div>
                            <div 
                                class="absolute -top-1 h-3.5 w-3.5 rounded-full bg-blue-500 shadow-md transition-all dark:bg-blue-400"
                                :style="{ left: `calc(${progress}% - 7px)` }"
                            ></div>
                        </div>
                    </div>
                    <span class="min-w-[40px] text-right text-xs font-medium text-gray-600 dark:text-gray-400">
                        {{ formatTime(totalDuration) }}
                    </span>
                </div>
            </div>

            <!-- Controls -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <!-- Play/Pause button -->
                    <button
                        @click="togglePlayPause"
                        class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-500 text-white shadow-lg transition-all hover:bg-blue-600 hover:shadow-xl active:scale-95 dark:bg-blue-600 dark:hover:bg-blue-700"
                    >
                        <svg v-if="!isPlaying" class="h-6 w-6" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M6.3 2.841A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z" />
                        </svg>
                        <svg v-else class="h-6 w-6" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M5.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75A.75.75 0 007.25 3h-1.5zM12.75 3a.75.75 0 00-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 00.75-.75V3.75a.75.75 0 00-.75-.75h-1.5z" />
                        </svg>
                    </button>

                    <!-- Skip backward -->
                    <button
                        @click="skip(-10)"
                        class="flex h-10 w-10 items-center justify-center rounded-full text-gray-600 transition-all hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-700"
                        title="Rewind 10 seconds"
                    >
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M8.445 14.832A1 1 0 0010 14v-2.798l5.445 3.63A1 1 0 0017 14V6a1 1 0 00-1.555-.832L10 8.798V6a1 1 0 00-1.555-.832l-6 4a1 1 0 000 1.664l6 4z" />
                        </svg>
                    </button>

                    <!-- Skip forward -->
                    <button
                        @click="skip(10)"
                        class="flex h-10 w-10 items-center justify-center rounded-full text-gray-600 transition-all hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-700"
                        title="Forward 10 seconds"
                    >
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M4.555 5.168A1 1 0 003 6v8a1 1 0 001.555.832L10 11.202V14a1 1 0 001.555.832l6-4a1 1 0 000-1.664l-6-4A1 1 0 0010 6v2.798l-5.445-3.63z" />
                        </svg>
                    </button>
                </div>

                <!-- Volume and Speed controls -->
                <div class="flex items-center gap-4">
                    <!-- Speed control -->
                    <div class="relative">
                        <button
                            @click="showSpeedMenu = !showSpeedMenu"
                            class="flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-700"
                        >
                            {{ playbackRate }}x
                        </button>
                        
                        <!-- Speed menu -->
                        <div
                            v-if="showSpeedMenu"
                            class="absolute bottom-full right-0 mb-2 rounded-lg bg-white py-1 shadow-lg dark:bg-gray-800"
                        >
                            <button
                                v-for="speed in [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]"
                                :key="speed"
                                @click="setPlaybackRate(speed)"
                                :class="[
                                    'block w-full px-4 py-1.5 text-left text-sm transition-colors',
                                    playbackRate === speed
                                        ? 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400'
                                        : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'
                                ]"
                            >
                                {{ speed }}x
                            </button>
                        </div>
                    </div>

                    <!-- Volume control -->
                    <div class="flex items-center gap-2">
                        <button
                            @click="toggleMute"
                            class="text-gray-600 transition-colors hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"
                        >
                            <svg v-if="isMuted || volume === 0" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM12.293 7.293a1 1 0 011.414 0L15 8.586l1.293-1.293a1 1 0 111.414 1.414L16.414 10l1.293 1.293a1 1 0 01-1.414 1.414L15 11.414l-1.293 1.293a1 1 0 01-1.414-1.414L13.586 10l-1.293-1.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                            <svg v-else-if="volume < 0.5" class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                            <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217zM14.657 2.929a1 1 0 011.414 0A9.972 9.972 0 0119 10a9.972 9.972 0 01-2.929 7.071 1 1 0 01-1.414-1.414A7.971 7.971 0 0017 10c0-2.21-.894-4.208-2.343-5.657a1 1 0 010-1.414z" clip-rule="evenodd" />
                                <path d="M11.829 4.515a1 1 0 011.414 0 6 6 0 010 8.485 1 1 0 01-1.414-1.414 4 4 0 000-5.657 1 1 0 010-1.414z" />
                            </svg>
                        </button>
                        <input
                            type="range"
                            min="0"
                            max="1"
                            step="0.1"
                            v-model="volume"
                            @input="setVolume"
                            class="h-1 w-20 cursor-pointer appearance-none rounded-lg bg-gray-300 dark:bg-gray-700"
                        >
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden audio element -->
        <audio
            ref="audioElement"
            :src="src"
            @loadedmetadata="onLoadedMetadata"
            @timeupdate="onTimeUpdate"
            @ended="onEnded"
            preload="metadata"
        ></audio>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';

interface Props {
    src: string;
    duration?: number;
    size?: number;
}

const props = defineProps<Props>();

// State
const audioElement = ref<HTMLAudioElement | null>(null);
const progressBar = ref<HTMLDivElement | null>(null);
const isPlaying = ref(false);
const currentTime = ref(0);
const totalDuration = ref(0);
const volume = ref(1);
const isMuted = ref(false);
const playbackRate = ref(1);
const showSpeedMenu = ref(false);

// Computed
const progress = computed(() => {
    if (!totalDuration.value) return 0;
    return (currentTime.value / totalDuration.value) * 100;
});

// Methods
const formatTime = (seconds: number): string => {
    if (!seconds || isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const formatDuration = (seconds: number): string => {
    if (!seconds) return '';
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return secs > 0 ? `${mins}m ${secs}s` : `${mins}m`;
};

const formatFileSize = (bytes: number): string => {
    if (!bytes) return '';
    const mb = bytes / (1024 * 1024);
    return `${mb.toFixed(1)} MB`;
};

const togglePlayPause = () => {
    if (!audioElement.value) return;
    
    if (isPlaying.value) {
        audioElement.value.pause();
    } else {
        audioElement.value.play();
    }
    isPlaying.value = !isPlaying.value;
};

const seek = (event: MouseEvent) => {
    if (!audioElement.value || !progressBar.value) return;
    
    const rect = progressBar.value.getBoundingClientRect();
    const percent = (event.clientX - rect.left) / rect.width;
    const newTime = percent * totalDuration.value;
    
    audioElement.value.currentTime = newTime;
    currentTime.value = newTime;
};

const skip = (seconds: number) => {
    if (!audioElement.value) return;
    
    const newTime = Math.max(0, Math.min(totalDuration.value, audioElement.value.currentTime + seconds));
    audioElement.value.currentTime = newTime;
    currentTime.value = newTime;
};

const setPlaybackRate = (rate: number) => {
    if (!audioElement.value) return;
    
    playbackRate.value = rate;
    audioElement.value.playbackRate = rate;
    showSpeedMenu.value = false;
};

const toggleMute = () => {
    if (!audioElement.value) return;
    
    isMuted.value = !isMuted.value;
    audioElement.value.muted = isMuted.value;
};

const setVolume = () => {
    if (!audioElement.value) return;
    
    audioElement.value.volume = volume.value;
    if (volume.value > 0 && isMuted.value) {
        isMuted.value = false;
        audioElement.value.muted = false;
    }
};

const downloadAudio = () => {
    const a = document.createElement('a');
    a.href = props.src;
    a.download = `recording_${new Date().toISOString()}.wav`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
};

// Event handlers
const onLoadedMetadata = () => {
    if (!audioElement.value) return;
    totalDuration.value = audioElement.value.duration;
};

const onTimeUpdate = () => {
    if (!audioElement.value) return;
    currentTime.value = audioElement.value.currentTime;
};

const onEnded = () => {
    isPlaying.value = false;
    currentTime.value = 0;
};

// Close speed menu when clicking outside
const handleClickOutside = (event: MouseEvent) => {
    const target = event.target as HTMLElement;
    if (!target.closest('.relative')) {
        showSpeedMenu.value = false;
    }
};

// Lifecycle
onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    if (audioElement.value) {
        audioElement.value.pause();
    }
});

// Watch for src changes
watch(() => props.src, () => {
    if (audioElement.value) {
        audioElement.value.load();
        isPlaying.value = false;
        currentTime.value = 0;
    }
});
</script>

<style scoped>
/* Custom range input styles */
input[type="range"] {
    -webkit-appearance: none;
}

input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    background: #3b82f6;
    border-radius: 50%;
    cursor: pointer;
}

input[type="range"]::-moz-range-thumb {
    width: 12px;
    height: 12px;
    background: #3b82f6;
    border-radius: 50%;
    cursor: pointer;
    border: none;
}

/* Dark mode adjustments */
.dark input[type="range"]::-webkit-slider-thumb {
    background: #60a5fa;
}

.dark input[type="range"]::-moz-range-thumb {
    background: #60a5fa;
}
</style>