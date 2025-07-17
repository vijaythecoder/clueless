<template>
    <Head title="Settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <PageContainer>
            <div class="flex h-full flex-col">
                <!-- Header -->
                <div class="mb-6">
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Settings</h1>
                </div>

                <!-- Settings Content -->
                <div class="flex min-h-0 flex-1 gap-4">
                    <!-- Sidebar Navigation -->
                    <div class="w-64 flex-shrink-0">
                        <BaseCard noPadding>
                            <nav class="p-2">
                                <button
                                    v-for="item in navItems"
                                    :key="item.id"
                                    @click="handleNavClick(item)"
                                    :class="[
                                        'flex w-full items-center gap-3 rounded-lg px-4 py-3 text-left transition-all',
                                        activeSection === item.id
                                            ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-900/20 dark:text-blue-400'
                                            : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-800',
                                    ]"
                                >
                                    <component :is="item.icon" class="h-5 w-5" />
                                    <span>{{ item.label }}</span>
                                </button>
                            </nav>
                        </BaseCard>
                    </div>

                    <!-- Main Content Area -->
                    <div class="min-w-0 flex-1">
                        <BaseCard class="h-full overflow-y-auto">
                            <!-- API Configuration Section -->
                            <div v-if="activeSection === 'api'" class="space-y-6">
                                <div>
                                    <h2 class="mb-4 text-xl font-semibold">API Configuration</h2>
                                    <p class="mb-6 text-gray-600">Configure your OpenAI API key for the AI coach functionality.</p>
                                </div>

                                <div class="space-y-4">
                                    <div>
                                        <Label for="apiKey">OpenAI API Key</Label>
                                        <div class="mt-2 flex gap-2">
                                            <Input
                                                id="apiKey"
                                                v-model="apiKey"
                                                :type="showApiKey ? 'text' : 'password'"
                                                placeholder="sk-..."
                                                class="flex-1"
                                            />
                                            <Button @click="showApiKey = !showApiKey" variant="outline">
                                                <svg v-if="!showApiKey" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                                    />
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                                    />
                                                </svg>
                                                <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                                                    />
                                                </svg>
                                            </Button>
                                        </div>
                                        <p class="mt-2 text-xs text-gray-500">Your API key is stored locally and never sent to our servers.</p>
                                    </div>

                                    <div class="pt-4">
                                        <Button @click="saveApiKey"> Save API Key </Button>
                                    </div>
                                </div>
                            </div>

                            <!-- Permissions Section -->
                            <div v-if="activeSection === 'permissions'" class="space-y-6">
                                <div>
                                    <h2 class="mb-4 text-xl font-semibold">Permissions</h2>
                                    <p class="mb-6 text-gray-600">Manage system permissions required for Clueless to function properly.</p>
                                </div>

                                <div class="space-y-4">
                                    <!-- Screen Recording Permission -->
                                    <BaseCard>
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-3">
                                                <div class="rounded-lg bg-blue-100 p-2 dark:bg-blue-900/20">
                                                    <svg
                                                        class="h-5 w-5 text-blue-600 dark:text-blue-400"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                        />
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h3 class="font-medium text-gray-900 dark:text-gray-100">Screen Recording</h3>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                                        Required for system audio capture during meetings
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="flex items-center space-x-3">
                                                <div
                                                    v-if="permissionStatus === 'granted'"
                                                    class="flex items-center space-x-2 text-green-600 dark:text-green-400"
                                                >
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                    <span class="text-sm font-medium">Granted</span>
                                                </div>
                                                <div
                                                    v-else-if="permissionStatus === 'denied'"
                                                    class="flex items-center space-x-2 text-red-600 dark:text-red-400"
                                                >
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M6 18L18 6M6 6l12 12"
                                                        />
                                                    </svg>
                                                    <span class="text-sm font-medium">Denied</span>
                                                </div>
                                                <div
                                                    v-else-if="permissionStatus === 'checking'"
                                                    class="flex items-center space-x-2 text-blue-600 dark:text-blue-400"
                                                >
                                                    <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                                        <circle
                                                            class="opacity-25"
                                                            cx="12"
                                                            cy="12"
                                                            r="10"
                                                            stroke="currentColor"
                                                            stroke-width="4"
                                                        ></circle>
                                                        <path
                                                            class="opacity-75"
                                                            fill="currentColor"
                                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                                        ></path>
                                                    </svg>
                                                    <span class="text-sm font-medium">Checking...</span>
                                                </div>
                                                <div v-else class="flex items-center space-x-2 text-gray-600 dark:text-gray-400">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                        />
                                                    </svg>
                                                    <span class="text-sm font-medium">Unknown</span>
                                                </div>
                                                <div class="flex space-x-2">
                                                    <Button
                                                        @click="checkPermissionStatus"
                                                        variant="outline"
                                                        size="sm"
                                                        :disabled="permissionStatus === 'checking'"
                                                    >
                                                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                                            />
                                                        </svg>
                                                        Check
                                                    </Button>
                                                    <Button
                                                        v-if="permissionStatus === 'denied' || permissionStatus === 'error'"
                                                        @click="requestPermission"
                                                        size="sm"
                                                        :disabled="isRequestingPermission"
                                                    >
                                                        <svg
                                                            v-if="isRequestingPermission"
                                                            class="mr-2 h-4 w-4 animate-spin"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <circle
                                                                class="opacity-25"
                                                                cx="12"
                                                                cy="12"
                                                                r="10"
                                                                stroke="currentColor"
                                                                stroke-width="4"
                                                            ></circle>
                                                            <path
                                                                class="opacity-75"
                                                                fill="currentColor"
                                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                                            ></path>
                                                        </svg>
                                                        <svg v-else class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                                                            />
                                                        </svg>
                                                        {{ isRequestingPermission ? 'Requesting...' : 'Grant Permission' }}
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-if="permissionMessage" class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                                            {{ permissionMessage }}
                                        </div>
                                    </BaseCard>

                                    <!-- Permission Help -->
                                    <BaseCard>
                                        <div class="rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                                            <div class="flex items-start space-x-3">
                                                <svg
                                                    class="mt-0.5 h-5 w-5 text-blue-600 dark:text-blue-400"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                    />
                                                </svg>
                                                <div>
                                                    <h4 class="font-medium text-blue-900 dark:text-blue-100">
                                                        Why is screen recording permission needed?
                                                    </h4>
                                                    <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
                                                        Clueless uses macOS ScreenCaptureKit to capture system audio during meetings. This requires
                                                        screen recording permission, but the app only captures audio data - no visual content is
                                                        recorded or stored.
                                                    </p>
                                                    <p class="mt-2 text-sm text-blue-700 dark:text-blue-300">
                                                        <strong>To manually grant permission:</strong> Go to System Preferences → Privacy & Security →
                                                        Screen Recording, then enable Clueless.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </BaseCard>
                                </div>
                            </div>

                            <!-- Templates Section -->
                            <div v-if="activeSection === 'templates'" class="space-y-6">
                                <div class="mb-6 flex items-center justify-between">
                                    <div>
                                        <h2 class="text-xl font-semibold">Sales Coach Templates</h2>
                                        <p class="mt-1 text-gray-600">Manage your coaching templates and create custom ones.</p>
                                    </div>
                                    <Button @click="$inertia.visit('/realtime-agent/templates/create')">
                                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                        New Template
                                    </Button>
                                </div>

                                <!-- Template List -->
                                <div class="space-y-3">
                                    <BaseCard v-for="template in templates.filter((t) => t.category === 'sales_coach')" :key="template.id" hover>
                                        <div class="flex items-start justify-between">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-lg">{{ getIconEmoji(template.icon) }}</span>
                                                    <h3 class="font-semibold text-gray-800">{{ template.name }}</h3>
                                                    <span
                                                        v-if="template.is_system"
                                                        class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-600"
                                                    >
                                                        Built-in
                                                    </span>
                                                </div>
                                                <p class="mt-1 text-sm text-gray-600">{{ template.description }}</p>
                                                <div class="mt-3 flex items-center gap-4 text-xs text-gray-500">
                                                    <span>Used {{ template.usage_count }} times</span>
                                                    <span>•</span>
                                                    <span>{{ template.variables ? Object.keys(template.variables).length : 0 }} variables</span>
                                                </div>
                                            </div>
                                            <div class="ml-4 flex items-center gap-2">
                                                <Button
                                                    @click="$inertia.visit(`/realtime-agent/templates/${template.id}/edit`)"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="p-2"
                                                    title="Edit template"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                                        />
                                                    </svg>
                                                </Button>
                                                <Button
                                                    @click="deleteTemplate(template)"
                                                    variant="ghost"
                                                    size="sm"
                                                    :disabled="template.is_system"
                                                    class="p-2 hover:text-red-600"
                                                    :title="template.is_system ? 'Built-in templates cannot be deleted' : 'Delete template'"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="2"
                                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                        />
                                                    </svg>
                                                </Button>
                                            </div>
                                        </div>
                                    </BaseCard>
                                </div>
                            </div>

                            <!-- Variables Section -->
                            <div v-if="activeSection === 'variables'" class="space-y-6">
                                <VariablesPanel />
                            </div>

                            <!-- Knowledge Base Section (Placeholder) -->
                            <div v-if="activeSection === 'knowledge'" class="space-y-6">
                                <div>
                                    <h2 class="mb-4 text-xl font-semibold">Knowledge Base</h2>
                                    <p class="mb-6 text-gray-600">Upload documents and resources to enhance the AI coach's knowledge.</p>
                                </div>

                                <BaseCard class="p-8 text-center">
                                    <svg class="mx-auto mb-4 h-16 w-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                        />
                                    </svg>
                                    <p class="text-gray-500">Knowledge base feature coming soon...</p>
                                </BaseCard>
                            </div>
                        </BaseCard>
                    </div>
                </div>
            </div>
        </PageContainer>
    </AppLayout>
