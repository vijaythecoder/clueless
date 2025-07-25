<template>
    <button
        @click="toggleProtection"
        class="flex items-center gap-1.5 text-xs transition-colors"
        :class="[
            isProtectionSupported
                ? isProtectionEnabled
                    ? 'text-green-600 hover:text-green-700 dark:text-green-400 dark:hover:text-green-300'
                    : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'
                : 'text-orange-600 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300',
        ]"
    >
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
                v-if="isProtectionEnabled"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
            />
            <path
                v-else
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
            />
        </svg>
        <span class="font-medium">
            {{ protectionStatusText }}
        </span>
    </button>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue';
import { useSettingsStore } from '@/stores/settings';
import { useScreenProtection } from '@/composables/useScreenProtection';

// Store
const settingsStore = useSettingsStore();

// Screen protection composable
const screenProtection = useScreenProtection();

// Computed
const isProtectionSupported = computed(() => screenProtection.isProtectionSupported.value);
const isProtectionEnabled = computed(() => screenProtection.isProtectionEnabled.value);
const protectionStatusText = computed(() => {
    if (!isProtectionSupported.value) return 'N/A';
    return isProtectionEnabled.value ? 'Protected' : 'Protect';
});

// Sync protection state with settings store
watch(screenProtection.isProtectionEnabled, (enabled) => {
    settingsStore.isProtectionEnabled = enabled;
});

watch(screenProtection.isProtectionSupported, (supported) => {
    settingsStore.setProtectionSupported(supported);
});

// Methods
const toggleProtection = () => {
    // Use the actual screen protection composable to toggle
    screenProtection.toggleProtection();
};
</script>