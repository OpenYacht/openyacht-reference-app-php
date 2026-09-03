<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import CharterDetails from '@/components/CharterDetails.vue';
import RemoteMediaSections from '@/components/RemoteMediaSections.vue';
import type { RemoteMedia } from '@/components/RemoteMediaSections.vue';
import type { CharterRate } from '@/lib/listingPrice';
import { formatListingPrice } from '@/lib/listingPrice';
import { index as charterIndex } from '@/routes/imported-charter-yachts';
import { index, show } from '@/routes/imported-yachts';

type SrcsetMap = Record<number, string>;

type GalleryItem = {
    id: number;
    kind: string;
    caption: string | null;
    srcset: SrcsetMap;
};

type Broker = {
    name: string | null;
    title: string | null;
    email: string | null;
    phone: string | null;
};

type Yacht = {
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
    summary: string | null;
    attribution_text: string | null;
    is_stale: boolean;
    is_hidden: boolean;
    hero: SrcsetMap;
    gallery: GalleryItem[];
    remote_media: RemoteMedia;
    vessel: Record<string, unknown> | null;
    specifications: Record<string, unknown> | null;
    descriptions: { section: string | null; content: string }[];
    features: { category: string | null; name: string; slug: string | null }[];
    charter: Record<string, unknown> | null;
    brokers: Broker[];
    price_history: { amount: string; currency: string; changed_at: string }[];
    compliance: Record<string, unknown> | null;
    provenance: {
        canonical: string;
        authority: string;
        received_at: string;
        signature_verified: boolean;
    };
    payload: Record<string, unknown>;
};

const props = defineProps<{
    yacht: Yacht;
}>();

setLayoutProps({
    breadcrumbs: [
        props.yacht.type === 'charter'
            ? { title: 'Partner charter', href: charterIndex() }
            : { title: 'Partner sale', href: index() },
        {
            title: 'Imported',
            href: props.yacht.type === 'charter' ? charterIndex() : index(),
        },
        { title: props.yacht.name, href: show(props.yacht.id) },
    ],
});

