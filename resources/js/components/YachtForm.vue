<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import ComplianceSection from '@/components/listing-form/ComplianceSection.vue';
import DescriptionsSection from '@/components/listing-form/DescriptionsSection.vue';
import FeaturesSection from '@/components/listing-form/FeaturesSection.vue';
import ListingBasicsSection from '@/components/listing-form/ListingBasicsSection.vue';
import LocationSection from '@/components/listing-form/LocationSection.vue';
import MediaLinksSection from '@/components/listing-form/MediaLinksSection.vue';
import SpecificationsSections from '@/components/listing-form/SpecificationsSections.vue';
import type {
    ListingForm,
    YachtFormFields,
} from '@/components/listing-form/types';
import VesselSection from '@/components/listing-form/VesselSection.vue';
import type { MapConfig } from '@/components/LocationMapPicker.vue';

defineProps<{
    builders: { slug: string; name: string; country: string | null }[];
    categories: { slug: string; name: string }[];
    map: MapConfig;
    form: ListingForm<YachtFormFields>;
}>();

const currencies = ['EUR', 'USD', 'GBP', 'CHF', 'AUD'];
</script>

<template>
    <div class="space-y-6">
        <ListingBasicsSection :form="form" />

        <VesselSection :form="form" :builders="builders" />

        <!-- Price — the sale side of the wire's type conditional; a
             charter listing has no asking price at all. -->
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

        <LocationSection :form="form" :map="map" />

        <SpecificationsSections :form="form" :categories="categories" />

        <DescriptionsSection :form="form" />

        <FeaturesSection :form="form" />

        <MediaLinksSection :form="form" />

        <ComplianceSection :form="form" />
    </div>
</template>
