<script lang="ts">
export type EngineForm = {
    make: string;
    model: string;
    year: number | null;
    type: string;
    drive_type: string;
    power_hp: number | null;
    power_kw: number | null;
    fuel_type: string;
    hours: number | null;
    hours_recorded_at: string;
    location: string;
};

export type GeneratorForm = {
    make: string;
    model: string;
    power_kw: number | null;
    hours: number | null;
    hours_recorded_at: string;
};

export type SpecificationsForm = {
    beam_m: number | null;
    draft_max_m: number | null;
    draft_min_m: number | null;
    lwl_m: number | null;
    lod_m: number | null;
    bridge_clearance_m: number | null;
    gross_tonnage: number | null;
    displacement_kg: number | null;
    fuel_capacity_l: number | null;
    water_capacity_l: number | null;
    holding_tank_l: number | null;
    cruise_speed_kn: number | null;
    max_speed_kn: number | null;
    range_nmi: number | null;
    fuel_consumption_lph: number | null;
    hull_material: string;
    superstructure_material: string;
    deck_material: string;
    hull_shape: string;
    hull_color: string;
    naval_architect: string;
    exterior_designer: string;
    interior_designer: string;
    fuel_type: string;
    flag: string;
    registry_port: string;
    power_or_sail: string;
    category: { name: string; slug: string };
    cabins: number | null;
    sleeps: number | null;
    heads: number | null;
    guests_cruising: number | null;
    guests_entertaining: number | null;
    cabin_config: Record<
        'double' | 'twin' | 'triple' | 'single' | 'convertible',
        number | null
    >;
    berth_config: Record<
        'king' | 'queen' | 'double' | 'twin' | 'single' | 'pullman' | 'bunk',
        number | null
    >;
    crew_accommodation: {
        cabins: number | null;
        berths: number | null;
        layout: string;
    };
    engines: EngineForm[];
    generators: GeneratorForm[];
    tenders: string;
};

export type YachtFormFields = {
    name: string;
    summary: string;
    condition: string;
    price_amount: string;
    price_currency: string;
    price_on_application: boolean;
    starting_price: boolean;
    location_display: string;
    location_city: string;
    location_state: string;
    location_country: string;
    location_marina: string;
    location_lat: number | null;
    location_lon: number | null;
    builder_slug: string | null;
    builder_name: string;
    model_name: string;
    model_slug: string;
    year_built: number | null;
    refit_year: number | null;
    loa_m: number | null;
    hin: string;
    imo: string;
    mmsi: string;
    official_number: string;
    previous_names: string;
    specifications: SpecificationsForm;
    descriptions: { section: string; content: string }[];
    features: { category: string; name: string; slug: string }[];
    compliance: {
        not_for_sale_to_us_residents_in_us_waters: string;
        vat_status: string;
        ce_certified: string;
        mca_compliant: string;
        classification: {
            society: string;
            notation: string;
            next_survey_due: string;
        }[];
    };
};

export function emptyEngine(): EngineForm {
    return {
        make: '',
        model: '',
        year: null,
        type: 'inboard',
        drive_type: '',
        power_hp: null,
        power_kw: null,
        fuel_type: 'diesel',
        hours: null,
        hours_recorded_at: '',
        location: '',
    };
}

export type ComplianceForm = YachtFormFields['compliance'];

/**
 * Tri-state selects use an 'unknown' sentinel (reka Select forbids empty
 * string values); the wire wants true/false/null.
 */
export function normalizeCompliance(compliance: ComplianceForm) {
    const triState = (value: string) =>
        value === '1' ? true : value === '0' ? false : null;

    return {
        ...compliance,
        not_for_sale_to_us_residents_in_us_waters: triState(
            compliance.not_for_sale_to_us_residents_in_us_waters,
        ),
        ce_certified: triState(compliance.ce_certified),
        mca_compliant: triState(compliance.mca_compliant),
    };
}

