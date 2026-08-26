<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import type { ListingForm } from '@/components/listing-form/types';
import LocationMapPicker from '@/components/LocationMapPicker.vue';
import type { MapConfig } from '@/components/LocationMapPicker.vue';

const props = defineProps<{
    form: ListingForm;
    map: MapConfig;
}>();

const setCoordinates = (lat: number, lon: number) => {
    props.form.location_lat = lat;
    props.form.location_lon = lon;
};
</script>

<template>
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
</template>
