<template>
    <div
        class="title-bar flex h-10 flex-shrink-0 items-center border-b border-gray-200 bg-gray-50 px-3 md:px-6 dark:border-gray-700 dark:bg-gray-900"
        style="-webkit-app-region: drag"
    >
        <!-- Left: App Title (with space for macOS controls) -->
        <div class="flex-1 pl-16 md:pl-20">
            <span class="text-xs font-semibold text-gray-800 md:text-sm dark:text-gray-200">
                {{ title }}
            </span>
        </div>

        <!-- Mobile Menu Button (visible on small screens) -->
        <button
            @click="toggleMobileMenu"
            class="p-1.5 text-gray-600 transition-colors hover:text-gray-900 md:hidden dark:text-gray-400 dark:hover:text-gray-100"
            style="-webkit-app-region: no-drag"
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path 
                    v-if="!showMobileMenu" 
                    stroke-linecap="round" 
                    stroke-linejoin="round" 
                    stroke-width="2" 
                    d="M4 6h16M4 12h16M4 18h16" 
                />
                <path 
                    v-else 
                    stroke-linecap="round" 
                    stroke-linejoin="round" 
                    stroke-width="2" 
                    d="M6 18L18 6M6 6l12 12" 
                />
            </svg>
        </button>

        <!-- Right Side: All Controls (hidden on mobile, visible on desktop) -->
        <div class="hidden items-center gap-4 md:flex lg:gap-6" style="-webkit-app-region: no-drag">
            <!-- Coach Selector -->
            <CoachSelector />

            <!-- Connection Status -->
            <ConnectionStatus />
            
            <!-- Recording Indicator Slot -->
            <slot name="recording-indicator" />

            <!-- Divider -->
            <div class="h-4 w-px bg-gray-300 dark:bg-gray-600"></div>

            <!-- Screen Protection Toggle -->
            <ScreenProtectionToggle />

            <!-- Microphone Permission Toggle (only show when not authorized) -->
            <button
                v-if="micPermissionStatus !== 'authorized'"
                @click="requestMicrophonePermission"
                :disabled="micPermissionLoading"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                :class="[
                    micPermissionStatus === 'denied'
                        ? 'bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30'
                        : 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-400 dark:hover:bg-yellow-900/30',
                    { 'opacity-50 cursor-not-allowed': micPermissionLoading }
                ]"
                :title="micPermissionTooltip"
            >
                <!-- Microphone Icon -->
                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7 4a3 3 0 016 0v4a3 3 0 11-6 0V4zm4 10.93A7.001 7.001 0 0017 8a1 1 0 10-2 0A5 5 0 015 8a1 1 0 00-2 0 7.001 7.001 0 006 6.93V17H6a1 1 0 100 2h8a1 1 0 100-2h-3v-2.07z" clip-rule="evenodd" />
                </svg>
                {{ micPermissionText }}
            </button>

            <!-- Screen Capture Permission Toggle (only show when not authorized) -->
            <button
                v-if="screenPermissionStatus !== 'authorized'"
                @click="requestScreenPermission"
                :disabled="screenPermissionLoading"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                :class="[
                    screenPermissionStatus === 'denied'
                        ? 'bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30'
                        : 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-400 dark:hover:bg-yellow-900/30',
                    { 'opacity-50 cursor-not-allowed': screenPermissionLoading }
                ]"
                :title="screenPermissionTooltip"
            >
                <!-- Screen Icon -->
                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 011 1v8a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm2 4a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1zm0 3a1 1 0 011-1h4a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                </svg>
                {{ screenPermissionText }}
            </button>

            <!-- Overlay Mode Toggle -->
            <OverlayModeToggle v-if="isOverlaySupported" />

            <!-- Actions -->
            <button
                @click="handleDashboardClick"
                :disabled="isActive"
                class="text-xs font-medium text-gray-600 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                :class="{ 'cursor-not-allowed opacity-50': isActive }"
            >
                Dashboard
            </button>
            
            <button
                @click="toggleSession"
                class="rounded-md px-4 py-1.5 text-xs font-medium transition-colors"
                :class="[
                    isActive 
                        ? 'bg-red-500 text-white hover:bg-red-600' 
                        : 'bg-blue-500 text-white hover:bg-blue-600'
                ]"
            >
                {{ isActive ? 'End Call' : 'Start Call' }}
            </button>
        </div>

        <!-- Mobile-visible Start/End Call button -->
        <button
            @click="toggleSession"
            class="ml-2 rounded-md px-3 py-1 text-xs font-medium transition-colors md:hidden"
            style="-webkit-app-region: no-drag"
            :class="[
                isActive 
                    ? 'bg-red-500 text-white hover:bg-red-600' 
                    : 'bg-blue-500 text-white hover:bg-blue-600'
            ]"
        >
            {{ isActive ? 'End' : 'Start' }}
        </button>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';