const subtitle = computed(() =>
    [
        [props.yacht.builder_name, props.yacht.model_name]
            .filter(Boolean)
            .join(' '),
        props.yacht.year_built,
        props.yacht.loa_m ? `${props.yacht.loa_m}m` : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

const srcset = (map: SrcsetMap) =>
    Object.entries(map)
        .map(([width, url]) => `${url} ${width}w`)
        .join(', ');

const largest = (map: SrcsetMap) => {
    const widths = Object.keys(map).map(Number);

    return widths.length ? map[Math.max(...widths)] : null;
};

// Charter copies carry price: null by design — the headline price falls
// back to the weekly rate range from the charter block.
const priceLine = computed(() =>
    formatListingPrice({
        amount: props.yacht.price_amount,
        currency: props.yacht.price_currency,
        rates: (props.yacht.charter?.rates ?? null) as CharterRate[] | null,
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
            value: props.yacht.specifications?.[key],
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
        (props.yacht.specifications?.engines ?? []) as Record<
            string,
            unknown
        >[],
);

const featureGroups = computed(() => {
    const groups = new Map<string, string[]>();

    for (const feature of props.yacht.features ?? []) {
        const category = feature.category ?? 'other';

        groups.set(category, [...(groups.get(category) ?? []), feature.name]);
    }

    return [...groups.entries()].map(([category, names]) => ({
        category,
        names,
    }));
});

const identifiers = computed(() =>
    (['hin', 'imo', 'mmsi', 'official_number'] as const)
        .map((key) => ({ key, value: props.yacht.vessel?.[key] }))
        .filter((row) => row.value),
);

const complianceRows = computed(() =>
    Object.entries(props.yacht.compliance ?? {})
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

const payloadJson = computed(() =>
    JSON.stringify(props.yacht.payload, null, 2),
);
</script>

<template>
    <Head :title="yacht.name" />

    <div class="mx-auto w-full max-w-5xl space-y-8 px-4 py-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-2xl font-semibold tracking-tight">
                        {{ yacht.name }}
                    </h2>
                    <UBadge
                        v-if="yacht.status !== 'active'"
                        color="neutral"
                        variant="subtle"
                        :label="yacht.status_label"
                    />
                    <UBadge
                        v-if="yacht.is_hidden"
                        color="neutral"
                        variant="outline"
                        label="Hidden from public"
                    />
                    <UBadge
                        v-else-if="yacht.is_stale"
                        color="warning"
                        variant="outline"
                        label="Stale"
                    />
                </div>
                <p v-if="subtitle" class="mt-1 text-muted">{{ subtitle }}</p>
                <p v-if="yacht.location_display" class="text-sm text-muted">
                    {{ yacht.location_display }}
                </p>
            </div>
            <p class="text-xl font-semibold whitespace-nowrap">
                {{ priceLine }}
            </p>
        </div>

        <div
            v-if="largest(yacht.hero)"
            class="overflow-hidden rounded-xl bg-elevated"
        >
            <img
                :src="largest(yacht.hero)!"
                :srcset="srcset(yacht.hero)"
                sizes="(min-width: 1024px) 960px, 100vw"
                :alt="yacht.name"
                class="aspect-video w-full object-cover"
            />
        </div>

        <p v-if="yacht.summary" class="max-w-3xl text-muted">
            {{ yacht.summary }}
        </p>

        <section v-if="yacht.descriptions.length" class="space-y-6">
            <article
                v-for="(description, i) in yacht.descriptions"
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

        <CharterDetails
            v-if="yacht.type === 'charter'"
            :charter="yacht.charter"
        />

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

        <section v-if="yacht.gallery.length > 1">
            <h3 class="mb-3 text-lg font-semibold">Gallery</h3>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <img
                    v-for="item in yacht.gallery"
                    :key="item.id"
                    :src="largest(item.srcset) ?? undefined"
                    :srcset="srcset(item.srcset)"
                    sizes="(min-width: 640px) 33vw, 50vw"
                    :alt="item.caption ?? yacht.name"
                    class="aspect-[4/3] w-full rounded-lg object-cover"
                    loading="lazy"
                />
            </div>
        </section>

        <RemoteMediaSections :media="yacht.remote_media" />

        <section
            v-if="yacht.brokers.length || identifiers.length"
            class="grid gap-4 sm:grid-cols-2"
        >
            <UCard v-if="yacht.brokers.length">
                <template #header>
                    <h3 class="font-semibold">Brokers</h3>
                </template>
                <ul class="space-y-3 text-sm">
                    <li v-for="(broker, i) in yacht.brokers" :key="i">
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

            <UCard v-if="identifiers.length">
                <template #header>
                    <h3 class="font-semibold">Vessel identifiers</h3>
                </template>
                <dl class="space-y-2 text-sm">
                    <div v-for="row in identifiers" :key="row.key">
                        <dt class="text-muted uppercase">{{ row.key }}</dt>
                        <dd class="font-mono">{{ row.value }}</dd>
                    </div>
                </dl>
            </UCard>
        </section>

        <section
            v-if="yacht.price_history.length > 1 || complianceRows.length"
            class="grid gap-4 sm:grid-cols-2"
        >
            <UCard v-if="yacht.price_history.length > 1">
                <template #header>
                    <h3 class="font-semibold">Price history</h3>
                </template>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="(entry, i) in yacht.price_history"
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
                    <dt class="text-muted">Authority</dt>
                    <dd>{{ yacht.provenance.authority }}</dd>
                </div>
                <div>
                    <dt class="text-muted">Canonical listing</dt>
                    <!-- The canonical URI is the partner's signed API — an
                         identifier, not a browsable link (ID-2). -->
                    <dd class="font-mono text-xs break-all">
                        {{ yacht.provenance.canonical }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted">Received</dt>
                    <dd>{{ yacht.provenance.received_at }}</dd>
                </div>
            </dl>
            <p
                v-if="yacht.attribution_text"
                class="mt-3 text-xs text-dimmed italic"
            >
                {{ yacht.attribution_text }}
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
