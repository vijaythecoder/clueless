<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Bot, Mic, Settings, Sparkles } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const features = [
    {
        icon: Mic,
        title: 'Realtime Agent',
        description: 'Voice conversations with real-time AI coaching',
        href: '/realtime-agent-v2',
        color: 'text-green-600 dark:text-green-400',
    },
    {
        icon: Bot,
        title: 'Templates',
        description: 'Create and manage conversation templates',
        href: '/templates',
        color: 'text-purple-600 dark:text-purple-400',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Welcome Section -->
            <div class="rounded-lg bg-gradient-to-r from-blue-600 to-purple-600 p-8 text-white">
                <div class="mb-2 flex items-center gap-2">
                    <Sparkles class="h-6 w-6" />
                    <h1 class="text-3xl font-bold">Welcome to Clueless</h1>
                </div>
                <p class="mb-4 text-lg opacity-90">Your AI-powered assistant for intelligent conversations and real-time coaching</p>
                <Link href="/realtime-agent">
                    <Button variant="secondary" size="lg">
                        <Mic class="mr-2 h-5 w-5" />
                        Start Voice Session
                        <ArrowRight class="ml-2 h-5 w-5" />
                    </Button>
                </Link>
            </div>

            <!-- Features Grid -->
            <div class="grid gap-4 md:grid-cols-2">
                <Card v-for="feature in features" :key="feature.title" class="transition-shadow hover:shadow-lg">
                    <CardHeader>
                        <div class="flex items-center gap-3">
                            <div :class="[feature.color, 'rounded-lg bg-gray-100 p-2 dark:bg-gray-800']">
                                <component :is="feature.icon" class="h-6 w-6" />
                            </div>
                            <CardTitle>{{ feature.title }}</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <CardDescription class="mb-4">
                            {{ feature.description }}
                        </CardDescription>
                        <Link :href="feature.href">
                            <Button variant="outline" class="w-full">
                                Open
                                <ArrowRight class="ml-2 h-4 w-4" />
                            </Button>
                        </Link>
                    </CardContent>
                </Card>
            </div>

            <!-- Quick Actions -->
            <Card>
                <CardHeader>
                    <CardTitle>Quick Actions</CardTitle>
                    <CardDescription>Common tasks and settings</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-wrap gap-2">
                        <Link href="/conversations">
                            <Button variant="outline"> View Conversations </Button>
                        </Link>
                        <Link href="/variables">
                            <Button variant="outline"> Manage Variables </Button>
                        </Link>
                        <Link href="/settings/api-keys">
                            <Button variant="outline">
                                <Settings class="mr-2 h-4 w-4" />
                                API Settings
                            </Button>
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
