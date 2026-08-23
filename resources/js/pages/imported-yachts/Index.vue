<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import ListingCard from '@/components/ListingCard.vue';
import type { ListingBadge } from '@/components/ListingCard.vue';
import type { ListingFilters } from '@/components/ListingFilterBar.vue';
import ListingIndexShell from '@/components/ListingIndexShell.vue';
import { destroy, index, show } from '@/routes/imported-yachts';

type ImportedYacht = {
    id: number;
    name: string;
    type: string;
    status: string;
    status_label: string;
    builder_name: string | null;
    model_name: string | null;
    year_built: number | null;
    loa_m: number | null;
    price_amount: string | null;
    price_currency: string | null;
    location_display: string | null;
    attribution_text: string | null;
    authority_domain: string;
    is_stale: boolean;
    media_synced_at: string | null;
    media_count: number;
    hero: Record<number, string>;
};

defineProps<{
    filters: ListingFilters;
    categories: { slug: string; name: string }[];
    yachts: ImportedYacht[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Imported yachts',
                href: index(),
            },
        ],
    },
});

const page = usePage();

const largest = (hero: Record<number, string>) => {
    const widths = Object.keys(hero).map(Number);

    return widths.length ? hero[Math.max(...widths)] : null;
};

const srcset = (hero: Record<number, string>) =>
    Object.entries(hero)
        .map(([width, url]) => `${url} ${width}w`)
        .join(', ');

const badges = (yacht: ImportedYacht): ListingBadge[] => [
    ...(yacht.is_stale
        ? [{ label: 'Stale', color: 'warning' } satisfies ListingBadge]
        : []),
    ...(yacht.status !== 'active'
        ? [{ label: yacht.status_label } satisfies ListingBadge]
        : []),
];

const metaLine = (yacht: ImportedYacht): string =>
    [
        yacht.builder_name,
        yacht.loa_m ? `${yacht.loa_m}m` : null,
        yacht.year_built,
    ]
        .filter(Boolean)
        .join(' · ');

const formatPrice = (amount: string | null, currency: string | null) => {
    if (!amount || !currency) {
        return 'Price on application';
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(Number(amount));
};

const removeImport = (yacht: ImportedYacht) => {
    router.delete(destroy.url({ importedYacht: yacht.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Imported yachts" />

    <ListingIndexShell
        title="Imported yachts"
        description="Partner listings curated for display — data and media stay in step with the authority"
        search-placeholder="Search name, builder, or model…"
        :filters="filters"
        :categories="categories"
        :has-results="yachts.length > 0"
        empty="Nothing imported found. Pick listings to display from the synced listings page."
    >
        <ListingCard
            v-for="yacht in yachts"
            :key="yacht.id"
            :title="yacht.name"
            :href="show(yacht.id)"
            :image="largest(yacht.hero)"
            :image-srcset="srcset(yacht.hero)"
            :pending="!yacht.media_synced_at"
            :badges="badges(yacht)"
            :meta="metaLine(yacht)"
            :attribution="yacht.attribution_text"
        >
            <template #trailing>
                <UButton
                    v-if="page.props.auth.canManageListings"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-trash-2"
                    aria-label="Remove import"
                    @click="removeImport(yacht)"
                />
            </template>

            <p class="text-sm font-medium">
                {{ formatPrice(yacht.price_amount, yacht.price_currency) }}
            </p>
            <p v-if="yacht.location_display" class="text-sm text-muted">
                {{ yacht.location_display }}
            </p>
        </ListingCard>
    </ListingIndexShell>
</template>
