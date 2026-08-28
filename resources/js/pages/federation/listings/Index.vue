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
import { store as importCopy } from '@/routes/imported-yachts';
import { index as charterIndex } from '@/routes/synced-charter-listings';
import { index, show } from '@/routes/synced-listings';

type Copy = {
    id: number;
    imported: boolean;
    importable: boolean;
    thumbnail_url: string | null;
    name: string | null;
    type: string;
    status: string;
    status_label: string;
    price_amount: string | null;
    price_currency: string | null;
    charter_rates: CharterRate[];
    node_name: string;
    listing_updated_at: string | null;
    received_at: string;
    signature_verified: boolean;
    is_stale: boolean;
    is_tombstoned: boolean;
    has_conflict: boolean;
    attribution: string | null;
};

const props = defineProps<{
    listingType: 'sale' | 'charter';
    filters: ListingFilters;
    categories: { slug: string; name: string }[];
    copies: Copy[];
}>();

const title = computed(() => partnerListingsLabel(props.listingType));

setLayoutProps({
    breadcrumbs: [
        {
            title: title.value,
            href: props.listingType === 'charter' ? charterIndex() : index(),
        },
        {
            title: 'Synced',
            href: props.listingType === 'charter' ? charterIndex() : index(),
        },
    ],
});

const page = usePage();

const doImport = (copy: Copy) => {
    router.post(
        importCopy.url({ copy: copy.id }),
        {},
        { preserveScroll: true },
    );
};

const badges = (copy: Copy): ListingBadge[] => [
    ...(copy.imported
        ? [{ label: 'Imported', color: 'info' } satisfies ListingBadge]
        : []),
    {
        label: copy.status_label,
        color:
            (
                {
                    active: 'success',
                    under_offer: 'info',
                } as const
            )[copy.status] ?? 'neutral',
    },
    ...(copy.has_conflict
        ? [
              {
                  label: 'Identity conflict',
                  color: 'error',
              } satisfies ListingBadge,
          ]
        : []),
    ...(copy.is_stale
        ? [{ label: 'Stale', color: 'warning' } satisfies ListingBadge]
        : []),
];
</script>

<template>
    <Head :title="`${title} — synced`" />

    <ListingIndexShell
        :title="title"
        description="Copies of partners' listings, held with provenance — never re-served as this node's own"
        search-placeholder="Search name or partner domain…"
        :filters="filters"
        :categories="categories"
        :has-results="copies.length > 0"
        empty="Nothing synced found. Once a partner is approved and synced, their shared listings appear here."
    >
        <template #tabs>
            <PartnerListingsTabs :listing-type="listingType" stage="synced" />
        </template>
        <ListingCard
            v-for="copy in copies"
            :key="copy.id"
            :title="copy.name ?? 'Unnamed'"
            :href="show(copy.id)"
            :image="copy.thumbnail_url"
            :badges="badges(copy)"
            :meta="copy.node_name"
            :attribution="copy.attribution"
            :dimmed="copy.is_tombstoned"
        >
            <template
                v-if="
                    copy.importable &&
                    !copy.imported &&
                    page.props.auth.canManageListings
                "
                #trailing
            >
                <UButton
                    color="neutral"
                    variant="outline"
                    size="xs"
                    icon="i-lucide-download"
                    label="Import"
                    @click="doImport(copy)"
                />
            </template>

            <p class="text-sm font-medium">
                {{
                    formatListingPrice({
                        amount: copy.price_amount,
                        currency: copy.price_currency,
                        rates: copy.charter_rates,
                    })
                }}
            </p>
            <p class="text-xs text-muted">
                <template v-if="copy.listing_updated_at">
                    updated {{ copy.listing_updated_at }} ·
                </template>
                received {{ copy.received_at }}
            </p>
        </ListingCard>
    </ListingIndexShell>
</template>
