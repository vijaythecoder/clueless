<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import Switch from '@/components/ui/switch/Switch.vue';

// State
const recordingEnabled = ref(false);
const fileFormat = ref('wav');
const recordingPath = ref('');
const autoSaveInterval = ref(30);
const retentionDays = ref(0);
const recordingStats = ref({ count: 0, totalSize: 0 });
const clearingRecordings = ref(false);

// Computed
const totalSizeBytes = computed(() => recordingStats.value.totalSize);

// Methods
const saveSettings = () => {
    const settings = {
        enabled: recordingEnabled.value,
        fileFormat: fileFormat.value,
        autoSaveInterval: autoSaveInterval.value,
        retentionDays: retentionDays.value,
    };
    
    localStorage.setItem('recordingSettings', JSON.stringify(settings));
};

const loadSettings = () => {
    const savedSettings = localStorage.getItem('recordingSettings');
    if (savedSettings) {
        const settings = JSON.parse(savedSettings);
        recordingEnabled.value = settings.enabled ?? false;
        fileFormat.value = settings.fileFormat ?? 'wav';
        autoSaveInterval.value = settings.autoSaveInterval ?? 30;
        retentionDays.value = settings.retentionDays ?? 0;
    }
};

const loadRecordingPath = async () => {
    if (window.remote) {
        try {
            const app = window.remote.app;
            const path = window.remote.path;
            const userDataPath = app.getPath('userData');
            recordingPath.value = path.join(userDataPath, 'recordings');
        } catch (error) {
            console.error('Failed to get recording path:', error);
            recordingPath.value = '~/Library/Application Support/Clueless/recordings';
        }
    }
};

const openRecordingsFolder = async () => {
    if (window.remote && recordingPath.value) {
        try {
            await window.remote.shell.openPath(recordingPath.value);
        } catch (error) {
            console.error('Failed to open recordings folder:', error);
        }
    }
};

const loadRecordingStats = async () => {
    if (window.remote && recordingPath.value) {
        try {
            const fs = window.remote.fs;
            
            // Create directory if it doesn't exist
            if (!fs.existsSync(recordingPath.value)) {
                fs.mkdirSync(recordingPath.value, { recursive: true });
            }
            
            // Get all WAV files
            const files = fs.readdirSync(recordingPath.value)
                .filter(file => file.endsWith('.wav'));
            
            let totalSize = 0;
            for (const file of files) {
                const stats = fs.statSync(window.remote.path.join(recordingPath.value, file));
                totalSize += stats.size;
            }
            
            recordingStats.value = {
                count: files.length,
                totalSize: totalSize,
            };
        } catch (error) {
            console.error('Failed to load recording stats:', error);
        }
    }
};

const clearRecordings = async () => {
    if (!confirm('Are you sure you want to delete all recordings? This action cannot be undone.')) {
        return;
    }
    
    clearingRecordings.value = true;
    
    try {
        if (window.remote && recordingPath.value) {
            const fs = window.remote.fs;
            const path = window.remote.path;
            
            const files = fs.readdirSync(recordingPath.value)
                .filter(file => file.endsWith('.wav'));
            
            for (const file of files) {
                fs.unlinkSync(path.join(recordingPath.value, file));
            }
            
            await loadRecordingStats();
            alert('All recordings have been deleted successfully.');
        }
    } catch (error) {
        console.error('Failed to clear recordings:', error);
        alert('Failed to delete recordings. Please try again.');
    } finally {
        clearingRecordings.value = false;
    }
};

const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

// Lifecycle
onMounted(() => {
    loadSettings();
    loadRecordingPath();
    loadRecordingStats();
});

// Watch for changes and save
watch([recordingEnabled, fileFormat, autoSaveInterval, retentionDays], () => {
    saveSettings();
});
</script>

