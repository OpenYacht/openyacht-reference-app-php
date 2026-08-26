<script setup lang="ts">
import ComplianceSection from '@/components/listing-form/ComplianceSection.vue';
import CrewSection from '@/components/listing-form/CrewSection.vue';
import DescriptionsSection from '@/components/listing-form/DescriptionsSection.vue';
import FeaturesSection from '@/components/listing-form/FeaturesSection.vue';
import ListingBasicsSection from '@/components/listing-form/ListingBasicsSection.vue';
import LocationSection from '@/components/listing-form/LocationSection.vue';
import MediaLinksSection from '@/components/listing-form/MediaLinksSection.vue';
import OperatingAreasSection from '@/components/listing-form/OperatingAreasSection.vue';
import RatesSection from '@/components/listing-form/RatesSection.vue';
import SpecificationsSections from '@/components/listing-form/SpecificationsSections.vue';
import type {
    CharterYachtFormFields,
    ListingForm,
} from '@/components/listing-form/types';
import VesselSection from '@/components/listing-form/VesselSection.vue';
import type { MapConfig } from '@/components/LocationMapPicker.vue';

defineProps<{
    builders: { slug: string; name: string; country: string | null }[];
    categories: { slug: string; name: string }[];
    destinations: { slug: string; name: string; parent: string | null }[];
    map: MapConfig;
    form: ListingForm<CharterYachtFormFields>;
}>();
</script>

<template>
    <div class="space-y-6">
        <ListingBasicsSection :form="form" />

        <VesselSection :form="form" :builders="builders" />

        <!-- The charter side of the wire's type conditional: rates,
             operating areas, base ports, and crew replace the asking
             price entirely. -->
        <RatesSection :form="form" />

        <OperatingAreasSection :form="form" :destinations="destinations" />

        <CrewSection :form="form" />

        <LocationSection :form="form" :map="map" />

        <SpecificationsSections :form="form" :categories="categories" />

        <DescriptionsSection :form="form" />

        <FeaturesSection :form="form" />

        <MediaLinksSection :form="form" />

        <ComplianceSection :form="form" />
    </div>
</template>