export function emptyGenerator(): GeneratorForm {
    return {
        make: '',
        model: '',
        power_kw: null,
        hours: null,
        hours_recorded_at: '',
    };
}

export function emptySpecifications(): SpecificationsForm {
    return {
        beam_m: null,
        draft_max_m: null,
        draft_min_m: null,
        lwl_m: null,
        lod_m: null,
        bridge_clearance_m: null,
        gross_tonnage: null,
        displacement_kg: null,
        fuel_capacity_l: null,
        water_capacity_l: null,
        holding_tank_l: null,
        cruise_speed_kn: null,
        max_speed_kn: null,
        range_nmi: null,
        fuel_consumption_lph: null,
        hull_material: '',
        superstructure_material: '',
        deck_material: '',
        hull_shape: '',
        hull_color: '',
        naval_architect: '',
        exterior_designer: '',
        interior_designer: '',
        fuel_type: '',
        flag: '',
        registry_port: '',
        power_or_sail: 'power',
        category: { name: '', slug: '' },
        cabins: null,
        sleeps: null,
        heads: null,
        guests_cruising: null,
        guests_entertaining: null,
        cabin_config: {
            double: null,
            twin: null,
            triple: null,
            single: null,
            convertible: null,
        },
        berth_config: {
            king: null,
            queen: null,
            double: null,
            twin: null,
            single: null,
            pullman: null,
            bunk: null,
        },
        crew_accommodation: { cabins: null, berths: null, layout: '' },
        engines: [],
        generators: [],
        tenders: '',
    };
}
</script>

<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import { computed, ref } from 'vue';
import LocationMapPicker from '@/components/LocationMapPicker.vue';
import type { MapConfig } from '@/components/LocationMapPicker.vue';

type Builder = {
    slug: string;
    name: string;
    country: string | null;
};

const props = defineProps<{
    builders: Builder[];
    categories: { slug: string; name: string }[];
    map: MapConfig;
    form: YachtFormFields & { errors: Partial<Record<string, string>> };
}>();

const setCoordinates = (lat: number, lon: number) => {
    props.form.location_lat = lat;
    props.form.location_lon = lon;
};

// The builder is a fixed registry choice with an explicit
// unlisted-builder escape hatch — not a free-text field.
const unlistedBuilder = ref(
    props.form.builder_slug === null && !!props.form.builder_name,
);

const builderItems = computed(() =>
    props.builders.map((builder) => ({
        label: builder.name,
        value: builder.slug,
    })),
);

const toggleUnlisted = (value: boolean) => {
    unlistedBuilder.value = value;

    if (value) {
        props.form.builder_slug = null;
    } else {
        props.form.builder_name = '';
    }
};

// Category follows the same pattern: a vocabulary choice that sets the
// slug (the server fills the canonical name), with an unlisted escape
// hatch for a free-text name and null slug.
const unlistedCategory = ref(
    !props.form.specifications.category.slug &&
        !!props.form.specifications.category.name,
);

const categoryItems = computed(() =>
    props.categories.map((category) => ({
        label: category.name,
        value: category.slug,
    })),
);

const toggleUnlistedCategory = (value: boolean) => {
    unlistedCategory.value = value;

    if (value) {
        props.form.specifications.category.slug = '';
    } else {
        props.form.specifications.category.name = '';
    }
};

// Per-description-section source view (the escape hatch for cleaning up
// pasted junk by hand; the server sanitises on save regardless).
const sourceMode = ref<Record<number, boolean>>({});

