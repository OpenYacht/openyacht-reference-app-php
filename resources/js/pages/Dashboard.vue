<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    edit as editCharterYacht,
    index as charterYachtsIndex,
} from '@/routes/charter-yachts';
import { index as importedYachtsIndex } from '@/routes/imported-yachts';
import { index as partnersIndex } from '@/routes/partners';
import { index as syncedListingsIndex } from '@/routes/synced-listings';
import { edit as editYacht, index as yachtsIndex } from '@/routes/yachts';

type RecentListing = {
    id: number;
    type: 'sale' | 'charter';
    name: string;
    status: string;
    status_label: string;
    thumbnail_url: string | null;
    updated_at: string | null;
};

const props = defineProps<{
    fleet: {
        sale_active: number;
        sale_draft: number;
        charter_active: number;
        charter_draft: number;
    } | null;
    partnerListings: {
        synced: number;
        tombstoned: number;
        imported: number;
    } | null;
    federation: {
        verified: number;
        provisional: number;
        stale: number;
    } | null;
    recentListings: RecentListing[] | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

type Stat = {
    label: string;
    value: number;
    note: string | null;
    noteTone: 'muted' | 'warning';
    icon: string;
    href: string;
};

const stats = computed<Stat[]>(() => {
    const rows: Stat[] = [];

    if (props.fleet) {
        rows.push(
            {
                label: 'Sale listings',
                value: props.fleet.sale_active,
                note: props.fleet.sale_draft
                    ? `${props.fleet.sale_draft} draft${props.fleet.sale_draft === 1 ? '' : 's'}`
                    : null,
                noteTone: 'muted',
                icon: 'i-lucide-sailboat',
                href: toUrl(yachtsIndex()),
            },
            {
                label: 'Charter listings',
                value: props.fleet.charter_active,
                note: props.fleet.charter_draft
                    ? `${props.fleet.charter_draft} draft${props.fleet.charter_draft === 1 ? '' : 's'}`
                    : null,
                noteTone: 'muted',
                icon: 'i-lucide-anchor',
                href: toUrl(charterYachtsIndex()),
            },
        );
    }

    if (props.partnerListings) {
        rows.push({
            label: 'Synced from partners',
            value: props.partnerListings.synced,
            note: props.partnerListings.imported
                ? `${props.partnerListings.imported} imported for display`
                : null,
            noteTone: 'muted',
            icon: 'i-lucide-refresh-cw',
            href: toUrl(syncedListingsIndex()),
        });
    }

    if (props.federation) {
        rows.push({
            label: 'Verified partners',
            value: props.federation.verified,
            note:
                props.federation.stale > 0
                    ? `${props.federation.stale} stale — no successful sync in 7 days`
                    : props.federation.provisional > 0
                      ? `${props.federation.provisional} awaiting approval`
                      : null,
            noteTone: props.federation.stale > 0 ? 'warning' : 'muted',
            icon: 'i-lucide-network',
            href: toUrl(partnersIndex()),
        });
    }

    return rows;
});

const listingHref = (listing: RecentListing) =>
    listing.type === 'charter'
        ? editCharterYacht(listing.id)
        : editYacht(listing.id);

const statusColor = (status: string) =>
    ({
        draft: 'neutral',
        active: 'success',
        under_offer: 'info',
        sold: 'neutral',
        withdrawn: 'neutral',
    })[status] ?? 'neutral';
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6">
        <div
            v-if="stats.length"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
        >
            <Link
                v-for="stat in stats"
                :key="stat.label"
                :href="stat.href"
                class="rounded-xl border border-default p-4 transition-colors hover:bg-elevated/50"
            >
                <div class="flex items-center gap-2 text-muted">
                    <UIcon :name="stat.icon" class="size-4 shrink-0" />
                    <p class="truncate text-sm">{{ stat.label }}</p>
                </div>
                <p class="mt-2 text-3xl font-semibold tracking-tight">
                    {{ stat.value }}
                </p>
                <p
                    class="mt-1 truncate text-xs"
                    :class="
                        stat.noteTone === 'warning'
                            ? 'font-medium text-warning'
                            : 'text-muted'
                    "
                >
                    {{ stat.note ?? ' ' }}
                </p>
            </Link>
        </div>

        <UCard v-if="recentListings && recentListings.length">
            <template #header>
                <h3 class="font-semibold">Recently updated listings</h3>
            </template>
            <ul class="divide-y divide-default">
                <li
                    v-for="listing in recentListings"
                    :key="`${listing.type}-${listing.id}`"
                >
                    <Link
                        :href="listingHref(listing)"
                        class="flex items-center gap-3 py-2.5 transition-colors first:pt-0 last:pb-0 hover:bg-elevated/40"
                    >
                        <img
                            v-if="listing.thumbnail_url"
                            :src="listing.thumbnail_url"
                            :alt="listing.name"
                            class="h-10 w-16 shrink-0 rounded-md object-cover"
                        />
                        <div
                            v-else
                            class="flex h-10 w-16 shrink-0 items-center justify-center rounded-md bg-elevated"
                        >
                            <UIcon
                                :name="
                                    listing.type === 'charter'
                                        ? 'i-lucide-anchor'
                                        : 'i-lucide-sailboat'
                                "
                                class="size-4 text-muted"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ listing.name }}
                            </p>
                            <p
                                v-if="listing.updated_at"
                                class="text-xs text-muted"
                            >
                                updated {{ listing.updated_at }}
                            </p>
                        </div>
                        <UBadge
                            color="neutral"
                            variant="outline"
                            :label="
                                listing.type === 'charter' ? 'Charter' : 'Sale'
                            "
                        />
                        <UBadge
                            :color="statusColor(listing.status)"
                            variant="subtle"
                            :label="listing.status_label"
                        />
                    </Link>
                </li>
            </ul>
        </UCard>

        <UCard v-if="!stats.length">
            <p class="text-sm text-muted">
                Your account has no role assigned yet, so there is nothing to
                show here. An administrator can assign one from the users page.
            </p>
        </UCard>

        <UCard v-if="partnerListings && partnerListings.tombstoned > 0">
            <div class="flex items-center gap-3 text-sm">
                <UIcon
                    name="i-lucide-archive"
                    class="size-4 shrink-0 text-muted"
                />
                <p class="text-muted">
                    {{ partnerListings.tombstoned }} synced
                    {{
                        partnerListings.tombstoned === 1
                            ? 'copy has'
                            : 'copies have'
                    }}
                    been tombstoned by their authority — kept for the record, no
                    longer displayable.
                </p>
                <ULink
                    :href="toUrl(importedYachtsIndex())"
                    class="ms-auto shrink-0 text-xs"
                >
                    Review imports
                </ULink>
            </div>
        </UCard>
    </div>
</template>
