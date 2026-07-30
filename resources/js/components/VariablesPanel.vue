<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold">Variables Management</h2>
                <p class="mt-1 text-gray-600">Define dynamic variables for use in your templates</p>
            </div>
            <div class="flex items-center gap-2">
                <Button @click="seedDefaults" variant="outline" class="omega-button omega-button-secondary" :disabled="loading">
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                        />
                    </svg>
                    Seed Defaults
                </Button>
                <Button @click="showImportDialog = true" variant="outline" class="omega-button omega-button-secondary">
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
                        />
                    </svg>
                    Import
                </Button>
                <Button @click="exportVariables" variant="outline" class="omega-button omega-button-secondary">
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"
                        />
                    </svg>
                    Export
                </Button>
                <Button @click="showCreateDialog = true" class="omega-button omega-button-primary">
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Variable
                </Button>
            </div>
        </div>

        <!-- Category Filter -->
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600">Filter by category:</span>
            <div class="flex gap-2">
                <button
                    @click="selectedCategory = null"
                    :class="[
                        'rounded-lg px-3 py-1 text-sm transition-colors',
                        selectedCategory === null ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200',
                    ]"
                >
                    All ({{ variables.length }})
                </button>
                <button
                    v-for="stat in categoryStats"
                    :key="stat.name"
                    @click="selectedCategory = stat.name"
                    :class="[
                        'rounded-lg px-3 py-1 text-sm transition-colors',
                        selectedCategory === stat.name ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200',
                    ]"
                >
                    {{ stat.name }} ({{ stat.count }})
                </button>
            </div>
        </div>

        <!-- Variables List -->
        <div v-if="loading && variables.length === 0" class="omega-card p-8 text-center">
            <div class="mx-auto h-12 w-12 animate-spin rounded-full border-b-2 border-purple-600"></div>
            <p class="mt-4 text-gray-500">Loading variables...</p>
        </div>

        <div v-else-if="filteredVariables.length === 0" class="omega-card p-8 text-center">
            <svg class="mx-auto mb-4 h-16 w-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
                />
            </svg>
            <p class="text-gray-500">No variables found. Create your first variable to get started.</p>
        </div>

        <div v-else class="space-y-4">
            <div v-for="variable in filteredVariables" :key="variable.id" class="omega-card p-4">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="mb-1 flex items-center gap-2">
                            <h3 class="text-lg font-semibold">{{ variable.key }}</h3>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                {{ variable.type }}
                            </span>
                            <span v-if="variable.is_system" class="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-600"> system </span>
                            <span class="rounded-full bg-purple-100 px-2 py-0.5 text-xs text-purple-600">
                                {{ variable.category }}
                            </span>
                        </div>
                        <p v-if="variable.description" class="mb-2 text-sm text-gray-600">{{ variable.description }}</p>
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-500">Value:</span>
                                <code class="rounded bg-gray-100 px-2 py-0.5 text-sm">{{ variable.value }}</code>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm text-gray-500">Usage:</span>
                                <code class="rounded bg-gray-100 px-2 py-0.5 text-sm">{{ '{' }}{{ variable.key }}{{ '}' }}</code>
                            </div>
                        </div>
                    </div>
                    <div class="ml-4 flex items-center gap-2">
                        <Button @click="editVariable(variable)" variant="ghost" size="sm" class="p-2">
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
                            @click="deleteVariable(variable)"
                            variant="ghost"
                            size="sm"
                            class="p-2 text-red-600 hover:text-red-700"
                            :disabled="variable.is_system"
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
            </div>
        </div>

        <!-- Create/Edit Dialog -->
        <div v-if="showCreateDialog || editingVariable" class="bg-opacity-50 fixed inset-0 z-50 flex items-center justify-center bg-black p-4">
            <div class="omega-card w-full max-w-md p-6">
                <h3 class="mb-4 text-lg font-semibold">{{ editingVariable ? 'Edit' : 'Create' }} Variable</h3>

                <div class="space-y-4">
                    <div>
                        <Label for="var-key">Key</Label>
                        <Input
                            id="var-key"
                            v-model="formData.key"
                            placeholder="e.g., product_name"
                            class="omega-input mt-1"
                            :disabled="!!editingVariable"
                        />
                    </div>

                    <div>
                        <Label for="var-value">Value</Label>
                        <Input id="var-value" v-model="formData.value" placeholder="e.g., DevHelper" class="omega-input mt-1" />
                    </div>

                    <div>
                        <Label for="var-type">Type</Label>
                        <select id="var-type" v-model="formData.type" class="omega-input mt-1 w-full">
                            <option value="text">Text</option>
                            <option value="number">Number</option>
                            <option value="boolean">Boolean</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>

                    <div>
                        <Label for="var-category">Category</Label>
                        <Input id="var-category" v-model="formData.category" placeholder="e.g., product" class="omega-input mt-1" />
                    </div>

                    <div>
                        <Label for="var-description">Description (optional)</Label>
                        <textarea
                            id="var-description"
                            v-model="formData.description"
                            placeholder="Brief description of this variable"
                            class="omega-input mt-1 h-20 w-full resize-none"
                        />
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <Button @click="closeDialog" variant="outline" class="omega-button omega-button-secondary"> Cancel </Button>
                    <Button @click="saveVariable" class="omega-button omega-button-primary" :disabled="!formData.key || !formData.value">
                        {{ editingVariable ? 'Update' : 'Create' }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Import Dialog -->
        <div v-if="showImportDialog" class="bg-opacity-50 fixed inset-0 z-50 flex items-center justify-center bg-black p-4">
            <div class="omega-card w-full max-w-md p-6">
                <h3 class="mb-4 text-lg font-semibold">Import Variables</h3>

                <div class="rounded-lg border-2 border-dashed border-gray-300 p-8 text-center">
                    <input ref="fileInput" type="file" accept=".json" @change="handleFileUpload" class="hidden" />
                    <svg class="mx-auto mb-4 h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
                        />
                    </svg>
                    <Button @click="fileInput?.click()" class="omega-button omega-button-primary"> Choose JSON File </Button>
                    <p class="mt-2 text-sm text-gray-500">or drag and drop</p>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <Button @click="showImportDialog = false" variant="outline" class="omega-button omega-button-secondary"> Cancel </Button>
                </div>
            </div>
        </div>

        <!-- Error Message -->
        <div v-if="error" class="omega-card border-red-200 bg-red-50 p-4">
            <div class="flex items-center gap-2 text-red-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ error }}</span>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useVariables } from '@/composables/useVariables';
import type { Variable } from '@/types/variable';
import { computed, onMounted, ref } from 'vue';

const {
    variables,
    categoryStats,
    loading,
    error,
    loadVariables,
    saveVariable: saveVariableApi,
    deleteVariable: deleteVariableApi,
    importVariables,
    exportVariables: exportVariablesApi,
    seedDefaults: seedDefaultsApi,
} = useVariables();

// UI State
const selectedCategory = ref<string | null>(null);
const showCreateDialog = ref(false);
const showImportDialog = ref(false);
const editingVariable = ref<Variable | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

// Form Data
const formData = ref({
    key: '',
    value: '',
    type: 'text' as Variable['type'],
    category: 'general',
    description: '',
});

// Computed
const filteredVariables = computed(() => {
    if (!selectedCategory.value) return variables.value;
    return variables.value.filter((v) => v.category === selectedCategory.value);
});

// Methods
const editVariable = (variable: Variable) => {
    editingVariable.value = variable;
    formData.value = {
        key: variable.key,
        value: variable.value,
        type: variable.type,
        category: variable.category,
        description: variable.description || '',
    };
};

const deleteVariable = async (variable: Variable) => {
    if (variable.is_system) return;

    if (confirm(`Are you sure you want to delete the variable "${variable.key}"?`)) {
        await deleteVariableApi(variable.key);
    }
};

const saveVariable = async () => {
    const result = await saveVariableApi(formData.value);
    if (result) {
        closeDialog();
    }
};

const closeDialog = () => {
    showCreateDialog.value = false;
    editingVariable.value = null;
    formData.value = {
        key: '',
        value: '',
        type: 'text',
        category: 'general',
        description: '',
    };
};

const handleFileUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) return;

    const success = await importVariables(file);
    if (success) {
        showImportDialog.value = false;
    }
};

const exportVariables = async () => {
    const data = await exportVariablesApi();
    if (data) {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `variables-export-${new Date().toISOString().split('T')[0]}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }
};

const seedDefaults = async () => {
    if (confirm('This will create default system variables. Continue?')) {
        await seedDefaultsApi();
    }
};

// Lifecycle
onMounted(() => {
    loadVariables();
});
</script>
