<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import CharterYachtForm from '@/components/CharterYachtForm.vue';
import type {
    CrewMemberForm,
    OperatingAreaForm,
    RateForm,
    SpecificationsForm,
} from '@/components/listing-form/types';
import {
    emptyCrewMember,
    emptyEngine,
    emptyGenerator,
    emptyOperatingArea,
    emptyRate,
    emptySpecifications,
    normalizeCompliance,
} from '@/components/listing-form/types';
import ListingMediaManager from '@/components/ListingMediaManager.vue';
import type { MediaItem } from '@/components/ListingMediaManager.vue';
import ListingSharingCard from '@/components/ListingSharingCard.vue';
import { edit, index, transition, update } from '@/routes/charter-yachts';
import { update as updateAudience } from '@/routes/charter-yachts/audience';
import {
    destroy as destroyMedia,
    store as storeMedia,
    update as updateMedia,
} from '@/routes/charter-yachts/media';

type Yacht = {
    id: number;
    uuid: string;
    canonical_uri: string;
    status: string;
    status_label: string;
    allowed_transitions: { value: string; label: string }[];
    name: string;
    summary: string | null;
    condition: string | null;
    location_display: string | null;
    location_city: string | null;
    location_state: string | null;
    location_country: string | null;
    location_marina: string | null;
    location_lat: number | null;
    location_lon: number | null;
    builder_slug: string | null;
    builder_name: string | null;
    model_name: string | null;
    model_slug: string | null;
    year_built: number | null;
    refit_year: number | null;
    loa_m: number | null;
    hin: string | null;
    imo: string | null;
    mmsi: string | null;
    official_number: string | null;
    previous_names: string;
    specifications: Record<string, unknown>;
    descriptions: { section: string | null; content: string }[];
    features: {
        category: string | null;
        name: string;
        slug: string | null;
        quantity?: number | null;
    }[];
    compliance: Record<string, unknown>;
    rates: Partial<RateForm>[];
    operating_areas: Partial<OperatingAreaForm>[];
    summer_base_port: string | null;
    winter_base_port: string | null;
    crew: Partial<CrewMemberForm>[];
    crew_attested: boolean;
    videos: { url: string; caption: string | null }[];
    tours: { url: string; caption: string | null }[];
    profile: MediaItem | null;
    gallery: MediaItem[];
    layouts: MediaItem[];
    documents: MediaItem[];
};

const triState = (value: unknown): string =>
    value === true ? '1' : value === false ? '0' : 'unknown';

const hydrateSpecifications = (
    stored: Record<string, unknown>,
): SpecificationsForm => {
    const base = emptySpecifications();

    for (const key of Object.keys(base) as (keyof SpecificationsForm)[]) {
        const value = stored[key];

        if (value === null || value === undefined) {
            continue;
        }

        if (
            base[key] !== null &&
            typeof base[key] === 'object' &&
            !Array.isArray(base[key]) &&
            typeof value === 'object' &&
            !Array.isArray(value)
        ) {
            Object.assign(base[key] as object, value);
        } else {
            // @ts-expect-error — keys are validated server-side.
            base[key] = value;
        }
    }

    const withoutNulls = (row: object) =>
        Object.fromEntries(
            Object.entries(row).filter(([, value]) => value !== null),
        );

    base.engines = (base.engines ?? []).map((engine) => ({
        ...emptyEngine(),
        ...withoutNulls(engine),
    }));
    base.generators = (base.generators ?? []).map((generator) => ({
        ...emptyGenerator(),
        ...withoutNulls(generator),
    }));

    return base;
};

type Sharing = {
    audience: string;
    selected_partner_ids: number[];
    selected_group_ids: number[];
    partners: {
        id: number;
        domain: string;
        node_name: string | null;
        sharing_scope: string;
    }[];
    groups: { id: number; name: string; members_count: number }[];
};

const props = defineProps<{
    yacht: Yacht;
    builders: { slug: string; name: string; country: string | null }[];
    categories: { slug: string; name: string }[];
    destinations: { slug: string; name: string; parent: string | null }[];
    map: { provider: 'openstreetmap' | 'mapbox'; mapbox_token: string | null };
    sharing: Sharing;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Charter yachts', href: index() },
        { title: props.yacht.name, href: edit(props.yacht.id) },
    ],
});

const withoutNulls = (row: object) =>
    Object.fromEntries(
        Object.entries(row).filter(([, value]) => value !== null),
    );

