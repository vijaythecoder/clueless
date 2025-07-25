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

            <!-- Divider -->
            <div class="h-4 w-px bg-gray-300 dark:bg-gray-600"></div>

            <!-- Screen Protection Toggle -->
            <ScreenProtectionToggle />

            <!-- Overlay Mode Toggle -->
            <OverlayModeToggle v-if="isOverlaySupported" />

            <!-- Actions -->
            <button
                @click="handleDashboardClick"
                :disabled="isActive"
                class="text-xs font-medium text-gray-600 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                :class="{ 'cursor-not-allowed opacity-50': isActive }"
            >
                Call History
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
import { computed } from 'vue';
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

// Computed
const isActive = computed(() => realtimeStore.isActive);
const showMobileMenu = computed(() => settingsStore.showMobileMenu);
const isOverlaySupported = computed(() => settingsStore.isOverlaySupported);

// Methods
const toggleMobileMenu = () => {
    settingsStore.toggleMobileMenu();
};

const handleDashboardClick = () => {
    emit('dashboardClick');
};

const toggleSession = () => {
    emit('toggleSession');
};
</script>