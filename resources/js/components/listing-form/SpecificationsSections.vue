<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import { computed, ref } from 'vue';
import type { ListingForm } from '@/components/listing-form/types';
import { emptyEngine, emptyGenerator } from '@/components/listing-form/types';

const props = defineProps<{
    form: ListingForm;
    categories: { slug: string; name: string }[];
}>();

// Category follows the builder pattern: a vocabulary choice that sets the
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

const spec = computed(() => props.form.specifications);
</script>

<template>
    <div class="space-y-6">
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
    </div>
</template>