const form = useForm({
    name: props.yacht.name,
    summary: props.yacht.summary ?? '',
    condition: props.yacht.condition ?? '',
    location_display: props.yacht.location_display ?? '',
    location_city: props.yacht.location_city ?? '',
    location_state: props.yacht.location_state ?? '',
    location_country: props.yacht.location_country ?? '',
    location_marina: props.yacht.location_marina ?? '',
    location_lat: props.yacht.location_lat,
    location_lon: props.yacht.location_lon,
    builder_slug: props.yacht.builder_slug,
    builder_name: props.yacht.builder_name ?? '',
    model_name: props.yacht.model_name ?? '',
    model_slug: props.yacht.model_slug ?? '',
    year_built: props.yacht.year_built,
    refit_year: props.yacht.refit_year,
    loa_m: props.yacht.loa_m,
    hin: props.yacht.hin ?? '',
    imo: props.yacht.imo ?? '',
    mmsi: props.yacht.mmsi ?? '',
    official_number: props.yacht.official_number ?? '',
    previous_names: props.yacht.previous_names,
    specifications: hydrateSpecifications(props.yacht.specifications),
    descriptions: props.yacht.descriptions.map((section) => ({
        section: section.section ?? '',
        content: section.content,
    })),
    features: props.yacht.features.map((feature) => ({
        category: feature.category ?? '',
        name: feature.name,
        slug: feature.slug ?? '',
        quantity: feature.quantity ?? null,
    })),
    videos: props.yacht.videos.map((video) => ({
        url: video.url,
        caption: video.caption ?? '',
    })),
    tours: props.yacht.tours.map((tour) => ({
        url: tour.url,
        caption: tour.caption ?? '',
    })),
    compliance: {
        not_for_sale_to_us_residents_in_us_waters: triState(
            props.yacht.compliance.not_for_sale_to_us_residents_in_us_waters,
        ),
        vat_status: String(props.yacht.compliance.vat_status ?? ''),
        ce_certified: triState(props.yacht.compliance.ce_certified),
        mca_compliant: triState(props.yacht.compliance.mca_compliant),
        classification: (
            (props.yacht.compliance.classification ?? []) as {
                society?: string | null;
                notation?: string | null;
                next_survey_due?: string | null;
            }[]
        ).map((entry) => ({
            society: entry.society ?? '',
            notation: entry.notation ?? '',
            next_survey_due: entry.next_survey_due ?? '',
        })),
    },
    rates: props.yacht.rates.map((rate) => ({
        ...emptyRate(),
        ...withoutNulls(rate),
    })),
    // A stored null slug means "deliberately unlisted" and must survive
    // the round trip — it is not a missing value.
    operating_areas: props.yacht.operating_areas.map((area) => ({
        ...emptyOperatingArea(),
        ...withoutNulls(area),
        slug: area.slug ?? null,
    })),
    summer_base_port: props.yacht.summer_base_port ?? '',
    winter_base_port: props.yacht.winter_base_port ?? '',
    crew: props.yacht.crew.map((member) => ({
        ...emptyCrewMember(),
        ...withoutNulls(member),
    })),
    crew_attested: props.yacht.crew_attested,
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            compliance: normalizeCompliance(data.compliance),
        }))
        .submit(update(props.yacht.id));

const changeStatus = (status: string) => {
    router.post(
        transition.url({ charterYacht: props.yacht.id }),
        { status },
        { preserveScroll: true },
    );
};

const mediaStoreUrl = storeMedia.url({ charterYacht: props.yacht.id });
const mediaUpdateUrl = (mediaId: number) =>
    updateMedia.url({ charterYacht: props.yacht.id, media: mediaId });
const mediaDestroyUrl = (mediaId: number) =>
    destroyMedia.url({ charterYacht: props.yacht.id, media: mediaId });

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
    <Head :title="yacht.name" />

    <div class="mx-auto w-full max-w-3xl space-y-8 px-4 py-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h2 class="text-xl font-semibold tracking-tight">
                        {{ yacht.name }}
                    </h2>
                    <UBadge
                        :color="statusColor(yacht.status)"
                        variant="subtle"
                        :label="yacht.status_label"
                    />
                </div>
                <p class="mt-1 font-mono text-xs break-all text-muted">
                    {{ yacht.canonical_uri }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <UButton
                    v-for="target in yacht.allowed_transitions"
                    :key="target.value"
                    :color="target.value === 'active' ? 'success' : 'neutral'"
                    :variant="target.value === 'active' ? 'solid' : 'outline'"
                    size="sm"
                    :label="`Mark ${target.label.toLowerCase()}`"
                    @click="changeStatus(target.value)"
                />
            </div>
        </div>

        <ListingMediaManager
            :yacht-name="yacht.name"
            :profile="yacht.profile"
            :gallery="yacht.gallery"
            :layouts="yacht.layouts"
            :documents="yacht.documents"
            :store-url="mediaStoreUrl"
            :update-url="mediaUpdateUrl"
            :destroy-url="mediaDestroyUrl"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <CharterYachtForm
                :form="form"
                :builders="builders"
                :categories="categories"
                :destinations="destinations"
                :map="map"
            />

            <div class="flex justify-end">
                <UButton
                    type="submit"
                    :loading="form.processing"
                    label="Save changes"
                />
            </div>
        </form>

        <ListingSharingCard
            :update-url="updateAudience.url(yacht.id)"
            :audience="sharing.audience"
            :selected-partner-ids="sharing.selected_partner_ids"
            :selected-group-ids="sharing.selected_group_ids"
            :partners="sharing.partners"
            :groups="sharing.groups"
        />
    </div>
</template>
