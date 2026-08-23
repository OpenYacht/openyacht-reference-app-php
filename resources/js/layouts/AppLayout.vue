<script setup lang="ts">
import type { BreadcrumbItem as UBreadcrumbItem } from '@nuxt/ui';
import { computed } from 'vue';
import AppearanceToggle from '@/components/AppearanceToggle.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const breadcrumbItems = computed<UBreadcrumbItem[]>(() =>
    breadcrumbs.map((item, index) => ({
        label: item.title,
        to: index === breadcrumbs.length - 1 ? undefined : toUrl(item.href),
    })),
);
</script>

<template>
    <UApp>
        <UDashboardGroup>
            <AppSidebar />

            <UDashboardPanel>
                <template #header>
                    <UDashboardNavbar>
                        <template #title>
                            <UBreadcrumb
                                v-if="breadcrumbItems.length"
                                :items="breadcrumbItems"
                            />
                        </template>
                        <template #right>
                            <AppearanceToggle />
                        </template>
                    </UDashboardNavbar>
                </template>

                <template #body>
                    <slot />
                </template>
            </UDashboardPanel>
        </UDashboardGroup>
    </UApp>
</template>
