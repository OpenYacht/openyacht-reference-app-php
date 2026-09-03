<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';
import ListingFilterBar from '@/components/ListingFilterBar.vue';
import type { ListingFilters } from '@/components/ListingFilterBar.vue';
import type { CharterRate } from '@/lib/listingPrice';
import { formatListingPrice } from '@/lib/listingPrice';
import {
    approve,
    block,
    index,
    refreshKeys,
    show,
    sync,
} from '@/routes/partners';
import { update as updateAcceptancePolicy } from '@/routes/partners/acceptance-policy';
import { update as updateFieldGroups } from '@/routes/partners/field-groups';
import { update as updateImportTypes } from '@/routes/partners/import-types';
import { update as updateSharedListings } from '@/routes/partners/shared-listings';
import { update as updateSharingScope } from '@/routes/partners/sharing-scope';

type PartnerKey = {
    key_id: string;
    algorithm: string;
    created_at: string | null;
    pinned: boolean;
};

type Partner = {
    id: number;
    domain: string;
    node_uuid: string | null;
    trust_level: string;
    trust_level_label: string;
    keys: PartnerKey[];
    pinned_key_id: string | null;
    keys_fetched_at: string | null;
    approved_by: string | null;
    last_ok_at: string | null;
    last_synced_at: string | null;
    consecutive_failures: number;
    listing_copies_count: number;
    is_stale: boolean;
    is_hidden: boolean;
    field_groups: string[] | null;
    acceptance_policy: string;
    sharing_scope: string;
    import_types: string;
    effective_acceptance_policy: string;
    policy_groups: { name: string; policy_label: string }[];
};

type SharedListingRow = {
    uuid: string;
    name: string;
    status: string;
    status_label: string;
    audience: string;
    builder_name: string | null;
    loa_m: number | null;
    year_built: number | null;
    price_amount: string | null;
    price_currency: string | null;
    rates: CharterRate[] | null;
    directly_shared: boolean;
    via_groups: string[];
    has_sister_listing: boolean;
};

const props = defineProps<{
    partner: Partner;
    // Curated partners only: the shared-listings picker's data.
    sharedListings?: {
        type: 'sale' | 'charter';
        direct_uuids: string[];
        items: SharedListingRow[];
    };
    filters?: ListingFilters;
    categories?: { slug: string; name: string }[];
    builders?: string[];
    statuses?: { value: string; label: string }[];
    availableFieldGroups: { value: string; label: string }[];
    availableAcceptancePolicies: {
        value: string;
        label: string;
        hint: string;
    }[];
    availableSharingScopes: {
        value: string;
        label: string;
        hint: string;
    }[];
    availableImportTypes: {
        value: string;
        label: string;
        hint: string;
    }[];
}>();

const acceptancePolicy = ref(props.partner.acceptance_policy);
const savingPolicy = ref(false);

const saveAcceptancePolicy = () => {
    savingPolicy.value = true;
    router.put(
        updateAcceptancePolicy.url(props.partner.id),
        { acceptance_policy: acceptancePolicy.value },
        {
            preserveScroll: true,
            onFinish: () => (savingPolicy.value = false),
        },
    );
};

const sharingScope = ref(props.partner.sharing_scope);
const savingScope = ref(false);

const importTypes = ref(props.partner.import_types);
const savingImportTypes = ref(false);

const saveImportTypes = () => {
    savingImportTypes.value = true;
    router.put(
        updateImportTypes.url(props.partner.id),
        { import_types: importTypes.value },
        {
            preserveScroll: true,
            onFinish: () => (savingImportTypes.value = false),
        },
    );
};

const saveSharingScope = () => {
    savingScope.value = true;
    router.put(
        updateSharingScope.url(props.partner.id),
        { sharing_scope: sharingScope.value },
        {
            preserveScroll: true,
            onFinish: () => (savingScope.value = false),
        },
    );
};

