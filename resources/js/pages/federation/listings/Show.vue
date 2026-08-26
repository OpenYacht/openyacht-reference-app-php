<script setup lang="ts">
import { Head, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CharterDetails from '@/components/CharterDetails.vue';
import type { CharterRate } from '@/lib/listingPrice';
import { formatListingPrice } from '@/lib/listingPrice';
import { store as importCopy } from '@/routes/imported-yachts';
import { index as charterIndex } from '@/routes/synced-charter-listings';
import { dismissConflict as dismissConflictRoute } from '@/routes/synced-listings';
import { index, show } from '@/routes/synced-listings';

type Broker = {
    name: string | null;
    title: string | null;
    email: string | null;
    phone: string | null;
};

type Copy = {
    id: number;
    name: string | null;
    type: string;
    status: string;
    status_label: string;
    imported: boolean;
    importable: boolean;
    is_stale: boolean;
    is_tombstoned: boolean;
    identity_conflicts: {
        with: string;
        matched_on: string;
        label: string;
        uuid: string | null;
    }[];
    conflict_reviewed: boolean;
    node_name: string;
    builder_name: string | null;
    model_name: string | null;
    year_built: number | null;
    loa_m: number | null;
    price_amount: string | null;
    price_currency: string | null;
    location_display: string | null;
    summary: string | null;
    hero_url: string | null;
    gallery: {
        url: string;
        thumbnail_url: string | null;
        caption: string | null;
    }[];
    vessel: Record<string, unknown> | null;
    specifications: Record<string, unknown> | null;
    descriptions: { section: string | null; content: string }[];
    features: { category: string | null; name: string; slug: string | null }[];
    brokers: Broker[];
    price_history: { amount: string; currency: string; changed_at: string }[];
    compliance: Record<string, unknown> | null;
    attribution: string | null;
    provenance: {
        canonical: string;
        authority: string;
        received_at: string;
        signature_verified: boolean;
    };
    payload: Record<string, unknown>;
};

const props = defineProps<{
    copy: Copy;
}>();

setLayoutProps({
    breadcrumbs: [
        props.copy.type === 'charter'
            ? { title: 'Synced charter listings', href: charterIndex() }
            : { title: 'Synced sale listings', href: index() },
        { title: props.copy.name ?? 'Unnamed', href: show(props.copy.id) },
    ],
});

const page = usePage();

const doImport = () => {
    router.post(importCopy.url({ copy: props.copy.id }), {});
};

const dismissConflict = () => {
    router.post(
        dismissConflictRoute.url(props.copy.id),
        {},
        { preserveScroll: true },
    );
};

const subtitle = computed(() =>
    [
        [props.copy.builder_name, props.copy.model_name]
            .filter(Boolean)
            .join(' '),
        props.copy.year_built,
        props.copy.loa_m ? `${props.copy.loa_m}m` : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

// The charter block lives in the verbatim payload; charter copies carry
// price: null by design, so the headline price falls back to the weekly
// rate range.
const charter = computed(
    () =>
        (props.copy.payload.charter ?? null) as Record<string, unknown> | null,
);

const priceLine = computed(() =>
    formatListingPrice({
        amount: props.copy.price_amount,
        currency: props.copy.price_currency,
        rates: (charter.value?.rates ?? null) as CharterRate[] | null,
    }),
);

const specLabels: Record<string, string> = {
    beam_m: 'Beam',
    draft_max_m: 'Max draft',
    draft_min_m: 'Min draft',
    lwl_m: 'Waterline length',
    gross_tonnage: 'Gross tonnage',
    displacement_kg: 'Displacement',
    fuel_capacity_l: 'Fuel capacity',
    water_capacity_l: 'Water capacity',
    holding_tank_l: 'Holding tank',
    cruise_speed_kn: 'Cruise speed',
    max_speed_kn: 'Max speed',
    range_nmi: 'Range',
    fuel_consumption_lph: 'Fuel consumption',
    hull_material: 'Hull material',
    superstructure_material: 'Superstructure',
    deck_material: 'Deck',
    hull_shape: 'Hull shape',
    hull_color: 'Hull colour',
    naval_architect: 'Naval architect',
    exterior_designer: 'Exterior designer',
    interior_designer: 'Interior designer',
    fuel_type: 'Fuel',
    flag: 'Flag',
    registry_port: 'Registry port',
    cabins: 'Cabins',
    sleeps: 'Sleeps',
    heads: 'Heads',
};

const specUnits: Record<string, string> = {
    beam_m: ' m',
    draft_max_m: ' m',
    draft_min_m: ' m',
    lwl_m: ' m',
    displacement_kg: ' kg',
    fuel_capacity_l: ' L',
    water_capacity_l: ' L',
    holding_tank_l: ' L',
    cruise_speed_kn: ' kn',
    max_speed_kn: ' kn',
    range_nmi: ' nmi',
    fuel_consumption_lph: ' L/h',
};

const specRows = computed(() =>
    Object.entries(specLabels)
        .map(([key, label]) => ({
            key,
            label,
            value: props.copy.specifications?.[key],
        }))
        .filter(
            (row) =>
                row.value !== null &&
                row.value !== undefined &&
                row.value !== '',
        )
        .map((row) => ({
            ...row,
            display: `${row.value}${specUnits[row.key] ?? ''}`,
        })),
);

const engines = computed(
    () =>
        (props.copy.specifications?.engines ?? []) as Record<string, unknown>[],
);

const featureGroups = computed(() => {
    const groups = new Map<string, string[]>();

    for (const feature of props.copy.features ?? []) {
        const category = feature.category ?? 'other';

        groups.set(category, [...(groups.get(category) ?? []), feature.name]);
    }

    return [...groups.entries()].map(([category, names]) => ({
        category,
        names,
    }));
});

const complianceRows = computed(() =>
    Object.entries(props.copy.compliance ?? {})
        .filter(
            ([key, value]) =>
                key !== 'classification' &&
                value !== null &&
                value !== undefined,
        )
        .map(([key, value]) => ({
            label: key.replaceAll('_', ' '),
            value:
                typeof value === 'boolean'
                    ? value
                        ? 'Yes'
                        : 'No'
                    : String(value),
        })),
);

const payloadJson = computed(() => JSON.stringify(props.copy.payload, null, 2));
</script>

<template>
    <Head :title="copy.name ?? 'Unnamed'" />

    <div class="mx-auto w-full max-w-5xl space-y-8 px-4 py-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        {{ copy.name ?? 'Unnamed' }}
                    </h2>
                    <UBadge
                        v-if="copy.status !== 'active'"
                        color="neutral"
                        variant="subtle"
                        :label="copy.status_label"
                    />
                    <UBadge
                        v-if="copy.imported"
                        color="info"
                        variant="subtle"
                        label="Imported"
                    />
                    <UBadge
                        v-if="copy.is_stale"
                        color="warning"
                        variant="outline"
                        label="Stale"
                    />
                    <UBadge
                        v-if="copy.is_tombstoned"
                        color="neutral"
                        variant="outline"
                        label="Removed by partner"
                    />
                </div>
                <p v-if="subtitle" class="mt-1 text-muted">{{ subtitle }}</p>
                <p class="text-sm text-muted">
                    Shared by {{ copy.node_name }}
                    <template v-if="copy.location_display">
                        · {{ copy.location_display }}
                    </template>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <p class="text-xl font-semibold whitespace-nowrap">
                    {{ priceLine }}
                </p>
                <UButton
                    v-if="
                        copy.importable &&
                        !copy.imported &&
                        page.props.auth.canManageListings
                    "
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-download"
                    label="Import"
                    @click="doImport"
                />
            </div>
        </div>

        <!-- ID-9: hard-matched vessels are retained and flagged, never
             auto-resolved. Dismissing records the human review; display
             stays the operator's decision either way. -->
        <div
            v-if="copy.identity_conflicts.length"
            class="rounded-lg border p-4"
            :class="
                copy.conflict_reviewed
                    ? 'border-default bg-elevated/40'
                    : 'border-error/40 bg-error/5'
            "
        >
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
            >
                <div>
                    <p class="font-medium">
                        {{
                            copy.conflict_reviewed
                                ? 'Vessel-identity conflict (reviewed)'
                                : 'Possible duplicate vessel — review needed'
                        }}
                    </p>
                    <ul class="mt-1 space-y-0.5 text-sm text-muted">
                        <li
                            v-for="(conflict, i) in copy.identity_conflicts"
                            :key="i"
                        >
                            Matches
                            {{
                                conflict.with === 'own'
                                    ? 'your own listing'
                                    : 'another partner’s listing'
                            }}
                            “{{ conflict.label }}” on
                            {{
                                conflict.matched_on === 'vessel_profile'
                                    ? 'builder, model, year, and length'
                                    : conflict.matched_on.toUpperCase()
                            }}
                        </li>
                    </ul>
                </div>
                <UButton
                    v-if="
                        !copy.conflict_reviewed &&
                        page.props.auth.canManageListings
                    "
                    color="neutral"
                    variant="outline"
                    size="sm"
                    label="Dismiss — I reviewed both"
                    @click="dismissConflict"
                />
            </div>
        </div>

        <div
            v-if="copy.hero_url"
            class="overflow-hidden rounded-xl bg-elevated"
        >
            <!-- Remote partner media: https-validated server-side (FP-14),
                 rendered at the single wire size. -->
            <img
                :src="copy.hero_url"
                :alt="copy.name ?? 'Listing image'"
                class="aspect-video w-full object-cover"
            />
        </div>

        <p v-if="copy.summary" class="max-w-3xl text-muted">
            {{ copy.summary }}
        </p>

        <section v-if="copy.descriptions.length" class="space-y-6">
            <article
                v-for="(description, i) in copy.descriptions"
                :key="i"
                class="max-w-3xl"
            >
                <h3
                    v-if="description.section"
                    class="mb-2 text-lg font-semibold capitalize"
                >
                    {{ description.section }}
                </h3>
                <!-- Content is sanitised server-side to the wire schema's
                     restricted HTML subset (LS-5). -->
                <div
                    class="prose prose-sm max-w-none text-default"
                    v-html="description.content"
                />
            </article>
        </section>

        <CharterDetails v-if="copy.type === 'charter'" :charter="charter" />

        <section v-if="specRows.length">
            <h3 class="mb-3 text-lg font-semibold">Specifications</h3>
            <dl
                class="grid grid-cols-2 gap-x-8 gap-y-2 rounded-lg border border-default p-4 text-sm sm:grid-cols-3"
            >
                <template v-for="row in specRows" :key="row.key">
                    <div>
                        <dt class="text-muted">{{ row.label }}</dt>
                        <dd class="font-medium">{{ row.display }}</dd>
                    </div>
                </template>
            </dl>
        </section>

        <section v-if="engines.length">
            <h3 class="mb-3 text-lg font-semibold">Engines</h3>
            <div class="overflow-x-auto rounded-lg border border-default">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-default bg-elevated/50">
                            <th class="p-3 text-left font-medium">Engine</th>
                            <th class="p-3 text-left font-medium">Power</th>
                            <th class="p-3 text-left font-medium">Hours</th>
                            <th class="p-3 text-left font-medium">Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(engine, i) in engines"
                            :key="i"
                            class="border-b border-default last:border-b-0"
                        >
                            <td class="p-3">
                                {{ engine.make }} {{ engine.model }}
                                <span v-if="engine.year" class="text-muted"
                                    >({{ engine.year }})</span
                                >
                            </td>
                            <td class="p-3">
                                <template v-if="engine.power_hp"
                                    >{{ engine.power_hp }} hp</template
                                >
                            </td>
                            <td class="p-3">{{ engine.hours ?? '—' }}</td>
                            <td class="p-3">{{ engine.location ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="featureGroups.length">
            <h3 class="mb-3 text-lg font-semibold">Features</h3>
            <div class="space-y-3">
                <div v-for="group in featureGroups" :key="group.category">
                    <p class="mb-1 text-sm text-muted capitalize">
                        {{ group.category }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <UBadge
                            v-for="name in group.names"
                            :key="name"
                            color="neutral"
                            variant="subtle"
                            :label="name"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section v-if="copy.gallery.length">
            <h3 class="mb-3 text-lg font-semibold">Gallery</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <a
                    v-for="(item, i) in copy.gallery"
                    :key="i"
                    :href="item.url"
                    target="_blank"
                    rel="noopener"
                >
                    <img
                        :src="item.thumbnail_url ?? item.url"
                        :alt="item.caption ?? copy.name ?? 'Listing image'"
                        class="aspect-[4/3] w-full rounded-lg object-cover"
                        loading="lazy"
                    />
                </a>
            </div>
        </section>

        <section v-if="copy.brokers.length" class="grid gap-4 sm:grid-cols-2">
            <UCard>
                <template #header>
                    <h3 class="font-semibold">Brokers</h3>
                </template>
                <ul class="space-y-3 text-sm">
                    <li v-for="(broker, i) in copy.brokers" :key="i">
                        <p class="font-medium">{{ broker.name }}</p>
                        <p v-if="broker.title" class="text-muted">
                            {{ broker.title }}
                        </p>
                        <p v-if="broker.email" class="text-muted">
                            {{ broker.email }}
                        </p>
                        <p v-if="broker.phone" class="text-muted">
                            {{ broker.phone }}
                        </p>
                    </li>
                </ul>
            </UCard>
        </section>

        <section
            v-if="copy.price_history.length > 1 || complianceRows.length"
            class="grid gap-4 sm:grid-cols-2"
        >
            <UCard v-if="copy.price_history.length > 1">
                <template #header>
                    <h3 class="font-semibold">Price history</h3>
                </template>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="(entry, i) in copy.price_history"
                        :key="i"
                        class="flex justify-between gap-4"
                    >
                        <span class="text-muted">{{
                            entry.changed_at.slice(0, 10)
                        }}</span>
                        <span class="font-medium"
                            >{{ entry.amount }} {{ entry.currency }}</span
                        >
                    </li>
                </ul>
            </UCard>

            <UCard v-if="complianceRows.length">
                <template #header>
                    <h3 class="font-semibold">Compliance</h3>
                </template>
                <dl class="space-y-2 text-sm">
                    <div
                        v-for="row in complianceRows"
                        :key="row.label"
                        class="flex justify-between gap-4"
                    >
                        <dt class="text-muted capitalize">{{ row.label }}</dt>
                        <dd class="font-medium">{{ row.value }}</dd>
                    </div>
                </dl>
            </UCard>
        </section>

        <UCard>
            <template #header>
                <h3 class="font-semibold">Provenance</h3>
            </template>
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="text-muted">Shared by</dt>
                    <dd>
                        {{ copy.node_name }}
                        <span class="text-muted"
                            >({{ copy.provenance.authority }})</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-muted">Canonical listing</dt>
                    <!-- The canonical URI is the partner's signed API — an
                         identifier, not a browsable link (ID-2). -->
                    <dd class="font-mono text-xs break-all">
                        {{ copy.provenance.canonical }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted">Received</dt>
                    <dd>
                        {{ copy.provenance.received_at }}
                        <UBadge
                            v-if="copy.provenance.signature_verified"
                            color="success"
                            variant="subtle"
                            size="sm"
                            label="Signature verified"
                        />
                    </dd>
                </div>
            </dl>
            <p v-if="copy.attribution" class="mt-3 text-xs text-dimmed italic">
                {{ copy.attribution }}
            </p>
        </UCard>

        <UCollapsible>
            <UButton
                color="neutral"
                variant="ghost"
                icon="i-lucide-braces"
                label="Raw wire payload"
                trailing-icon="i-lucide-chevron-down"
            />
            <template #content>
                <pre
                    class="mt-2 max-h-[32rem] overflow-auto rounded-lg border border-default bg-elevated/50 p-4 font-mono text-xs"
                    >{{ payloadJson }}</pre>
            </template>
        </UCollapsible>
    </div>
</template>
