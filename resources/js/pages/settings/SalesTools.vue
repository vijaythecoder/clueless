<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { verifyMcpImport } from '@/services/mcpImportVerifier';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

interface SalesTool {
    id: string;
    name: string;
    server_label: string;
    server_url?: string | null;
    connector_id?: string | null;
    allowed_tools?: string[] | null;
    require_approval: 'always' | 'never';
    is_enabled: boolean;
    is_read_only: boolean;
    has_authorization: boolean;
}

const props = defineProps<{
    tools: SalesTool[];
}>();

const tools = ref<SalesTool[]>(props.tools ?? []);
const saving = ref(false);
const editingId = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});
const testingId = ref<string | null>(null);
const testResults = ref<Record<string, { success: boolean; message: string }>>({});

const form = reactive({
    name: '',
    server_label: '',
    server_url: '',
    connector_id: '',
    authorization: '',
    allowed_tools: '',
    require_approval: 'never' as 'always' | 'never',
    is_enabled: true,
    is_read_only: true,
});

const isEditing = computed(() => editingId.value !== null);

watch(
    () => form.is_read_only,
    (isReadOnly) => {
        if (!isReadOnly) form.require_approval = 'always';
    },
);

const resetForm = () => {
    editingId.value = null;
    errors.value = {};
    Object.assign(form, {
        name: '',
        server_label: '',
        server_url: '',
        connector_id: '',
        authorization: '',
        allowed_tools: '',
        require_approval: 'never',
        is_enabled: true,
        is_read_only: true,
    });
};

const editTool = (tool: SalesTool) => {
    editingId.value = tool.id;
    errors.value = {};
    Object.assign(form, {
        name: tool.name,
        server_label: tool.server_label,
        server_url: tool.server_url ?? '',
        connector_id: tool.connector_id ?? '',
        authorization: '',
        allowed_tools: (tool.allowed_tools ?? []).join(', '),
        require_approval: tool.require_approval,
        is_enabled: tool.is_enabled,
        is_read_only: tool.is_read_only,
    });
};

const saveTool = async () => {
    saving.value = true;
    errors.value = {};
    const payload = {
        name: form.name,
        server_label: form.server_label,
        server_url: form.server_url || null,
        connector_id: form.connector_id || null,
        authorization: form.authorization || undefined,
        allowed_tools: form.allowed_tools
            ? form.allowed_tools
                  .split(',')
                  .map((item) => item.trim())
                  .filter(Boolean)
            : [],
        require_approval: form.require_approval,
        is_enabled: form.is_enabled,
        is_read_only: form.is_read_only,
    };

    try {
        const request = isEditing.value ? axios.put(`/api/sales-tools/${editingId.value}`, payload) : axios.post('/api/sales-tools', payload);
        const { data } = await request;
        if (isEditing.value) {
            tools.value = tools.value.map((tool) => (tool.id === data.tool.id ? data.tool : tool));
        } else {
            tools.value.unshift(data.tool);
        }
        resetForm();
    } catch (error: any) {
        errors.value = error.response?.data?.errors ?? { form: ['Unable to save tool.'] };
    } finally {
        saving.value = false;
    }
};

const deleteTool = async (tool: SalesTool) => {
    if (!confirm(`Delete ${tool.name}?`)) return;
    await axios.delete(`/api/sales-tools/${tool.id}`);
    tools.value = tools.value.filter((item) => item.id !== tool.id);
};

const testTool = async (tool: SalesTool) => {
    testingId.value = tool.id;
    delete testResults.value[tool.id];
    try {
        const { data } = await axios.post(`/api/sales-tools/${tool.id}/test`);
        const importedTools = await verifyMcpImport(data.clientSecret, data.session.model);
        testResults.value[tool.id] = {
            success: true,
            message: `${importedTools.length} tool${importedTools.length === 1 ? '' : 's'} ready`,
        };
    } catch (error: any) {
        testResults.value[tool.id] = {
            success: false,
            message: error.response?.data?.message ?? 'Unable to validate this tool connection.',
        };
    } finally {
        testingId.value = null;
    }
};
</script>

