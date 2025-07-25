<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { BookOpen, FileText, Folder, MessageCircle, Phone, Settings, Variable } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const mainNavItems: NavItem[] = [
    {
        title: 'Realtime Agent',
        href: '/realtime-agent-v2',
        icon: Phone,
    },
    {
        title: 'Call History',
        href: '/conversations',
        icon: MessageCircle,
    },
    {
        title: 'Templates',
        href: '/templates',
        icon: FileText,
    },
    {
        title: 'Variables',
        href: '/variables',
        icon: Variable,
    },
    {
        title: 'Settings',
        href: '/settings/api-keys',
        icon: Settings,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/vijaythecoder/clueless',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://github.com/vijaythecoder/clueless#readme',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/realtime-agent-v2">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
