<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ListingCard from '@/components/ListingCard.vue';
import type { ListingBadge } from '@/components/ListingCard.vue';
import type { ListingFilters } from '@/components/ListingFilterBar.vue';
import ListingIndexShell from '@/components/ListingIndexShell.vue';
import type { CharterRate } from '@/lib/listingPrice';
import { formatRateRange } from '@/lib/listingPrice';
import { create, edit, index } from '@/routes/charter-yachts';

type YachtRow = {
    id: number;
    uuid: string;
    name: string;
    status: string;
    status_label: string;
    thumbnail_url: string | null;
    builder_name: string | null;
    year_built: number | null;
    loa_m: number | null;
    rates: CharterRate[];
    updated_at: string | null;
};

defineProps<{
    filters: ListingFilters;
    categories: { slug: string; name: string }[];
    builders: string[];
    statuses: { value: string; label: string }[];
    yachts: YachtRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Charter yachts',
                href: index(),
            },
        ],
    },
});

const badges = (yacht: YachtRow): ListingBadge[] => [
    {
        label: yacht.status_label,
        color:
            (
                {
                    draft: 'neutral',
                    active: 'success',
                    under_offer: 'info',
                } as const
            )[yacht.status] ?? 'neutral',
    },
];

const metaLine = (yacht: YachtRow): string =>
    [
        yacht.builder_name,
        yacht.loa_m ? `${yacht.loa_m}m` : null,
        yacht.year_built,
    ]
        .filter(Boolean)
        .join(' · ');
</script>

<template>
    <Head title="Charter yachts" />

    <ListingIndexShell
        title="Charter yachts"
        description="Charter listings this node is the authority for"
        search-placeholder="Search name, builder, or model…"
        :filters="filters"
        :categories="categories"
        :builders="builders"
        :statuses="statuses"
        show-year-range
        show-power-sail
        :has-results="yachts.length > 0"
        empty="No charter listings found. New listings start as drafts and are never distributed until you activate them."
    >
        <template #actions>
            <UButton
                :to="create.url()"
                icon="i-lucide-plus"
                label="New charter listing"
            />
        </template>

        <ListingCard
            v-for="yacht in yachts"
            :key="yacht.id"
            :title="yacht.name"
            :href="edit(yacht.id)"
            :image="yacht.thumbnail_url"
            :badges="badges(yacht)"
            :meta="metaLine(yacht)"
        >
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-medium">
                    {{ formatRateRange(yacht.rates) }}
                </p>
                <p v-if="yacht.updated_at" class="text-xs text-muted">
                    updated {{ yacht.updated_at }}
                </p>
            </div>
        </ListingCard>
    </ListingIndexShell>
</template>
