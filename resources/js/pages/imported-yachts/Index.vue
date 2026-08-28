<script setup lang="ts">
import { Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ListingCard from '@/components/ListingCard.vue';
import type { ListingBadge } from '@/components/ListingCard.vue';
import type { ListingFilters } from '@/components/ListingFilterBar.vue';
import ListingIndexShell from '@/components/ListingIndexShell.vue';
import PartnerListingsTabs from '@/components/PartnerListingsTabs.vue';
import type { CharterRate } from '@/lib/listingPrice';
import { formatListingPrice } from '@/lib/listingPrice';
import { partnerListingsLabel } from '@/lib/partnerListings';
import { index as charterIndex } from '@/routes/imported-charter-yachts';
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
    charter_rates: CharterRate[];
    location_display: string | null;
    attribution_text: string | null;
    authority_domain: string;
    is_stale: boolean;
    auto_published_at: string | null;
    media_synced_at: string | null;
    media_count: number;
    hero: Record<number, string>;
};

const props = defineProps<{
    listingType: 'sale' | 'charter';
    filters: ListingFilters;
    categories: { slug: string; name: string }[];
    yachts: ImportedYacht[];
}>();

const title = computed(() => partnerListingsLabel(props.listingType));

setLayoutProps({
    breadcrumbs: [
        {
            title: title.value,
            href: props.listingType === 'charter' ? charterIndex() : index(),
        },
        {
            title: 'Imported',
            href: props.listingType === 'charter' ? charterIndex() : index(),
        },
    ],
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
    // Review after, not before: what the acceptance policy published
    // with no human in the loop is badged for an operator to skim.
    ...(yacht.auto_published_at
        ? [
              {
                  label: `Auto-published ${yacht.auto_published_at}`,
                  color: 'info',
              } satisfies ListingBadge,
          ]
        : []),
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

const removeImport = (yacht: ImportedYacht) => {
    router.delete(destroy.url({ importedYacht: yacht.id }), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`${title} — imported`" />

    <ListingIndexShell
        :title="title"
        description="Partner listings curated for display — data and media stay in step with the authority"
        search-placeholder="Search name, builder, or model…"
        :filters="filters"
        :categories="categories"
        :has-results="yachts.length > 0"
        empty="Nothing imported found. Pick listings to display from the Synced tab."
    >
        <template #tabs>
            <PartnerListingsTabs :listing-type="listingType" stage="imported" />
        </template>
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
                {{
                    formatListingPrice({
                        amount: yacht.price_amount,
                        currency: yacht.price_currency,
                        rates: yacht.charter_rates,
                    })
                }}
            </p>
            <p v-if="yacht.location_display" class="text-sm text-muted">
                {{ yacht.location_display }}
            </p>
        </ListingCard>
    </ListingIndexShell>
</template>