// The picker's selection covers the WHOLE type (seeded from every
// direct share, not just the filtered rows) — saving replaces the full
// direct-share list, so narrowing the filter must never unshare what
// the filter hides.
const selectedShares = ref<Set<string>>(
    new Set(props.sharedListings?.direct_uuids ?? []),
);
const savingShares = ref(false);

const toggleShare = (uuid: string) => {
    const next = new Set(selectedShares.value);

    if (next.has(uuid)) {
        next.delete(uuid);
    } else {
        next.add(uuid);
    }

    selectedShares.value = next;
};

// "All" means all currently filtered rows — the lists are unpaginated,
// so the rendered rows are the full filtered set.
const selectAllShown = () => {
    const next = new Set(selectedShares.value);
    props.sharedListings?.items.forEach((item) => next.add(item.uuid));
    selectedShares.value = next;
};

const clearShown = () => {
    const next = new Set(selectedShares.value);
    props.sharedListings?.items.forEach((item) => next.delete(item.uuid));
    selectedShares.value = next;
};

// Sale and charter are never mixed in one list (the table is the type);
// the tab re-visits the page with the other listing_type.
const switchListingType = (type: string) => {
    router.get(
        show.url(props.partner.id),
        { listing_type: type },
        { preserveScroll: true },
    );
};

const saveShares = () => {
    if (!props.sharedListings) {
        return;
    }

    savingShares.value = true;
    router.put(
        updateSharedListings.url(props.partner.id),
        {
            type: props.sharedListings.type,
            uuids: [...selectedShares.value],
        },
        {
            preserveScroll: true,
            onFinish: () => (savingShares.value = false),
        },
    );
};

const shareMeta = (row: SharedListingRow): string =>
    [row.builder_name, row.loa_m ? `${row.loa_m}m` : null, row.year_built]
        .filter(Boolean)
        .join(' · ');

const sharePrice = (row: SharedListingRow): string =>
    formatListingPrice({
        amount: row.price_amount,
        currency: row.price_currency,
        rates: row.rates,
    });

// null means every group granted (the pre-grants default).
const grantedFieldGroups = ref<string[]>(
    props.partner.field_groups ??
        props.availableFieldGroups.map((group) => group.value),
);
const savingGrants = ref(false);

const toggleFieldGroup = (value: string) => {
    grantedFieldGroups.value = grantedFieldGroups.value.includes(value)
        ? grantedFieldGroups.value.filter((existing) => existing !== value)
        : [...grantedFieldGroups.value, value];
};

const saveFieldGroups = () => {
    savingGrants.value = true;
    router.put(
        updateFieldGroups.url(props.partner.id),
        { field_groups: grantedFieldGroups.value },
        {
            preserveScroll: true,
            onFinish: () => (savingGrants.value = false),
        },
    );
};

setLayoutProps({
    breadcrumbs: [
        { title: 'Partners', href: index() },
        { title: props.partner.domain, href: show(props.partner.id) },
    ],
});

const toast = useToast();

const act = (url: { url: string; method: string }) => {
    router.post(
        url.url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.add({
                    title: Object.values(errors)[0] ?? 'Action failed.',
                    color: 'error',
                });
            },
        },
    );
};

const trustColor = (level: string) =>
    ({ verified: 'success', provisional: 'warning', blocked: 'error' })[
        level
    ] ?? 'neutral';
</script>

