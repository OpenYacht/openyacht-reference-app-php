<script setup lang="ts">
import type { TabsItem } from '@nuxt/ui';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/types';

const { appearance, updateAppearance } = useAppearance();

const items: TabsItem[] = [
    { label: 'Light', icon: 'i-lucide-sun', value: 'light' },
    { label: 'Dark', icon: 'i-lucide-moon', value: 'dark' },
    { label: 'System', icon: 'i-lucide-monitor', value: 'system' },
];

const onChange = (value: string | number) => {
    updateAppearance(value as Appearance);
};
</script>

<template>
    <UTabs
        :items="items"
        :content="false"
        :model-value="appearance"
        color="neutral"
        class="w-fit"
        @update:model-value="onChange"
    />
</template>