</template>

<script setup lang="ts">
import BaseCard from '@/components/design/BaseCard.vue';
import PageContainer from '@/components/design/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import VariablesPanel from '@/components/VariablesPanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { BookOpen, Home, Key, MessageCircle, MessageSquare, Shield, Variable } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

interface Template {
    id: string;
    name: string;
    description: string;
    prompt: string;
    icon: string;
    category: string;
    is_system: boolean;
    usage_count: number;
    variables?: Record<string, string>;
    talking_points?: string[];
    additional_info?: Record<string, any>;
}

// Breadcrumbs
const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Realtime Agent',
        href: '/realtime-agent',
    },
    {
        title: 'Settings',
        href: '/realtime-agent/settings',
    },
];

// Navigation
const navItems = [
    { id: 'dashboard', label: 'Dashboard', icon: Home, link: '/realtime-agent' },
    { id: 'conversations', label: 'Conversations', icon: MessageCircle, link: '/conversations' },
    { id: 'templates', label: 'Templates', icon: MessageSquare },
    { id: 'variables', label: 'Variables', icon: Variable },
    { id: 'api', label: 'API Configuration', icon: Key },
    { id: 'permissions', label: 'Permissions', icon: Shield },
    { id: 'knowledge', label: 'Knowledge Base', icon: BookOpen },
];