// Toolbar for the restricted description subset (listing-schema.md
// §Conventions: p, br, ul, ol, li, strong, em, h3, h4, a[href]) — the
// UEditor is headless without one.
const editorToolbarItems = [
    [
        { kind: 'undo', icon: 'i-lucide-undo-2', tooltip: { text: 'Undo' } },
        { kind: 'redo', icon: 'i-lucide-redo-2', tooltip: { text: 'Redo' } },
    ],
    [
        {
            kind: 'mark',
            mark: 'bold',
            icon: 'i-lucide-bold',
            tooltip: { text: 'Bold' },
        },
        {
            kind: 'mark',
            mark: 'italic',
            icon: 'i-lucide-italic',
            tooltip: { text: 'Italic' },
        },
    ],
    [
        {
            kind: 'paragraph',
            icon: 'i-lucide-pilcrow',
            tooltip: { text: 'Paragraph' },
        },
        {
            kind: 'heading',
            level: 3,
            icon: 'i-lucide-heading-3',
            tooltip: { text: 'Heading' },
        },
        {
            kind: 'heading',
            level: 4,
            icon: 'i-lucide-heading-4',
            tooltip: { text: 'Subheading' },
        },
    ],
    [
        {
            kind: 'bulletList',
            icon: 'i-lucide-list',
            tooltip: { text: 'Bullet list' },
        },
        {
            kind: 'orderedList',
            icon: 'i-lucide-list-ordered',
            tooltip: { text: 'Numbered list' },
        },
    ],
    [
        {
            kind: 'link',
            icon: 'i-lucide-link',
            tooltip: { text: 'Link (https only, never your own site)' },
        },
        {
            kind: 'clearFormatting',
            icon: 'i-lucide-remove-formatting',
            tooltip: { text: 'Clear formatting' },
        },
    ],
];

const currencies = ['EUR', 'USD', 'GBP', 'CHF', 'AUD'];
const hullShapes = [
    'planing',
    'semi_displacement',
    'displacement',
    'hydrofoil',
    'catamaran',
    'trimaran',
];
const fuelTypes = ['diesel', 'petrol', 'electric', 'hybrid'];
const engineTypes = ['inboard', 'outboard', 'saildrive', 'pod', 'jet'];
const triState = [
    { label: '—', value: 'unknown' },
    { label: 'Yes', value: '1' },
    { label: 'No', value: '0' },
];

const spec = computed(() => props.form.specifications);
</script>

