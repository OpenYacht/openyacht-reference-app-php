<script setup lang="ts">
/*
 * Shared shell for the listing index pages: heading, page actions, the
 * filter bar, and the card grid with a per-page empty state.
 */
import Heading from '@/components/Heading.vue';
import ListingFilterBar from '@/components/ListingFilterBar.vue';
import type { ListingFilters } from '@/components/ListingFilterBar.vue';

defineProps<{
    title: string;
    description: string;
    searchPlaceholder: string;
    filters: ListingFilters;
    categories: { slug: string; name: string }[];
    hasResults: boolean;
    empty: string;
}>();
</script>

<template>
    <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6">
        <div class="flex items-start justify-between gap-4">
            <Heading :title="title" :description="description" />
            <slot name="actions" />
        </div>

        <ListingFilterBar
            :initial="filters"
            :categories="categories"
            :placeholder="searchPlaceholder"
        />

        <div v-if="hasResults" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <slot />
        </div>

        <p v-else class="text-sm text-muted">{{ empty }}</p>
    </div>
</template>
