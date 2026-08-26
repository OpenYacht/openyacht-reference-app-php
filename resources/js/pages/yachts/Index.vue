<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ListingCard from '@/components/ListingCard.vue';
import type { ListingBadge } from '@/components/ListingCard.vue';
import type { ListingFilters } from '@/components/ListingFilterBar.vue';
import ListingIndexShell from '@/components/ListingIndexShell.vue';
import { create, edit, index } from '@/routes/yachts';

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
    price_amount: string | null;
    price_currency: string | null;
    updated_at: string | null;
};

defineProps<{
    filters: ListingFilters;
    categories: { slug: string; name: string }[];
    yachts: YachtRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Sale yachts',
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
    <Head title="Sale yachts" />

    <ListingIndexShell
        title="Sale yachts"
        description="Sale listings this node is the authority for"
        search-placeholder="Search name, builder, or model…"
        :filters="filters"
        :categories="categories"
        :has-results="yachts.length > 0"
        empty="No listings found. New listings start as drafts and are never distributed until you activate them."
    >
        <template #actions>
            <UButton
                :to="create.url()"
                icon="i-lucide-plus"
                label="New listing"
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
                    <template v-if="yacht.price_amount">
                        {{ yacht.price_amount }} {{ yacht.price_currency }}
                    </template>
                </p>
                <p v-if="yacht.updated_at" class="text-xs text-muted">
                    updated {{ yacht.updated_at }}
                </p>
            </div>
        </ListingCard>
    </ListingIndexShell>
</template>
