<script lang="ts">
export type ListingFilters = {
    q: string;
    location: string;
    category: string;
    loa_min: number | null;
    loa_max: number | null;
    builder: string;
    year_min: number | null;
    year_max: number | null;
    power_sail: string;
    status: string;
    partner: string;
};

export type PartnerOption = { id: number; label: string };
</script>

<script setup lang="ts">
/*
 * Shared filter bar for the listing index pages: free-text search plus the
 * facets a yacht search actually needs — location, category (from the
 * vendored vocabulary), and size. Filtering is server-side: the debounced
 * state lands in the query string via a partial Inertia visit, so URLs
 * stay shareable and scoping rules (e.g. broker-only visibility) live in
 * the controllers.
 */
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, reactive, watch } from 'vue';

const props = defineProps<{
    initial: ListingFilters;
    categories: { slug: string; name: string }[];
    placeholder?: string;
    // Optional facets: a control renders only when its options are
    // provided, so indexes that don't apply a filter never show it.
    builders?: string[];
    statuses?: { value: string; label: string }[];
    partners?: PartnerOption[];
    showYearRange?: boolean;
    showPowerSail?: boolean;
    // Extra params carried through every visit unchanged — e.g. the
    // partner picker's listing_type tab.
    extra?: Record<string, string>;
}>();

// reka selects forbid empty-string values; 'all' is the no-filter sentinel.
const state = reactive({
    q: props.initial.q ?? '',
    location: props.initial.location ?? '',
    category: props.initial.category || 'all',
    loa_min: props.initial.loa_min,
    loa_max: props.initial.loa_max,
    builder: props.initial.builder || 'all',
    year_min: props.initial.year_min,
    year_max: props.initial.year_max,
    power_sail: props.initial.power_sail || 'all',
    status: props.initial.status || 'all',
    partner: props.initial.partner || 'all',
});

const categoryItems = [
    { label: 'All categories', value: 'all' },
    ...props.categories.map((category) => ({
        label: category.name,
        value: category.slug,
    })),
];

const builderItems = [
    { label: 'All builders', value: 'all' },
    ...(props.builders ?? []).map((builder) => ({
        label: builder,
        value: builder,
    })),
];

const statusItems = [
    { label: 'All statuses', value: 'all' },
    ...(props.statuses ?? []).map((status) => ({
        label: status.label,
        value: status.value,
    })),
];

const partnerItems = [
    { label: 'All partners', value: 'all' },
    ...(props.partners ?? []).map((partner) => ({
        label: partner.label,
        value: String(partner.id),
    })),
];

const powerSailItems = [
    { label: 'Power & sail', value: 'all' },
    { label: 'Power', value: 'power' },
    { label: 'Sail', value: 'sail' },
];

let timer: ReturnType<typeof setTimeout> | null = null;

watch(state, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        const params: Record<string, string | number> = { ...props.extra };

        if (state.q.trim()) {
            params.q = state.q.trim();
        }

        if (state.location.trim()) {
            params.location = state.location.trim();
        }

        if (state.category !== 'all') {
            params.category = state.category;
        }

        if (state.loa_min !== null && state.loa_min !== ('' as unknown)) {
            params.loa_min = state.loa_min;
        }

        if (state.loa_max !== null && state.loa_max !== ('' as unknown)) {
            params.loa_max = state.loa_max;
        }

        if (state.builder !== 'all') {
            params.builder = state.builder;
        }

        if (state.year_min !== null && state.year_min !== ('' as unknown)) {
            params.year_min = state.year_min;
        }

        if (state.year_max !== null && state.year_max !== ('' as unknown)) {
            params.year_max = state.year_max;
        }

        if (state.power_sail !== 'all') {
            params.power_sail = state.power_sail;
        }

        if (state.status !== 'all') {
            params.status = state.status;
        }

        if (state.partner !== 'all') {
            params.partner = state.partner;
        }

        // No page param: a changed filter always restarts at page one.
        router.get(window.location.pathname, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
        <UInput
            v-model="state.q"
            icon="i-lucide-search"
            :placeholder="placeholder ?? 'Search…'"
            class="w-full lg:flex-1"
        />
        <UInput
            v-model="state.location"
            icon="i-lucide-map-pin"
            placeholder="Location"
            class="w-full lg:w-48"
        />
        <USelectMenu
            v-model="state.category"
            :items="categoryItems"
            value-key="value"
            class="w-full lg:w-52"
            aria-label="Category"
        />
        <USelectMenu
            v-if="partners"
            v-model="state.partner"
            :items="partnerItems"
            value-key="value"
            class="w-full lg:w-52"
            aria-label="Partner"
        />
        <USelectMenu
            v-if="builders"
            v-model="state.builder"
            :items="builderItems"
            value-key="value"
            class="w-full lg:w-48"
            aria-label="Builder"
        />
        <USelectMenu
            v-if="statuses"
            v-model="state.status"
            :items="statusItems"
            value-key="value"
            class="w-full lg:w-44"
            aria-label="Status"
        />
        <USelectMenu
            v-if="showPowerSail"
            v-model="state.power_sail"
            :items="powerSailItems"
            value-key="value"
            class="w-full lg:w-36"
            aria-label="Power or sail"
        />
        <div v-if="showYearRange" class="flex items-center gap-2">
            <UInput
                v-model.number="state.year_min"
                type="number"
                min="1800"
                placeholder="Year from"
                class="w-full lg:w-28"
                aria-label="Built from year"
            />
            <span class="text-sm text-muted">–</span>
            <UInput
                v-model.number="state.year_max"
                type="number"
                min="1800"
                placeholder="Year to"
                class="w-full lg:w-28"
                aria-label="Built to year"
            />
        </div>
        <div class="flex items-center gap-2">
            <UInput
                v-model.number="state.loa_min"
                type="number"
                min="0"
                placeholder="Min m"
                class="w-full lg:w-24"
                aria-label="Minimum length (m)"
            />
            <span class="text-sm text-muted">–</span>
            <UInput
                v-model.number="state.loa_max"
                type="number"
                min="0"
                placeholder="Max m"
                class="w-full lg:w-24"
                aria-label="Maximum length (m)"
            />
        </div>
    </div>
</template>
