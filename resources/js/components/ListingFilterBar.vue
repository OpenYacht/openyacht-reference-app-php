<script lang="ts">
export type ListingFilters = {
    q: string;
    location: string;
    category: string;
    loa_min: number | null;
    loa_max: number | null;
};
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
}>();

// reka selects forbid empty-string values; 'all' is the no-filter sentinel.
const state = reactive({
    q: props.initial.q ?? '',
    location: props.initial.location ?? '',
    category: props.initial.category || 'all',
    loa_min: props.initial.loa_min,
    loa_max: props.initial.loa_max,
});

const categoryItems = [
    { label: 'All categories', value: 'all' },
    ...props.categories.map((category) => ({
        label: category.name,
        value: category.slug,
    })),
];

let timer: ReturnType<typeof setTimeout> | null = null;

watch(state, () => {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        const params: Record<string, string | number> = {};

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
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
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
