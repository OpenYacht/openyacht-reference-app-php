<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import {
    emptySpecifications,
    normalizeCompliance,
} from '@/components/listing-form/types';
import type {
    FeatureForm,
    FeatureVocabularyEntry,
} from '@/components/listing-form/types';
import YachtForm from '@/components/YachtForm.vue';
import { create, index, store } from '@/routes/yachts';

defineProps<{
    builders: { slug: string; name: string; country: string | null }[];
    categories: { slug: string; name: string }[];
    featureVocabulary: FeatureVocabularyEntry[];
    map: { provider: 'openstreetmap' | 'mapbox'; mapbox_token: string | null };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Sale yachts', href: index() },
            { title: 'New listing', href: create() },
        ],
    },
});

const form = useForm({
    name: '',
    summary: '',
    condition: 'used',
    price_amount: '',
    price_currency: 'EUR',
    price_on_application: false,
    starting_price: false,
    location_display: '',
    location_city: '',
    location_state: '',
    location_country: '',
    location_marina: '',
    location_lat: null as number | null,
    location_lon: null as number | null,
    builder_slug: null as string | null,
    builder_name: '',
    model_name: '',
    model_slug: '',
    year_built: null as number | null,
    refit_year: null as number | null,
    loa_m: null as number | null,
    hin: '',
    imo: '',
    mmsi: '',
    official_number: '',
    previous_names: '',
    specifications: emptySpecifications(),
    descriptions: [{ section: 'overview', content: '' }],
    features: [] as FeatureForm[],
    videos: [] as { url: string; caption: string }[],
    tours: [] as { url: string; caption: string }[],
    compliance: {
        not_for_sale_to_us_residents_in_us_waters: 'unknown',
        vat_status: '',
        ce_certified: 'unknown',
        mca_compliant: 'unknown',
        classification: [] as {
            society: string;
            notation: string;
            next_survey_due: string;
        }[],
    },
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            compliance: normalizeCompliance(data.compliance),
        }))
        .submit(store());
</script>

<template>
    <Head title="New listing" />

    <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">
        <Heading
            title="New listing"
            description="Starts as a draft — nothing is shared with partners until you activate it"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <YachtForm
                :form="form"
                :builders="builders"
                :categories="categories"
                :feature-vocabulary="featureVocabulary"
                :map="map"
            />

            <div class="flex justify-end gap-2">
                <UButton
                    :to="index.url()"
                    color="neutral"
                    variant="ghost"
                    label="Cancel"
                />
                <UButton
                    type="submit"
                    :loading="form.processing"
                    label="Create draft"
                />
            </div>
        </form>
    </div>
</template>
