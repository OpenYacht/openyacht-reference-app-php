<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';

const { resolvedAppearance, updateAppearance } = useAppearance();

const toggle = () => {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
};
</script>

<template>
    <UButton
        color="neutral"
        variant="ghost"
        :icon="resolvedAppearance === 'dark' ? 'i-lucide-moon' : 'i-lucide-sun'"
        aria-label="Toggle dark mode"
        @click="toggle"
    />
</template>
