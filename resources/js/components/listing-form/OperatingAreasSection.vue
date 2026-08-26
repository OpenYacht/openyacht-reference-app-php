<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import { computed } from 'vue';
import type {
    CharterYachtFormFields,
    ListingForm,
    OperatingAreaForm,
} from '@/components/listing-form/types';
import { emptyOperatingArea } from '@/components/listing-form/types';

type Destination = {
    slug: string;
    name: string;
    parent: string | null;
};

const props = defineProps<{
    form: ListingForm<CharterYachtFormFields>;
    destinations: Destination[];
}>();

// Destinations follow the builder pattern: a fixed registry choice with
// an explicit unlisted escape hatch (free-text name, null slug) —
// authorities never invent destination slugs.
const destinationItems = computed(() =>
    props.destinations.map((destination) => ({
        label: destination.name,
        value: destination.slug,
    })),
);

const pickDestination = (area: OperatingAreaForm, slug: string | null) => {
    area.slug = slug || null;

    if (area.slug !== null) {
        area.name =
            props.destinations.find((d) => d.slug === area.slug)?.name ?? '';
    }
};

const toggleUnlisted = (area: OperatingAreaForm, value: boolean) => {
    if (value) {
        area.slug = null;
    } else {
        area.name = '';
        area.slug = '';
    }
};

// '' means "registry pick pending"; null means deliberately unlisted.
const isUnlisted = (area: OperatingAreaForm) => area.slug === null;

// Base ports are plain strings on the wire — two inputs, no registry.
</script>

<template>
    <div class="space-y-3 rounded-lg border border-default p-4">
        <p class="text-sm font-medium">Operating areas & base ports</p>
        <div
            v-for="(area, i) in form.operating_areas"
            :key="`area-${i}`"
            class="space-y-2 rounded-md bg-elevated/50 p-3"
        >
            <div class="grid gap-3 sm:grid-cols-3">
                <UFormField
                    v-if="!isUnlisted(area)"
                    label="Destination"
                    :error="form.errors[`operating_areas.${i}.slug`]"
                >
                    <USelectMenu
                        :model-value="area.slug || undefined"
                        :items="destinationItems"
                        value-key="value"
                        class="w-full"
                        placeholder="Choose from the registry"
                        @update:model-value="
                            pickDestination(area, $event as string)
                        "
                    />
                </UFormField>
                <UFormField
                    v-else
                    label="Cruising ground (unlisted)"
                    :error="form.errors[`operating_areas.${i}.name`]"
                >
                    <UInput
                        v-model="area.name"
                        class="w-full"
                        placeholder="Area name"
                    />
                </UFormField>
                <UFormField label="Season">
                    <UInput
                        v-model="area.season"
                        class="w-full"
                        placeholder="summer"
                    />
                </UFormField>
                <UFormField label=" ">
                    <UCheckbox
                        :model-value="isUnlisted(area)"
                        label="Not in the registry"
                        @update:model-value="
                            toggleUnlisted(area, $event === true)
                        "
                    />
                </UFormField>
            </div>
            <UButton
                color="error"
                variant="ghost"
                size="xs"
                icon="i-lucide-trash-2"
                label="Remove area"
                @click="form.operating_areas.splice(i, 1)"
            />
        </div>
        <UButton
            color="neutral"
            variant="outline"
            size="xs"
            icon="i-lucide-plus"
            label="Add operating area"
            @click="form.operating_areas.push(emptyOperatingArea())"
        />

        <div class="grid gap-4 pt-2 sm:grid-cols-2">
            <UFormField
                label="Summer base port"
                :error="form.errors.summer_base_port"
            >
                <UInput
                    v-model="form.summer_base_port"
                    class="w-full"
                    placeholder="Palma de Mallorca"
                />
            </UFormField>
            <UFormField
                label="Winter base port"
                :error="form.errors.winter_base_port"
            >
                <UInput
                    v-model="form.winter_base_port"
                    class="w-full"
                    placeholder="Antigua"
                />
            </UFormField>
        </div>
    </div>
</template>