import { useSettingsStore } from '@/stores/settings';
import CoachSelector from './CoachSelector.vue';
import ConnectionStatus from './ConnectionStatus.vue';
import ScreenProtectionToggle from './ScreenProtectionToggle.vue';
import OverlayModeToggle from './OverlayModeToggle.vue';

// Props
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const props = withDefaults(defineProps<{
    title?: string;
}>(), {
    title: 'Clueless'
});

// Emit events
const emit = defineEmits<{
    dashboardClick: [];
    toggleSession: [];
}>();

// Stores
const realtimeStore = useRealtimeAgentStore();
const settingsStore = useSettingsStore();

// Microphone permission state
const micPermissionStatus = ref<string>('not determined');
const micPermissionLoading = ref<boolean>(false);

// Screen capture permission state
const screenPermissionStatus = ref<string>('not determined');
const screenPermissionLoading = ref<boolean>(false);

// Computed
const isActive = computed(() => realtimeStore.isActive);
const showMobileMenu = computed(() => settingsStore.showMobileMenu);
const isOverlaySupported = computed(() => settingsStore.isOverlaySupported);

// Microphone permission computed properties
const micPermissionText = computed(() => {
    switch (micPermissionStatus.value) {
        case 'authorized': return 'Mic OK';
        case 'denied': return 'Mic Denied';
        case 'not determined': return 'Request Mic';
        case 'restricted': return 'Mic Restricted';
        default: return 'Check Mic';
    }
});

const micPermissionTooltip = computed(() => {
    switch (micPermissionStatus.value) {
        case 'authorized': return 'Microphone access granted';
        case 'denied': return 'Microphone access denied - check System Preferences';
        case 'not determined': return 'Click to request microphone permission';
        case 'restricted': return 'Microphone access restricted by system policy';
        default: return 'Click to check microphone permission status';
    }
});

// Screen capture permission computed properties
const screenPermissionText = computed(() => {
    switch (screenPermissionStatus.value) {
        case 'authorized': return 'Screen OK';
        case 'denied': return 'Screen Denied';
        case 'not determined': return 'Request Screen';
        case 'restricted': return 'Screen Restricted';
        default: return 'Check Screen';
    }
});

const screenPermissionTooltip = computed(() => {
    switch (screenPermissionStatus.value) {
        case 'authorized': return 'Screen capture access granted';
        case 'denied': return 'Screen capture access denied - check System Preferences';
        case 'not determined': return 'Click to request screen capture permission';
        case 'restricted': return 'Screen capture access restricted by system policy';
        default: return 'Click to check screen capture permission status';
    }
});

// Methods
const toggleMobileMenu = () => {
    settingsStore.toggleMobileMenu();
};

const handleDashboardClick = () => {
    emit('dashboardClick');
};

const handleSettingsClick = () => {
    router.visit('/settings/recording');
};

const toggleSession = () => {
    emit('toggleSession');
};

// Microphone permission methods
const checkMicrophonePermission = async () => {
    try {
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.checkPermission('microphone');
            if (result.success) {
                micPermissionStatus.value = result.status || 'not determined';
            }
        }
    } catch (error) {
        console.error('Error checking microphone permission:', error);
    }
};

const requestMicrophonePermission = async () => {
    if (micPermissionLoading.value) return;
    
    try {
        micPermissionLoading.value = true;
        
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.requestPermission('microphone');
            if (result.success) {
                micPermissionStatus.value = result.status || 'not determined';
            } else {
                console.error('Failed to request microphone permission:', result.error);
            }
        } else {
            console.warn('macPermissions API not available');
        }
    } catch (error) {
        console.error('Error requesting microphone permission:', error);
    } finally {
        micPermissionLoading.value = false;
    }
};

// Screen capture permission methods
const checkScreenPermission = async () => {
    try {
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.checkPermission('screen');
            if (result.success) {
                screenPermissionStatus.value = result.status || 'not determined';
            }
        }
    } catch (error) {
        console.error('Error checking screen capture permission:', error);
    }
};


const requestScreenPermission = async () => {
    if (screenPermissionLoading.value) return;
    
    try {
        screenPermissionLoading.value = true;
        
        if ((window as any).macPermissions) {
            const result = await (window as any).macPermissions.requestPermission('screen');
            if (result.success) {
                screenPermissionStatus.value = result.status || 'not determined';
            } else {
                console.error('Failed to request screen capture permission:', result.error);
            }
        } else {
            console.warn('macPermissions API not available');
        }
    } catch (error) {
        console.error('Error requesting screen capture permission:', error);
    } finally {
        screenPermissionLoading.value = false;
    }
};

// Initialize permissions status
onMounted(() => {
    checkMicrophonePermission();
    checkScreenPermission();
});
</script>