<template>
    <div class="relative z-[100]" @click.stop>
        <button
            ref="buttonRef"
            @click.stop="toggleDropdown"
            :disabled="isActive"
            class="flex items-center gap-1.5 text-xs text-gray-600 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
            :class="{ 'cursor-not-allowed opacity-50': isActive }"
        >
            <span>Coach:</span>
            <span class="font-medium text-gray-800 dark:text-gray-200">
                {{ selectedTemplate?.name || 'Select' }}
            </span>
            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>

        <!-- Coach Dropdown Menu -->
        <Teleport to="body">
            <div
                v-if="showCoachDropdown"
                @click.stop
                data-dropdown
                :style="dropdownStyle"
                class="fixed z-[9999] flex max-h-96 w-full flex-col rounded-lg bg-gray-50 shadow-xl md:w-80 dark:bg-gray-900"
            >
            <!-- Search Input -->
            <div class="border-b border-gray-200 p-3 dark:border-gray-700">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search templates..."
                    class="w-full rounded border border-gray-200 bg-white px-3 py-1.5 text-sm transition-all focus:border-transparent focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                    @click.stop
                />
            </div>

            <!-- Template List -->
            <div
                class="scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent hover:scrollbar-thumb-gray-400 flex-1 overflow-y-auto"
            >
                <div v-if="filteredTemplates.length === 0" class="p-3 text-center text-xs text-gray-600 dark:text-gray-400">
                    No templates found
                </div>
                <div
                    v-for="template in filteredTemplates"
                    :key="template.id"
                    @click="selectTemplate(template)"
                    class="cursor-pointer px-3 py-1.5 transition-colors hover:bg-gray-50/50 dark:hover:bg-gray-700/50"
                    :class="{ 
                        'border-l-2 border-blue-500 bg-blue-50/50': selectedTemplate?.id === template.id 
                    }"
                >
                    <div class="flex items-center gap-2">
                        <span class="text-sm">{{ getIconEmoji(template.icon) }}</span>
                        <p class="text-xs text-gray-900 dark:text-gray-100">{{ template.name }}</p>
                    </div>
                </div>
            </div>
        </div>
        </Teleport>
    </div>
</template>

<script setup lang="ts">
import { computed, ref, onUnmounted, watch } from 'vue';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';
import { useSettingsStore } from '@/stores/settings';

// Stores
const realtimeStore = useRealtimeAgentStore();
const settingsStore = useSettingsStore();

// Refs
const buttonRef = ref<HTMLElement>();
const dropdownStyle = ref({});

// Computed
const isActive = computed(() => realtimeStore.isActive);
const selectedTemplate = computed(() => realtimeStore.selectedTemplate);
const templates = computed(() => realtimeStore.templates);
const showCoachDropdown = computed(() => settingsStore.showCoachDropdown);
const searchQuery = computed({
    get: () => settingsStore.templateSearchQuery,
    set: (value) => settingsStore.setTemplateSearchQuery(value)
});

const filteredTemplates = computed(() => {
    // Ensure templates is an array
    const templateArray = Array.isArray(templates.value) ? templates.value : [];
    
    if (!searchQuery.value) return templateArray;
    
    const query = searchQuery.value.toLowerCase();
    return templateArray.filter(template => 
        template.name.toLowerCase().includes(query)
    );
});

// Methods
const updateDropdownPosition = () => {
    if (!buttonRef.value || !showCoachDropdown.value) return;
    
    const rect = buttonRef.value.getBoundingClientRect();
    const isMobile = window.innerWidth < 768;
    
    // Calculate position
    const top = rect.bottom + 8; // 8px gap (mt-2)
    let left = rect.left;
    let width = rect.width;
    
    // On desktop, align to right edge and set fixed width
    if (!isMobile) {
        width = 320; // w-80 = 20rem = 320px
        left = rect.right - width;
        
        // Ensure dropdown doesn't go off-screen on the left
        if (left < 16) {
            left = 16; // Add some padding from edge
        }
    }
    
    // Ensure dropdown doesn't go off-screen on the right
    const maxRight = window.innerWidth - 16;
    if (left + width > maxRight) {
        left = maxRight - width;
    }
    
    dropdownStyle.value = {
        top: `${top}px`,
        left: `${left}px`,
        width: isMobile ? `${width}px` : `${width}px`,
    };
};

const toggleDropdown = () => {
    settingsStore.toggleCoachDropdown();
    if (!showCoachDropdown.value) {
        // Update position when opening
        setTimeout(updateDropdownPosition, 0);
    }
};

// Update position on window resize
const handleResize = () => {
    if (showCoachDropdown.value) {
        updateDropdownPosition();
    }
};

// Handle click outside
const handleClickOutside = (event: MouseEvent) => {
    const target = event.target as HTMLElement;
    if (!target.closest('[data-dropdown]') && !buttonRef.value?.contains(target)) {
        settingsStore.closeAllDropdowns();
    }
};

// Watch for dropdown visibility changes
const stopWatcher = watch(showCoachDropdown, (isVisible) => {
    if (isVisible) {
        updateDropdownPosition();
        window.addEventListener('resize', handleResize);
        window.addEventListener('scroll', updateDropdownPosition, true);
        // Add click outside handler
        setTimeout(() => {
            document.addEventListener('click', handleClickOutside);
        }, 0);
    } else {
        window.removeEventListener('resize', handleResize);
        window.removeEventListener('scroll', updateDropdownPosition, true);
        document.removeEventListener('click', handleClickOutside);
    }
});

onUnmounted(() => {
    stopWatcher();
    window.removeEventListener('resize', handleResize);
    window.removeEventListener('scroll', updateDropdownPosition, true);
    document.removeEventListener('click', handleClickOutside);
});

const selectTemplate = (template: any) => {
    realtimeStore.setSelectedTemplate(template);
    settingsStore.closeAllDropdowns();
    settingsStore.setTemplateSearchQuery('');
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