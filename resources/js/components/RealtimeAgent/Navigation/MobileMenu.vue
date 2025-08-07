<template>
    <div
        v-if="showMobileMenu"
        class="animate-fadeIn fixed top-10 right-0 left-0 z-[100] max-h-[80vh] overflow-y-auto border-b border-gray-200 bg-white shadow-lg md:hidden dark:border-gray-700 dark:bg-gray-900"
        @click.stop
    >
        <div class="space-y-3 px-4 py-3">
            <!-- Coach Selector in Mobile Menu -->
            <div class="border-b border-gray-100 pb-3 dark:border-gray-800">
                <button
                    @click.stop="toggleCoachDropdown"
                    :disabled="isActive"
                    class="flex w-full items-center justify-between text-xs text-gray-600 dark:text-gray-400"
                    :class="{ 'cursor-not-allowed opacity-50': isActive }"
                    type="button"
                >
                    <span>
                        Coach: 
                        <span class="font-medium text-gray-800 dark:text-gray-200">
                            {{ selectedTemplate?.name || 'Select' }}
                        </span>
                    </span>
                    <svg
                        class="h-3 w-3 transition-transform"
                        :class="{ 'rotate-180': showCoachDropdown }"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <!-- Coach Dropdown inside Mobile Menu -->
                <div v-if="showCoachDropdown" class="mt-2 rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                    <!-- Search Input -->
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search templates..."
                        class="mb-2 w-full rounded border border-gray-200 bg-white px-2 py-1 text-xs focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                        @click.stop
                    />
                    <div class="max-h-36 overflow-y-auto">
                        <div v-if="filteredTemplates.length === 0" class="p-2 text-center text-xs text-gray-600 dark:text-gray-400">
                            No templates found
                        </div>
                        <button
                            v-for="template in filteredTemplates"
                            :key="template.id"
                            @click.stop="selectTemplate(template)"
                            class="w-full cursor-pointer rounded px-2 py-1.5 text-left transition-colors hover:bg-white dark:hover:bg-gray-700"
                            :class="{ 'bg-blue-100': selectedTemplate?.id === template.id }"
                        >
                            <div class="pointer-events-none flex items-center gap-2">
                                <span class="text-xs">{{ getIconEmoji(template.icon) }}</span>
                                <p class="text-xs text-gray-900 dark:text-gray-100">{{ template.name }}</p>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Connection Status -->
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-600 dark:text-gray-400">Connection</span>
                <ConnectionStatus />
            </div>

            <!-- Screen Protection -->
            <button
                @click="toggleProtection"
                class="flex w-full items-center justify-between text-xs"
                :class="[
                    isProtectionSupported
                        ? isProtectionEnabled
                            ? 'text-green-600 dark:text-green-400'
                            : 'text-gray-600 dark:text-gray-400'
                        : 'text-orange-600 dark:text-orange-400',
                ]"
            >
                <span>Screen Protection</span>
                <span class="font-medium">
                    {{ protectionStatusText }}
                </span>
            </button>

            <!-- Overlay Mode -->
            <button
                v-if="isOverlaySupported"
                @click="toggleOverlay"
                class="flex w-full items-center justify-between text-xs"
                :class="[
                    isOverlayMode 
                        ? 'text-blue-600 dark:text-blue-400' 
                        : 'text-gray-600 dark:text-gray-400'
                ]"
            >
                <span>Overlay Mode</span>
                <span class="font-medium">{{ isOverlayMode ? 'ON' : 'OFF' }}</span>
            </button>

            <!-- Dashboard Link -->
            <button
                @click="handleDashboardClick"
                :disabled="isActive"
                class="w-full border-t border-gray-100 pt-3 text-left text-xs text-gray-600 dark:border-gray-800 dark:text-gray-400"
                :class="{ 'cursor-not-allowed opacity-50': isActive }"
            >
                Go to Dashboard →
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';
import { useSettingsStore } from '@/stores/settings';
import ConnectionStatus from './ConnectionStatus.vue';

// Emit events
const emit = defineEmits<{
    dashboardClick: [];
}>();

// Stores
const realtimeStore = useRealtimeAgentStore();
const settingsStore = useSettingsStore();

// Computed
const showMobileMenu = computed(() => settingsStore.showMobileMenu);
const showCoachDropdown = computed(() => settingsStore.showCoachDropdown);
const isActive = computed(() => realtimeStore.isActive);
const selectedTemplate = computed(() => realtimeStore.selectedTemplate);
const templates = computed(() => realtimeStore.templates);
const searchQuery = computed({
    get: () => settingsStore.templateSearchQuery,
    set: (value) => settingsStore.setTemplateSearchQuery(value)
});
const isProtectionEnabled = computed(() => settingsStore.isProtectionEnabled);
const isProtectionSupported = computed(() => settingsStore.isProtectionSupported);
const protectionStatusText = computed(() => settingsStore.protectionStatusText);
const isOverlayMode = computed(() => settingsStore.isOverlayMode);
const isOverlaySupported = computed(() => settingsStore.isOverlaySupported);

const filteredTemplates = computed(() => {
    if (!searchQuery.value) return templates.value;
    
    const query = searchQuery.value.toLowerCase();
    return templates.value.filter(template => 
        template.name.toLowerCase().includes(query)
    );
});

// Methods
const toggleCoachDropdown = () => {
    settingsStore.showCoachDropdown = !settingsStore.showCoachDropdown;
};

const selectTemplate = (template: any) => {
    realtimeStore.setSelectedTemplate(template);
    settingsStore.closeAllDropdowns();
    settingsStore.setTemplateSearchQuery('');
};

const toggleProtection = () => {
    settingsStore.toggleProtection();
    settingsStore.closeAllDropdowns();
};

const toggleOverlay = () => {
    settingsStore.toggleOverlayMode();
    settingsStore.closeAllDropdowns();
};

const handleDashboardClick = () => {
    emit('dashboardClick');
    settingsStore.closeAllDropdowns();
};

const handleSettingsClick = () => {
    router.visit('/settings/recording');
    settingsStore.closeAllDropdowns();
};


const getIconEmoji = (icon?: string) => {
    if (!icon) return '📋';
    
    const iconMap: Record<string, string> = {
        'discovery': '🔍',
        'demo': '🎯',
        'negotiation': '💰',
        'support': '🛟',
        'onboarding': '🚀',
        'feedback': '💭',
        'renewal': '🔄',
        'upsell': '📈',
        'default': '📋'
    };
    
    return iconMap[icon] || iconMap.default;
};
</script>