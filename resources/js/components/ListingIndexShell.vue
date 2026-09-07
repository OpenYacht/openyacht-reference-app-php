<script setup lang="ts">
/*
 * Shared shell for the listing index pages: heading, page actions, the
 * filter bar, the card grid with a per-page empty state, and paging
 * controls when the index is paginated.
 */
import Heading from '@/components/Heading.vue';
import ListingFilterBar from '@/components/ListingFilterBar.vue';
import type {
    ListingFilters,
    PartnerOption,
} from '@/components/ListingFilterBar.vue';
import PaginationControls from '@/components/PaginationControls.vue';
import type { Paginated } from '@/types/pagination';

defineProps<{
    title: string;
    description: string;
    searchPlaceholder: string;
    filters: ListingFilters;
    categories: { slug: string; name: string }[];
    builders?: string[];
    statuses?: { value: string; label: string }[];
    partners?: PartnerOption[];
    importStates?: { value: string; label: string }[];
    importState?: string;
    showYearRange?: boolean;
    showPowerSail?: boolean;
    hasResults: boolean;
    empty: string;
    paginator?: Omit<Paginated<unknown>, 'data'>;
}>();
</script>

<template>
    <div class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6">
        <div class="flex items-start justify-between gap-4">
            <Heading :title="title" :description="description" />
            <slot name="actions" />
        </div>

        <slot name="tabs" />

        <ListingFilterBar
            :initial="filters"
            :categories="categories"
            :builders="builders"
            :statuses="statuses"
            :partners="partners"
            :import-states="importStates"
            :import-state="importState"
            :show-year-range="showYearRange"
            :show-power-sail="showPowerSail"
            :placeholder="searchPlaceholder"
        />

        <div v-if="hasResults" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <slot />
        </div>

        <p v-else class="text-sm text-muted">{{ empty }}</p>

        <PaginationControls v-if="paginator" :paginator="paginator" />
    </div>
</template>