<template>
    <AppLayout>
        <Head title="Recording Settings" />
        
        <SettingsLayout>
            <div class="space-y-6">
                <!-- Main Recording Settings -->
                <Card>
                    <CardHeader>
                        <CardTitle>Audio Recording</CardTitle>
                        <CardDescription>
                            Configure how conversations are recorded locally with separate channels for each speaker
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <!-- Enable Recording Toggle -->
                        <div class="flex items-center justify-between">
                            <div class="flex-1 space-y-0.5">
                                <Label htmlFor="recording-enabled">Enable Audio Recording</Label>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Record conversations locally with separate channels
                                </p>
                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                                    <p>• Left channel: Salesperson (you)</p>
                                    <p>• Right channel: Customer/Speaker</p>
                                </div>
                            </div>
                            <Switch
                                id="recording-enabled"
                                v-model="recordingEnabled"
                                :class="[
                                    recordingEnabled ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-700',
                                    'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2'
                                ]"
                            >
                                <span
                                    aria-hidden="true"
                                    :class="[
                                        recordingEnabled ? 'translate-x-5' : 'translate-x-0',
                                        'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out'
                                    ]"
                                />
                            </Switch>
                        </div>
                    </CardContent>
                </Card>

                <!-- Recording Options (visible when enabled) -->
                <div v-if="recordingEnabled" class="space-y-6">
                    <!-- File Format -->
                    <Card>
                        <CardHeader>
                            <CardTitle>File Format</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-3">
                                <label class="flex items-center">
                                    <input
                                        type="radio"
                                        v-model="fileFormat"
                                        value="wav"
                                        class="h-4 w-4 text-blue-600 focus:ring-blue-500"
                                    />
                                    <span class="ml-3">
                                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">WAV (Recommended)</span>
                                        <span class="block text-xs text-gray-600 dark:text-gray-400">Uncompressed, best quality</span>
                                    </span>
                                </label>
                                <label class="flex items-center opacity-50 cursor-not-allowed">
                                    <input
                                        type="radio"
                                        v-model="fileFormat"
                                        value="mp3"
                                        disabled
                                        class="h-4 w-4 text-blue-600 focus:ring-blue-500"
                                    />
                                    <span class="ml-3">
                                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">MP3 (Coming Soon)</span>
                                        <span class="block text-xs text-gray-600 dark:text-gray-400">Compressed, smaller file size</span>
                                    </span>
                                </label>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Storage Location -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Storage Location</CardTitle>
                            <CardDescription>
                                Recordings are saved to your local disk
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-center gap-2">
                                <code class="flex-1 rounded bg-gray-100 px-3 py-2 text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                                    {{ recordingPath }}
                                </code>
                                <Button
                                    @click="openRecordingsFolder"
                                    variant="outline"
                                    size="sm"
                                >
                                    Open Folder
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Auto-save & Storage Management -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Storage Management</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <!-- Auto-save Interval -->
                            <div>
                                <Label htmlFor="auto-save">Auto-save Interval</Label>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                    How often to save recording progress to disk
                                </p>
                                <select
                                    id="auto-save"
                                    v-model.number="autoSaveInterval"
                                    class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                >
                                    <option :value="15">Every 15 seconds</option>
                                    <option :value="30">Every 30 seconds (Default)</option>
                                    <option :value="60">Every minute</option>
                                    <option :value="120">Every 2 minutes</option>
                                </select>
                            </div>

                            <Separator />

                            <!-- Storage Stats -->
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Total recordings:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ recordingStats.count }} files</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Total size:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatFileSize(recordingStats.totalSize) }}</span>
                                </div>
                            </div>

                            <Separator />

                            <!-- Retention Policy -->
                            <div>
                                <Label htmlFor="retention">Auto-delete recordings older than:</Label>
                                <select
                                    id="retention"
                                    v-model.number="retentionDays"
                                    class="mt-2 w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                >
                                    <option :value="0">Never</option>
                                    <option :value="7">7 days</option>
                                    <option :value="30">30 days</option>
                                    <option :value="90">90 days</option>
                                </select>
                            </div>

                            <Button
                                @click="clearRecordings"
                                :disabled="clearingRecordings || totalSizeBytes === 0"
                                variant="destructive"
                                class="w-full"
                            >
                                {{ clearingRecordings ? 'Clearing...' : 'Clear All Recordings' }}
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <!-- Privacy Notice -->
                <Card>
                    <CardContent class="pt-6">
                        <div class="flex gap-3">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                <p class="font-medium text-gray-900 dark:text-gray-100 mb-1">Privacy Notice</p>
                                <p>All recordings are stored locally on your device. They are never uploaded to any server. Please ensure you comply with local laws and obtain necessary consent before recording conversations.</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>