// Get section from URL query parameter
const urlParams = new URLSearchParams(window.location.search);
const activeSection = ref(urlParams.get('section') || 'templates');

// API Key Management
const apiKey = ref('');
const showApiKey = ref(false);

// Permission Management
const permissionStatus = ref('checking'); // 'checking', 'granted', 'denied', 'error'
const permissionMessage = ref('');
const isRequestingPermission = ref(false);

// Template Management
const templates = ref<Template[]>([]);

// Methods
const fetchTemplates = async () => {
    try {
        const response = await axios.get('/templates');
        templates.value = response.data.templates || [];
    } catch (error) {
        console.error('Failed to fetch templates:', error);
    }
};

const saveApiKey = () => {
    localStorage.setItem('OPENAI_API_KEY', apiKey.value);
    alert('API Key saved successfully!');
};

const checkPermissionStatus = async () => {
    try {
        permissionStatus.value = 'checking';
        const response = await axios.get('/api/permissions/screen-recording/status');

        if (response.data.status === 'granted') {
            permissionStatus.value = 'granted';
            permissionMessage.value = response.data.message;
        } else if (response.data.status === 'denied') {
            permissionStatus.value = 'denied';
            permissionMessage.value = response.data.message;
        } else if (response.data.status === 'error') {
            permissionStatus.value = 'error';
            permissionMessage.value = response.data.message;
        } else {
            permissionStatus.value = 'error';
            permissionMessage.value = 'Unknown permission status';
        }
    } catch (error) {
        console.error('Failed to check permission status:', error);
        permissionStatus.value = 'error';
        permissionMessage.value = 'Failed to check permission status';
    }
};

