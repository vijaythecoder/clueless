<script setup lang="ts">
import BaseCard from '@/components/design/BaseCard.vue';
import EmptyState from '@/components/design/EmptyState.vue';
import PageContainer from '@/components/design/PageContainer.vue';
import Button from '@/components/ui/button/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import axios, { AxiosError } from 'axios';
import { FileText, Plus } from 'lucide-vue-next';
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
    created_at: string;
    updated_at: string;
}

interface Props {
    templates?: Template[];
}

const props = defineProps<Props>();

const templates = ref<Template[]>(props.templates || []);
const loading = ref(false);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Templates',
        href: '/templates',
    },
];

const fetchTemplates = async () => {
    loading.value = true;
    try {
        const response = await axios.get(route('templates.index'));
        templates.value = response.data.templates;
    } catch (error) {
        console.error('Failed to fetch templates:', error);
    } finally {
        loading.value = false;
    }
};

const createTemplate = () => {
    router.visit('/templates/create');
};

const editTemplate = (templateId: string) => {
    router.visit(`/templates/${templateId}/edit`);
};

const deleteTemplate = async (template: Template) => {
    // Enhanced confirmation for system templates
    let confirmMessage = `Are you sure you want to delete "${template.name}"?`;
    if (template.is_system) {
        confirmMessage = `⚠️ WARNING: You are about to delete a built-in system template!\n\n"${template.name}" is a pre-configured template that may be useful for many users.\n\nAre you absolutely sure you want to permanently delete this system template?`;
    }

    if (confirm(confirmMessage)) {
        try {
            await axios.delete(route('templates.destroy', template.id));
            await fetchTemplates();
        } catch (error: unknown) {
            console.error('Failed to delete template:', error);

            // Handle specific error messages from backend
            const requestError = error instanceof AxiosError ? error : null;
            const responseData = requestError?.response?.data as { error?: string } | undefined;
            if (requestError?.response?.status === 422 && responseData?.error) {
                alert(responseData.error);
            } else {
                alert('Failed to delete template. Please try again.');
            }
        }
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
        Code2: '💻',
        Smile: '😊',
        Target: '🎯',
        Lightbulb: '💡',
        DollarSign: '💰',
        Calculator: '🔢',
        BookOpen: '📖',
        Users: '👥',
        Briefcase: '💼',
        Award: '🏆',
    };
    return iconMap[icon] || '📝';
};

onMounted(() => {
    // Only fetch if templates weren't passed as props
    if (!props.templates) {
        fetchTemplates();
    }
});
</script>

<template>
    <Head title="Templates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <PageContainer>
            <!-- Header with action button -->
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Templates</h1>
                <Button @click="createTemplate">
                    <Plus :size="16" class="mr-2" />
                    Create Template
                </Button>
            </div>

            <!-- Loading state -->
            <div v-if="loading" class="flex items-center justify-center py-12">
                <p class="text-gray-500 dark:text-gray-400">Loading templates...</p>
            </div>

            <!-- Empty state -->
            <div v-else-if="templates.length === 0">
                <BaseCard>
                    <EmptyState :icon="FileText" title="No templates yet" description="Create your first template to get started">
                        <template #action>
                            <Button @click="createTemplate">
                                <Plus :size="16" class="mr-2" />
                                Create Template
                            </Button>
                        </template>
                    </EmptyState>
                </BaseCard>
            </div>

            <!-- Templates grid -->
            <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <BaseCard v-for="template in templates" :key="template.id" hover class="group relative">
                    <div class="mb-4 flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-lg dark:bg-gray-800">
                                {{ getIconEmoji(template.icon) }}
                            </div>
                            <div>
                                <h3 class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ template.name }}
                                </h3>
                                <p v-if="template.is_system" class="text-xs text-gray-500 dark:text-gray-400">System Template</p>
                            </div>
                        </div>
                        <span v-if="template.usage_count > 0" class="text-xs text-gray-500 dark:text-gray-400">
                            Used {{ template.usage_count }} times
                        </span>
                    </div>

                    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                        {{ template.description || 'No description' }}
                    </p>

                    <div class="flex items-center gap-2">
                        <!-- Edit button -->
                        <Button size="sm" variant="outline" @click="editTemplate(template.id)"> Edit </Button>

                        <!-- Delete button -->
                        <Button
                            size="sm"
                            variant="outline"
                            @click="deleteTemplate(template)"
                            class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                        >
                            Delete
                        </Button>
                    </div>
                </BaseCard>
            </div>
        </PageContainer>
    </AppLayout>
</template>