<template>
    <div class="space-y-6">
        <!-- Listing -->
        <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Listing name" :error="form.errors.name" required>
                <UInput
                    v-model="form.name"
                    class="w-full"
                    placeholder="OASIS"
                />
            </UFormField>

            <UFormField label="Condition" :error="form.errors.condition">
                <USelect
                    v-model="form.condition"
                    :items="[
                        { label: 'New', value: 'new' },
                        { label: 'Used', value: 'used' },
                    ]"
                    value-key="value"
                    class="w-full"
                    placeholder="Select"
                />
            </UFormField>
        </div>

        <UFormField label="Summary" :error="form.errors.summary">
            <UTextarea
                v-model="form.summary"
                class="w-full"
                :rows="3"
                autoresize
                placeholder="Plain-text teaser — no markup"
            />
        </UFormField>

        <!-- Vessel -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Vessel</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <UFormField
                    v-if="!unlistedBuilder"
                    label="Builder"
                    :error="form.errors.builder_slug"
                >
                    <USelectMenu
                        :model-value="form.builder_slug ?? undefined"
                        :items="builderItems"
                        value-key="value"
                        class="w-full"
                        placeholder="Choose from the registry"
                        @update:model-value="
                            form.builder_slug = ($event as string) || null
                        "
                    />
                </UFormField>
                <UFormField
                    v-else
                    label="Builder (unlisted)"
                    :error="form.errors.builder_name"
                >
                    <UInput
                        v-model="form.builder_name"
                        class="w-full"
                        placeholder="Builder name"
                    />
                </UFormField>

                <UFormField label="Model" :error="form.errors.model_name">
                    <UInput v-model="form.model_name" class="w-full" />
                </UFormField>
            </div>

            <UCheckbox
                :model-value="unlistedBuilder"
                label="Builder is not in the registry"
                @update:model-value="toggleUnlisted($event === true)"
            />

            <div class="grid gap-4 sm:grid-cols-4">
                <UFormField
                    label="Model slug"
                    :error="form.errors.model_slug"
                    help="Optional interop identifier — leave empty unless this node curates model slugs"
                >
                    <UInput v-model="form.model_slug" class="w-full" />
                </UFormField>
                <UFormField label="Year built" :error="form.errors.year_built">
                    <UInput
                        v-model.number="form.year_built"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Refit year" :error="form.errors.refit_year">
                    <UInput
                        v-model.number="form.refit_year"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="LOA (m)" :error="form.errors.loa_m">
                    <UInput
                        v-model.number="form.loa_m"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <UFormField label="HIN" :error="form.errors.hin">
                    <UInput v-model="form.hin" class="w-full" />
                </UFormField>
                <UFormField label="IMO" :error="form.errors.imo">
                    <UInput v-model="form.imo" class="w-full" />
                </UFormField>
                <UFormField label="MMSI" :error="form.errors.mmsi">
                    <UInput v-model="form.mmsi" class="w-full" />
                </UFormField>
                <UFormField
                    label="Official number"
                    :error="form.errors.official_number"
                >
                    <UInput v-model="form.official_number" class="w-full" />
                </UFormField>
            </div>

            <UFormField
                label="Previous names"
                :error="form.errors.previous_names"
                help="Comma separated — the matching aid for renamed boats"
            >
                <UInput
                    v-model="form.previous_names"
                    class="w-full"
                    placeholder="ANDIAMO, SEA DREAM"
                />
            </UFormField>
        </div>

        <!-- Price -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Price</p>
            <div class="grid gap-4 sm:grid-cols-4">
                <UFormField label="Amount" :error="form.errors.price_amount">
                    <UInput
                        v-model="form.price_amount"
                        class="w-full"
                        placeholder="8500000"
                        :disabled="form.price_on_application"
                    />
                </UFormField>
                <UFormField
                    label="Currency"
                    :error="form.errors.price_currency"
                >
                    <USelect
                        v-model="form.price_currency"
                        :items="currencies"
                        class="w-full"
                        :disabled="form.price_on_application"
                    />
                </UFormField>
                <UFormField label=" ">
                    <UCheckbox
                        v-model="form.price_on_application"
                        label="Price on application"
                    />
                </UFormField>
                <UFormField label=" ">
                    <UCheckbox
                        v-model="form.starting_price"
                        label="Starting price (new build)"
                    />
                </UFormField>
            </div>
        </div>

        <!-- Location -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Location</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <UFormField
                    label="Public display"
                    :error="form.errors.location_display"
                    help="The wording every partner sees"
                >
                    <UInput
                        v-model="form.location_display"
                        class="w-full"
                        placeholder="Palma de Mallorca, Spain"
                    />
                </UFormField>
                <UFormField label="City" :error="form.errors.location_city">
                    <UInput v-model="form.location_city" class="w-full" />
                </UFormField>
                <UFormField label="State" :error="form.errors.location_state">
                    <UInput v-model="form.location_state" class="w-full" />
                </UFormField>
                <UFormField
                    label="Country (ISO 2)"
                    :error="form.errors.location_country"
                >
                    <UInput
                        v-model="form.location_country"
                        class="w-full"
                        maxlength="2"
                        placeholder="ES"
                    />
                </UFormField>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <UFormField
                    label="Marina"
                    :error="form.errors.location_marina"
                    help="Requires the exact-location field group"
                >
                    <UInput v-model="form.location_marina" class="w-full" />
                </UFormField>
                <UFormField
                    label="Latitude"
                    :error="form.errors.location_lat"
                    help="Requires the exact-location field group"
                >
                    <UInput
                        v-model.number="form.location_lat"
                        type="number"
                        step="0.000001"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Longitude" :error="form.errors.location_lon">
                    <UInput
                        v-model.number="form.location_lon"
                        type="number"
                        step="0.000001"
                        class="w-full"
                    />
                </UFormField>
                <div class="col-span-full">
                    <LocationMapPicker
                        :lat="form.location_lat"
                        :lon="form.location_lon"
                        :map="map"
                        @pick="setCoordinates"
                    />
                </div>
            </div>
        </div>

        <!-- Dimensions & capacities -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Dimensions & capacities</p>
            <div class="grid gap-4 sm:grid-cols-4">
                <UFormField label="Beam (m)">
                    <UInput
                        v-model.number="spec.beam_m"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Max draft (m)">
                    <UInput
                        v-model.number="spec.draft_max_m"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Min draft (m)">
                    <UInput
                        v-model.number="spec.draft_min_m"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Waterline length (m)">
                    <UInput
                        v-model.number="spec.lwl_m"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Length on deck (m)">
                    <UInput
                        v-model.number="spec.lod_m"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Bridge clearance (m)">
                    <UInput
                        v-model.number="spec.bridge_clearance_m"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Gross tonnage">
                    <UInput
                        v-model.number="spec.gross_tonnage"
                        type="number"
                        step="0.01"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Displacement (kg)">
                    <UInput
                        v-model.number="spec.displacement_kg"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Fuel capacity (L)">
                    <UInput
                        v-model.number="spec.fuel_capacity_l"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Water capacity (L)">
                    <UInput
                        v-model.number="spec.water_capacity_l"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Holding tank (L)">
                    <UInput
                        v-model.number="spec.holding_tank_l"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
            </div>
        </div>

        <!-- Performance -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Performance</p>
            <div class="grid gap-4 sm:grid-cols-4">
                <UFormField label="Cruise speed (kn)">
                    <UInput
                        v-model.number="spec.cruise_speed_kn"
                        type="number"
                        step="0.1"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Max speed (kn)">
                    <UInput
                        v-model.number="spec.max_speed_kn"
                        type="number"
                        step="0.1"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Range (nmi)">
                    <UInput
                        v-model.number="spec.range_nmi"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Fuel use (L/h)">
                    <UInput
                        v-model.number="spec.fuel_consumption_lph"
                        type="number"
                        step="0.1"
                        class="w-full"
                    />
                </UFormField>
            </div>
        </div>

        <!-- Construction & classification -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Construction & classification</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <UFormField label="Hull material">
                    <UInput v-model="spec.hull_material" class="w-full" />
                </UFormField>
                <UFormField label="Superstructure">
                    <UInput
                        v-model="spec.superstructure_material"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Deck material">
                    <UInput v-model="spec.deck_material" class="w-full" />
                </UFormField>
                <UFormField label="Hull shape">
                    <USelect
                        v-model="spec.hull_shape"
                        :items="hullShapes"
                        class="w-full"
                        placeholder="Select"
                    />
                </UFormField>
                <UFormField label="Hull colour">
                    <UInput v-model="spec.hull_color" class="w-full" />
                </UFormField>
                <UFormField label="Fuel type">
                    <USelect
                        v-model="spec.fuel_type"
                        :items="fuelTypes"
                        class="w-full"
                        placeholder="Select"
                    />
                </UFormField>
                <UFormField label="Naval architect">
                    <UInput v-model="spec.naval_architect" class="w-full" />
                </UFormField>
                <UFormField label="Exterior designer">
                    <UInput v-model="spec.exterior_designer" class="w-full" />
                </UFormField>
                <UFormField label="Interior designer">
                    <UInput v-model="spec.interior_designer" class="w-full" />
                </UFormField>
                <UFormField label="Flag">
                    <UInput v-model="spec.flag" class="w-full" />
                </UFormField>
                <UFormField label="Registry port">
                    <UInput v-model="spec.registry_port" class="w-full" />
                </UFormField>
                <UFormField
                    label="Power / sail"
                    :error="form.errors['specifications.power_or_sail']"
                >
                    <USelect
                        v-model="spec.power_or_sail"
                        :items="[
                            { label: 'Power', value: 'power' },
                            { label: 'Sail', value: 'sail' },
                        ]"
                        value-key="value"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    v-if="!unlistedCategory"
                    label="Category"
                    :error="form.errors['specifications.category.slug']"
                >
                    <USelectMenu
                        :model-value="spec.category.slug || undefined"
                        :items="categoryItems"
                        value-key="value"
                        class="w-full"
                        placeholder="Choose from the vocabulary"
                        @update:model-value="
                            spec.category.slug = ($event as string) || ''
                        "
                    />
                </UFormField>
                <UFormField v-else label="Category (unlisted)">
                    <UInput
                        v-model="spec.category.name"
                        class="w-full"
                        placeholder="Category name"
                    />
                </UFormField>
                <UFormField label=" ">
                    <UCheckbox
                        :model-value="unlistedCategory"
                        label="Category is not in the vocabulary"
                        @update:model-value="
                            toggleUnlistedCategory($event === true)
                        "
                    />
                </UFormField>
            </div>
        </div>

        <!-- Accommodation -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Accommodation</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <UFormField label="Cabins">
                    <UInput
                        v-model.number="spec.cabins"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Sleeps">
                    <UInput
                        v-model.number="spec.sleeps"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Heads">
                    <UInput
                        v-model.number="spec.heads"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="Guests cruising"
                    help="Carried under way — the regulatory limit"
                >
                    <UInput
                        v-model.number="spec.guests_cruising"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="Guests entertaining"
                    help="Carried static, at anchor or dockside"
                >
                    <UInput
                        v-model.number="spec.guests_entertaining"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
            </div>

            <p class="text-xs text-muted">
                Cabin configuration (rooms) — distinct from berth configuration
                (sleeping surfaces in the default makeup)
            </p>
            <div class="grid grid-cols-3 gap-4 sm:grid-cols-5">
                <UFormField
                    v-for="(label, key) in {
                        double: 'Double',
                        twin: 'Twin',
                        triple: 'Triple',
                        single: 'Single',
                        convertible: 'Convertible',
                    }"
                    :key="`cabin-${key}`"
                    :label="label"
                >
                    <UInput
                        v-model.number="spec.cabin_config[key]"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
            </div>

            <p class="text-xs text-muted">Berth configuration</p>
            <div class="grid grid-cols-3 gap-4 sm:grid-cols-7">
                <UFormField
                    v-for="(label, key) in {
                        king: 'King',
                        queen: 'Queen',
                        double: 'Double',
                        twin: 'Twin',
                        single: 'Single',
                        pullman: 'Pullman',
                        bunk: 'Bunk',
                    }"
                    :key="`berth-${key}`"
                    :label="label"
                >
                    <UInput
                        v-model.number="spec.berth_config[key]"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
            </div>

            <p class="text-xs text-muted">Crew accommodation</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <UFormField label="Crew cabins">
                    <UInput
                        v-model.number="spec.crew_accommodation.cabins"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Crew berths">
                    <UInput
                        v-model.number="spec.crew_accommodation.berths"
                        type="number"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="Layout">
                    <UInput
                        v-model="spec.crew_accommodation.layout"
                        class="w-full"
                        placeholder="2 rooms, 2 sets of bunks"
                    />
                </UFormField>
            </div>
        </div>

        <!-- Machinery -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Engines</p>
            <div
                v-for="(engine, i) in spec.engines"
                :key="i"
                class="space-y-3 rounded-md bg-elevated/50 p-3"
            >
                <div class="grid gap-3 sm:grid-cols-4">
                    <UFormField label="Make">
                        <UInput v-model="engine.make" class="w-full" />
                    </UFormField>
                    <UFormField label="Model">
                        <UInput v-model="engine.model" class="w-full" />
                    </UFormField>
                    <UFormField label="Year">
                        <UInput
                            v-model.number="engine.year"
                            type="number"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Type">
                        <USelect
                            v-model="engine.type"
                            :items="engineTypes"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Drive type">
                        <UInput
                            v-model="engine.drive_type"
                            class="w-full"
                            placeholder="Direct"
                        />
                    </UFormField>
                    <UFormField label="Power (hp)">
                        <UInput
                            v-model.number="engine.power_hp"
                            type="number"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Power (kW)">
                        <UInput
                            v-model.number="engine.power_kw"
                            type="number"
                            step="0.01"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Fuel">
                        <USelect
                            v-model="engine.fuel_type"
                            :items="fuelTypes"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Hours">
                        <UInput
                            v-model.number="engine.hours"
                            type="number"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Hours recorded">
                        <UInput
                            v-model="engine.hours_recorded_at"
                            type="date"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Location">
                        <UInput
                            v-model="engine.location"
                            class="w-full"
                            placeholder="port"
                        />
                    </UFormField>
                </div>
                <UButton
                    color="error"
                    variant="ghost"
                    size="xs"
                    icon="i-lucide-trash-2"
                    label="Remove engine"
                    @click="spec.engines.splice(i, 1)"
                />
            </div>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add engine"
                @click="spec.engines.push(emptyEngine())"
            />

            <p class="pt-2 text-sm font-medium">Generators</p>
            <div
                v-for="(generator, i) in spec.generators"
                :key="`gen-${i}`"
                class="space-y-3 rounded-md bg-elevated/50 p-3"
            >
                <div class="grid gap-3 sm:grid-cols-5">
                    <UFormField label="Make">
                        <UInput v-model="generator.make" class="w-full" />
                    </UFormField>
                    <UFormField label="Model">
                        <UInput v-model="generator.model" class="w-full" />
                    </UFormField>
                    <UFormField label="Power (kW)">
                        <UInput
                            v-model.number="generator.power_kw"
                            type="number"
                            step="0.01"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Hours">
                        <UInput
                            v-model.number="generator.hours"
                            type="number"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField label="Hours recorded">
                        <UInput
                            v-model="generator.hours_recorded_at"
                            type="date"
                            class="w-full"
                        />
                    </UFormField>
                </div>
                <UButton
                    color="error"
                    variant="ghost"
                    size="xs"
                    icon="i-lucide-trash-2"
                    label="Remove generator"
                    @click="spec.generators.splice(i, 1)"
                />
            </div>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add generator"
                @click="spec.generators.push(emptyGenerator())"
            />

            <UFormField
                label="Tenders & toys"
                help="Free text — structured water toys belong in features"
            >
                <UTextarea
                    v-model="spec.tenders"
                    class="w-full"
                    :rows="2"
                    autoresize
                />
            </UFormField>
        </div>

        <!-- Descriptions -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium">Descriptions</p>
                <UButton
                    color="neutral"
                    variant="outline"
                    size="xs"
                    icon="i-lucide-plus"
                    label="Add section"
                    @click="
                        form.descriptions.push({ section: '', content: '' })
                    "
                />
            </div>
            <p class="text-xs text-muted">
                Restricted HTML only: p, br, ul, ol, li, strong, em, h3, h4,
                https links. Use the well-known labels
                <code>overview</code> and <code>highlights</code> where they
                apply.
            </p>
            <div
                v-for="(description, i) in form.descriptions"
                :key="`desc-${i}`"
                class="space-y-2 rounded-md bg-elevated/50 p-3"
            >
                <div class="flex items-end gap-2">
                    <UFormField label="Section" class="flex-1">
                        <UInput
                            v-model="description.section"
                            class="w-full"
                            placeholder="overview"
                        />
                    </UFormField>
                    <UButton
                        color="error"
                        variant="ghost"
                        icon="i-lucide-trash-2"
                        aria-label="Remove section"
                        @click="form.descriptions.splice(i, 1)"
                    />
                </div>
                <UFormField
                    label="Content"
                    :error="form.errors[`descriptions.${i}.content`]"
                >
                    <UTextarea
                        v-if="sourceMode[i]"
                        v-model="description.content"
                        class="w-full font-mono text-xs"
                        :rows="12"
                        autoresize
                    />
                    <UEditor
                        v-else
                        v-model="description.content"
                        content-class="min-h-32 px-3 py-2"
                        :starter-kit="{
                            heading: { levels: [3, 4] },
                            codeBlock: false,
                            code: false,
                            blockquote: false,
                            horizontalRule: false,
                            strike: false,
                            underline: false,
                        }"
                        class="w-full rounded-md border border-default"
                    >
                        <template #default="{ editor }">
                            <UEditorToolbar
                                v-if="editor"
                                :editor="editor"
                                :items="editorToolbarItems"
                                class="border-b border-default p-1"
                            />
                        </template>
                    </UEditor>
                </UFormField>
                <UButton
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    :icon="sourceMode[i] ? 'i-lucide-eye' : 'i-lucide-code'"
                    :label="sourceMode[i] ? 'Visual editor' : 'View source'"
                    @click="sourceMode[i] = !sourceMode[i]"
                />
            </div>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add section"
                @click="form.descriptions.push({ section: '', content: '' })"
            />
        </div>

        <!-- Features -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Features</p>
            <div
                v-for="(feature, i) in form.features"
                :key="`feat-${i}`"
                class="flex items-end gap-2"
            >
                <UFormField label="Category" class="w-40">
                    <UInput
                        v-model="feature.category"
                        class="w-full"
                        placeholder="comfort"
                    />
                </UFormField>
                <UFormField
                    label="Name"
                    class="flex-1"
                    :error="form.errors[`features.${i}.name`]"
                >
                    <UInput
                        v-model="feature.name"
                        class="w-full"
                        placeholder="Air conditioning"
                    />
                </UFormField>
                <UButton
                    color="error"
                    variant="ghost"
                    icon="i-lucide-trash-2"
                    aria-label="Remove feature"
                    @click="form.features.splice(i, 1)"
                />
            </div>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add feature"
                @click="
                    form.features.push({ category: '', name: '', slug: '' })
                "
            />
        </div>

        <!-- Compliance -->
        <div class="space-y-3 rounded-lg border border-default p-4">
            <p class="text-sm font-medium">Compliance</p>
            <div class="grid gap-4 sm:grid-cols-4">
                <UFormField label="Not for sale to US residents in US waters">
                    <USelect
                        v-model="
                            form.compliance
                                .not_for_sale_to_us_residents_in_us_waters
                        "
                        :items="triState"
                        value-key="value"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="VAT status"
                    help="Free text — e.g. paid, not_paid, exempt"
                >
                    <UInput
                        v-model="form.compliance.vat_status"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="CE certified">
                    <USelect
                        v-model="form.compliance.ce_certified"
                        :items="triState"
                        value-key="value"
                        class="w-full"
                    />
                </UFormField>
                <UFormField label="MCA compliant">
                    <USelect
                        v-model="form.compliance.mca_compliant"
                        :items="triState"
                        value-key="value"
                        class="w-full"
                    />
                </UFormField>
            </div>

            <p class="pt-2 text-xs text-muted">Classification societies</p>
            <div
                v-for="(entry, i) in form.compliance.classification"
                :key="`class-${i}`"
                class="flex items-end gap-2"
            >
                <UFormField label="Society" class="w-40">
                    <UInput
                        v-model="entry.society"
                        class="w-full"
                        placeholder="RINA"
                    />
                </UFormField>
                <UFormField label="Notation" class="flex-1">
                    <UInput
                        v-model="entry.notation"
                        class="w-full"
                        placeholder="Pleasure"
                    />
                </UFormField>
                <UFormField label="Next survey due" class="w-48">
                    <UInput
                        v-model="entry.next_survey_due"
                        type="date"
                        class="w-full"
                    />
                </UFormField>
                <UButton
                    color="error"
                    variant="ghost"
                    icon="i-lucide-trash-2"
                    aria-label="Remove classification"
                    @click="form.compliance.classification.splice(i, 1)"
                />
            </div>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add classification"
                @click="
                    form.compliance.classification.push({
                        society: '',
                        notation: '',
                        next_survey_due: '',
                    })
                "
            />
        </div>
    </div>
</template>