const requestPermission = async () => {
    try {
        isRequestingPermission.value = true;
        const response = await axios.post('/api/permissions/screen-recording/request');

        if (response.data.success) {
            // Wait a moment for the permission dialog to appear
            await new Promise((resolve) => setTimeout(resolve, 2000));

            // Check permission status again
            await checkPermissionStatus();
        } else {
            permissionStatus.value = 'error';
            permissionMessage.value = response.data.error || 'Failed to request permission';
        }
    } catch (error) {
        console.error('Failed to request permission:', error);
        permissionStatus.value = 'error';
        permissionMessage.value = 'Failed to request permission';
    } finally {
        isRequestingPermission.value = false;
    }
};

const handleNavClick = (item: any) => {
    if (item.link) {
        router.visit(item.link);
    } else {
        // Update URL with section query parameter
        const url = new URL(window.location.href);
        url.searchParams.set('section', item.id);
        window.history.pushState({}, '', url.toString());
        activeSection.value = item.id;

        // Check permission status if navigating to permissions section
        if (item.id === 'permissions') {
            checkPermissionStatus();
        }
    }
};

const deleteTemplate = async (template: Template) => {
    if (template.is_system) return;

    if (!confirm(`Are you sure you want to delete "${template.name}"?`)) return;

    try {
        await axios.delete(`/templates/${template.id}`);
        await fetchTemplates();
    } catch (error) {
        console.error('Failed to delete template:', error);
        alert('Failed to delete template');
    }
};

const getIconEmoji = (icon: string): string => {
    const iconMap: Record<string, string> = {
        MessageSquare: '💬',
        Zap: '⚡',
        Heart: '❤️',
        Shield: '🛡️',
        TrendingUp: '📈',
        Search: '🔍',
        Code2: '🔧',
        Smile: '😊',
        Target: '🎯',
        Lightbulb: '💡',
        DollarSign: '💵',
        Calculator: '🧮',
        Layers: '🗂️',
        Star: '⭐',
        Award: '🏆',
        Users: '👥',
        Flame: '🔥',
        Rocket: '🚀',
        Phone: '📞',
        Building: '🏢',
    };
    return iconMap[icon] || '💬';
};

onMounted(() => {
    fetchTemplates();

    // Load API key from localStorage
    const savedKey = localStorage.getItem('OPENAI_API_KEY');
    if (savedKey) {
        apiKey.value = savedKey;
    }

    // Check permission status if on permissions section
    if (activeSection.value === 'permissions') {
        checkPermissionStatus();
    }
});
</script>
