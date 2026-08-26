<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import { computed, ref } from 'vue';
import type { ListingForm } from '@/components/listing-form/types';

type Builder = {
    slug: string;
    name: string;
    country: string | null;
};

const props = defineProps<{
    form: ListingForm;
    builders: Builder[];
}>();

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
</script>

<template>
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
</template>