<template>
    <Head :title="partner.domain" />

    <div class="mx-auto w-full max-w-3xl space-y-8 px-4 py-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-xl font-semibold tracking-tight">
                        {{ partner.domain }}
                    </h2>
                    <UBadge
                        :color="trustColor(partner.trust_level)"
                        variant="subtle"
                        :label="partner.trust_level_label"
                    />
                    <UBadge
                        v-if="partner.is_hidden"
                        color="neutral"
                        variant="outline"
                        label="Hidden from public"
                    />
                    <UBadge
                        v-else-if="partner.is_stale"
                        color="warning"
                        variant="outline"
                        label="Stale"
                    />
                </div>
                <p class="mt-1 text-sm text-muted">
                    {{ partner.listing_copies_count }} synced listings
                    <template v-if="partner.approved_by">
                        · approved by {{ partner.approved_by }}
                    </template>
                </p>
                <p v-if="partner.is_hidden" class="mt-1 text-sm text-muted">
                    This partner has been unreachable long enough that its
                    listings are no longer served publicly. The copies are kept
                    and stay visible here — a partner that stops answering can
                    never withdraw its own listings, so they would otherwise be
                    published as current indefinitely.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <UButton
                    v-if="partner.trust_level !== 'verified'"
                    color="success"
                    icon="i-lucide-check"
                    label="Approve"
                    @click="act(approve(partner.id))"
                />
                <UButton
                    v-if="partner.trust_level !== 'blocked'"
                    color="error"
                    variant="soft"
                    icon="i-lucide-ban"
                    label="Block"
                    @click="act(block(partner.id))"
                />
                <UButton
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-key-round"
                    label="Refresh keys"
                    @click="act(refreshKeys(partner.id))"
                />
                <UButton
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-refresh-cw"
                    label="Sync now"
                    @click="act(sync(partner.id))"
                />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <UCard>
                <template #header>
                    <h3 class="font-semibold">Node identity</h3>
                </template>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-muted">Node UUID</dt>
                        <dd class="font-mono text-xs break-all">
                            {{ partner.node_uuid ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted">Keys fetched</dt>
                        <dd>{{ partner.keys_fetched_at ?? 'never' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted">Last reachable</dt>
                        <dd>{{ partner.last_ok_at ?? 'never' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted">Last synced</dt>
                        <dd>{{ partner.last_synced_at ?? 'never' }}</dd>
                    </div>
                    <div v-if="partner.consecutive_failures > 0">
                        <dt class="text-muted">Consecutive failures</dt>
                        <dd class="text-error">
                            {{ partner.consecutive_failures }}
                        </dd>
                    </div>
                </dl>
            </UCard>

            <UCard>
                <template #header>
                    <h3 class="font-semibold">Published keys</h3>
                </template>
                <ul class="space-y-3 text-sm">
                    <li
                        v-for="key in partner.keys"
                        :key="key.key_id"
                        class="flex items-center justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <p class="font-mono text-xs">{{ key.key_id }}</p>
                            <p class="text-xs text-muted">
                                {{ key.algorithm }}
                                <template v-if="key.created_at">
                                    · {{ key.created_at }}
                                </template>
                            </p>
                        </div>
                        <UBadge
                            v-if="key.pinned"
                            color="info"
                            variant="subtle"
                            size="sm"
                            label="Pinned"
                        />
                    </li>
                </ul>
            </UCard>
        </div>

        <UCard>
            <template #header>
                <div>
                    <h3 class="font-semibold">Acceptance policy</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        What happens to this partner's listings after sync.
                        Copies are always stored; this decides whether they also
                        publish without a person importing each one. Listings
                        with an unreviewed vessel-identity conflict, or whose
                        usage terms forbid display, always wait for a human.
                    </p>
                </div>
            </template>
            <div class="space-y-4">
                <fieldset class="space-y-2">
                    <label
                        v-for="option in availableAcceptancePolicies"
                        :key="option.value"
                        class="flex cursor-pointer items-start gap-2 rounded-md border border-default p-3"
                        :class="{
                            'border-primary bg-primary/5':
                                acceptancePolicy === option.value,
                        }"
                    >
                        <input
                            v-model="acceptancePolicy"
                            type="radio"
                            name="acceptance_policy"
                            :value="option.value"
                            class="mt-1"
                        />
                        <span>
                            <span class="block text-sm font-medium">{{
                                option.label
                            }}</span>
                            <span class="block text-xs text-muted">{{
                                option.hint
                            }}</span>
                        </span>
                    </label>
                </fieldset>
                <p
                    v-if="
                        partner.effective_acceptance_policy !==
                        partner.acceptance_policy
                    "
                    class="rounded-md border border-info/40 bg-info/5 p-2 text-xs"
                >
                    Group membership loosens this partner's effective policy:
                    <template
                        v-for="(group, i) in partner.policy_groups"
                        :key="group.name"
                    >
                        <template v-if="i > 0">, </template>
                        “{{ group.name }}” sets
                        {{ group.policy_label.toLowerCase() }}
                    </template>
                    — the most permissive applies. Remove the partner from the
                    group to revoke it.
                </p>
                <div class="flex justify-end">
                    <UButton
                        label="Save policy"
                        :loading="savingPolicy"
                        @click="saveAcceptancePolicy"
                    />
                </div>
            </div>
        </UCard>

        <UCard>
            <template #header>
                <div>
                    <h3 class="font-semibold">Sharing scope</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        Which of this node's listings the partner's feed
                        contains. Narrowing to curated tombstones everything the
                        partner saw only through the everyone audience; explicit
                        shares survive either way.
                    </p>
                </div>
            </template>
            <div class="space-y-4">
                <fieldset class="space-y-2">
                    <label
                        v-for="option in availableSharingScopes"
                        :key="option.value"
                        class="flex cursor-pointer items-start gap-2 rounded-md border border-default p-3"
                        :class="{
                            'border-primary bg-primary/5':
                                sharingScope === option.value,
                        }"
                    >
                        <input
                            v-model="sharingScope"
                            type="radio"
                            name="sharing_scope"
                            :value="option.value"
                            class="mt-1"
                        />
                        <span>
                            <span class="block text-sm font-medium">{{
                                option.label
                            }}</span>
                            <span class="block text-xs text-muted">{{
                                option.hint
                            }}</span>
                        </span>
                    </label>
                </fieldset>
                <div class="flex justify-end">
                    <UButton
                        label="Save scope"
                        :loading="savingScope"
                        @click="saveSharingScope"
                    />
                </div>
            </div>
        </UCard>

        <UCard v-if="partner.sharing_scope === 'curated' && sharedListings">
            <template #header>
                <div>
                    <h3 class="font-semibold">Shared listings</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        The listings this curated partner receives. Listings
                        shared through a group stay granted by the group —
                        unticking here only removes the direct share.
                    </p>
                </div>
            </template>
            <div class="space-y-4">
                <!-- Sale and charter listings are never mixed in one list
                     (the table is the type — .ai/rules/models.md); the tabs
                     show one type at a time. -->
                <div class="flex gap-2">
                    <UButton
                        label="Sale"
                        size="sm"
                        :variant="
                            sharedListings.type === 'sale' ? 'solid' : 'outline'
                        "
                        color="neutral"
                        @click="switchListingType('sale')"
                    />
                    <UButton
                        label="Charter"
                        size="sm"
                        :variant="
                            sharedListings.type === 'charter'
                                ? 'solid'
                                : 'outline'
                        "
                        color="neutral"
                        @click="switchListingType('charter')"
                    />
                </div>

                <ListingFilterBar
                    v-if="filters"
                    :initial="filters"
                    :categories="categories ?? []"
                    :builders="builders"
                    :statuses="statuses"
                    show-year-range
                    show-power-sail
                    :extra="{ listing_type: sharedListings.type }"
                    placeholder="Search name, builder, or model…"
                />

                <div
                    class="flex items-center justify-between gap-2 text-xs text-muted"
                >
                    <p>
                        {{ sharedListings.items.length }} listing(s) shown ·
                        {{ selectedShares.size }} shared in this type
                    </p>
                    <div class="flex gap-2">
                        <UButton
                            label="Select all shown"
                            size="xs"
                            variant="ghost"
                            color="neutral"
                            @click="selectAllShown"
                        />
                        <UButton
                            label="Clear shown"
                            size="xs"
                            variant="ghost"
                            color="neutral"
                            @click="clearShown"
                        />
                    </div>
                </div>

                <p
                    v-if="!sharedListings.items.length"
                    class="text-sm text-muted"
                >
                    No listings match the current filters.
                </p>

                <div v-else class="max-h-96 space-y-1 overflow-y-auto">
                    <div
                        v-for="row in sharedListings.items"
                        :key="row.uuid"
                        class="flex items-start justify-between gap-3 rounded-md border border-default p-2"
                    >
                        <div class="flex min-w-0 items-start gap-2">
                            <UCheckbox
                                :model-value="selectedShares.has(row.uuid)"
                                :aria-label="row.name"
                                @update:model-value="toggleShare(row.uuid)"
                            />
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-medium">
                                        {{ row.name }}
                                    </p>
                                    <UBadge
                                        size="sm"
                                        variant="subtle"
                                        :label="row.status_label"
                                    />
                                    <UBadge
                                        v-if="row.has_sister_listing"
                                        size="sm"
                                        color="warning"
                                        variant="outline"
                                        :label="
                                            sharedListings.type === 'sale'
                                                ? 'Also listed for charter'
                                                : 'Also listed for sale'
                                        "
                                    />
                                </div>
                                <p class="text-xs text-muted">
                                    {{ shareMeta(row) }}
                                </p>
                                <p
                                    v-if="row.via_groups.length"
                                    class="text-xs text-info"
                                >
                                    Shared via
                                    {{ row.via_groups.join(', ') }}
                                </p>
                            </div>
                        </div>
                        <p class="shrink-0 text-sm">{{ sharePrice(row) }}</p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <UButton
                        label="Save shared listings"
                        :loading="savingShares"
                        @click="saveShares"
                    />
                </div>
            </div>
        </UCard>

        <UCard>
            <template #header>
                <div>
                    <h3 class="font-semibold">Import types</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        Which of this partner's listing types are published or
                        reach the review queue. Copies of the excluded type are
                        still synced and stored, but never surface.
                    </p>
                </div>
            </template>
            <div class="space-y-4">
                <fieldset class="space-y-2">
                    <label
                        v-for="option in availableImportTypes"
                        :key="option.value"
                        class="flex cursor-pointer items-start gap-2 rounded-md border border-default p-3"
                        :class="{
                            'border-primary bg-primary/5':
                                importTypes === option.value,
                        }"
                    >
                        <input
                            v-model="importTypes"
                            type="radio"
                            name="import_types"
                            :value="option.value"
                            class="mt-1"
                        />
                        <span>
                            <span class="block text-sm font-medium">{{
                                option.label
                            }}</span>
                            <span class="block text-xs text-muted">{{
                                option.hint
                            }}</span>
                        </span>
                    </label>
                </fieldset>
                <div class="flex justify-end">
                    <UButton
                        label="Save import types"
                        :loading="savingImportTypes"
                        @click="saveImportTypes"
                    />
                </div>
            </div>
        </UCard>

        <UCard>
            <template #header>
                <div>
                    <h3 class="font-semibold">Sharing permissions</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        The field groups this partner receives. Withheld values
                        are nulled server-side before the payload is signed;
                        saving re-gates every visible listing so the partner
                        picks up the change on its next poll.
                    </p>
                </div>
            </template>
            <div class="space-y-4">
                <div class="grid gap-1 sm:grid-cols-2">
                    <UCheckbox
                        v-for="group in availableFieldGroups"
                        :key="group.value"
                        :model-value="grantedFieldGroups.includes(group.value)"
                        :label="group.label"
                        @update:model-value="toggleFieldGroup(group.value)"
                    />
                </div>
                <div class="flex justify-end">
                    <UButton
                        label="Save permissions"
                        :loading="savingGrants"
                        @click="saveFieldGroups"
                    />
                </div>
            </div>
        </UCard>
    </div>
</template>