<template>
    <Head title="Sales tools" />

    <SettingsLayout>
        <div class="space-y-6">
            <HeadingSmall title="Sales tools" description="Connect remote MCP servers and read-only sales knowledge tools." />

            <form class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700" @submit.prevent="saveTool">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="space-y-1 text-sm">
                        <span>Name</span>
                        <input
                            v-model="form.name"
                            class="w-full rounded border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                        />
                        <InputError :message="errors.name?.[0]" />
                    </label>
                    <label class="space-y-1 text-sm">
                        <span>Server label</span>
                        <input
                            v-model="form.server_label"
                            class="w-full rounded border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                        />
                        <InputError :message="errors.server_label?.[0]" />
                    </label>
                    <label class="space-y-1 text-sm">
                        <span>MCP server URL</span>
                        <input
                            v-model="form.server_url"
                            placeholder="https://example.com/mcp"
                            class="w-full rounded border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                        />
                        <InputError :message="errors.server_url?.[0]" />
                    </label>
                    <label class="space-y-1 text-sm">
                        <span>Connector ID</span>
                        <input
                            v-model="form.connector_id"
                            placeholder="connector_googlecalendar"
                            class="w-full rounded border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                        />
                        <InputError :message="errors.connector_id?.[0]" />
                    </label>
                    <label class="space-y-1 text-sm md:col-span-2">
                        <span>Authorization token</span>
                        <input
                            v-model="form.authorization"
                            type="password"
                            placeholder="Leave blank to keep existing token"
                            class="w-full rounded border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                        />
                    </label>
                    <label class="space-y-1 text-sm md:col-span-2">
                        <span>Allowed tools</span>
                        <input
                            v-model="form.allowed_tools"
                            placeholder="search_companies, read_company"
                            class="w-full rounded border border-gray-300 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
                        />
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <input v-model="form.is_enabled" type="checkbox" />
                        Enabled
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="form.is_read_only" type="checkbox" />
                        Read-only
                    </label>
                    <label class="flex items-center gap-2">
                        <span>Approval</span>
                        <select
                            v-model="form.require_approval"
                            :disabled="!form.is_read_only"
                            class="rounded border border-gray-300 bg-white px-2 py-1 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <option value="never">Never for read-only</option>
                            <option value="always">Always</option>
                        </select>
                    </label>
                </div>

                <InputError :message="errors.form?.[0]" />

                <div class="flex gap-2">
                    <Button type="submit" :disabled="saving">{{ isEditing ? 'Update tool' : 'Add tool' }}</Button>
                    <Button v-if="isEditing" type="button" variant="outline" @click="resetForm">Cancel</Button>
                </div>
            </form>

            <div class="space-y-2">
                <div
                    v-if="tools.length === 0"
                    class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-400"
                >
                    No remote sales tools configured yet.
                </div>
                <div
                    v-for="tool in tools"
                    :key="tool.id"
                    class="flex items-center justify-between rounded-lg border border-gray-200 p-4 dark:border-gray-700"
                >
                    <div class="min-w-0">
                        <div class="font-medium">{{ tool.name }}</div>
                        <div class="text-xs text-gray-500">
                            {{ tool.server_label }}
                            <span v-if="tool.server_url"> - {{ tool.server_url }}</span>
                            <span v-if="tool.connector_id"> - {{ tool.connector_id }}</span>
                        </div>
                        <div
                            v-if="testResults[tool.id]"
                            class="mt-1 text-xs"
                            :class="testResults[tool.id].success ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                        >
                            {{ testResults[tool.id].message }}
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" :disabled="testingId === tool.id" @click="testTool(tool)">
                            {{ testingId === tool.id ? 'Testing' : 'Test' }}
                        </Button>
                        <Button variant="outline" size="sm" @click="editTool(tool)">Edit</Button>
                        <Button variant="destructive" size="sm" @click="deleteTool(tool)">Delete</Button>
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
