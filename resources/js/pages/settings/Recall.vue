<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, RadioTower } from 'lucide-vue-next';

const props = defineProps<{
    has_api_key: boolean;
    has_webhook_secret: boolean;
    configured: boolean;
    region: string;
    webhook_url: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Recall', href: '/settings/recall' },
];

const form = useForm({
    api_key: '',
    webhook_secret: '',
});

const updateCredentials = () => {
    form.put('/settings/recall', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Recall" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingsLayout>
            <div class="space-y-6">
                <div>
                    <h3 class="text-lg font-medium">Recall</h3>
                    <p class="text-sm text-muted-foreground">Teams meeting connection settings</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <RadioTower class="size-5" />
                            Recall credentials
                        </CardTitle>
                        <CardDescription>
                            Region: {{ props.region }}<span v-if="props.webhook_url">. Webhook: {{ props.webhook_url }}</span>
                        </CardDescription>
                    </CardHeader>

                    <form @submit.prevent="updateCredentials">
                        <CardContent class="space-y-4">
                            <div v-if="props.configured" class="flex items-center gap-2 text-sm text-green-700 dark:text-green-400">
                                <CheckCircle2 class="size-4" />
                                Recall is configured
                            </div>

                            <div class="space-y-2">
                                <Label for="recall-api-key">API key</Label>
                                <Input
                                    id="recall-api-key"
                                    v-model="form.api_key"
                                    type="password"
                                    autocomplete="off"
                                    :placeholder="props.has_api_key ? 'Configured' : 'Recall API key'"
                                    :disabled="form.processing"
                                />
                                <p v-if="form.errors.api_key" class="text-sm text-destructive">{{ form.errors.api_key }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="recall-webhook-secret">Webhook secret</Label>
                                <Input
                                    id="recall-webhook-secret"
                                    v-model="form.webhook_secret"
                                    type="password"
                                    autocomplete="off"
                                    :placeholder="props.has_webhook_secret ? 'Configured' : 'whsec_...'"
                                    :disabled="form.processing"
                                />
                                <p v-if="form.errors.webhook_secret" class="text-sm text-destructive">{{ form.errors.webhook_secret }}</p>
                            </div>
                        </CardContent>

                        <CardFooter>
                            <Button type="submit" :disabled="form.processing">Update credentials</Button>
                        </CardFooter>
                    </form>
                </Card>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
