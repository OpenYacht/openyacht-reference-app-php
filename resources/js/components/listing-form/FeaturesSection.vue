<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import { computed } from 'vue';
import type {
    FeatureForm,
    FeatureVocabularyEntry,
    ListingForm,
} from '@/components/listing-form/types';
import { emptyFeature } from '@/components/listing-form/types';

const props = defineProps<{
    form: ListingForm;
    featureVocabulary: FeatureVocabularyEntry[];
}>();

// The slug is a visible field that drives the row, never something derived
// from the name: a row reading "Cinema | Seabob x 10" is self-evidently
// wrong, where an invisible slug would ship the wrong identity unnoticed.
// SelectMenu cannot hold an empty value, so "no link" is a sentinel here
// and '' on the form (null on the wire).
const NO_LINK = '__no_link__';

// One shape for options and group headings alike, so value-key resolves.
type VocabularyItem = { label: string; value: string; type?: 'label' };

const vocabularyItems = computed((): VocabularyItem[][] => {
    const groups = new Map<string, VocabularyItem[]>();

    for (const entry of props.featureVocabulary) {
        groups.set(entry.category, [
            ...(groups.get(entry.category) ?? []),
            { label: entry.name, value: entry.slug },
        ]);
    }

    return [
        [{ label: '— No link —', value: NO_LINK }],
        ...[...groups.entries()].map(([category, items]) => [
            {
                type: 'label' as const,
                label: category.replaceAll('-', ' '),
                value: '',
            },
            ...items,
        ]),
    ];
});

// Picking an entry is choosing a new identity, so it overwrites name and
// category — stale text must not survive it. Editing either afterwards
// never touches the slug, and "no link" leaves the text as typed.
const pickVocabularyEntry = (feature: FeatureForm, value: string) => {
    const entry = props.featureVocabulary.find((e) => e.slug === value);

    feature.slug = entry?.slug ?? '';

    if (entry) {
        feature.name = entry.name;
        feature.category = entry.category;
    }
};
</script>

<template>
    <div class="space-y-3 rounded-lg border border-default p-4">
        <div>
            <p class="text-sm font-medium">Features</p>
            <p class="text-sm text-muted">
                Pick the closest vocabulary entry — it fills the name and
                category, and partners filter on it. Both stay yours to reword;
                keep names singular (Seabob, not 2x Seabob) and put counts in
                Qty, where empty means present, count unstated. Nothing fits?
                Leave No link and type freely.
            </p>
        </div>
        <div
            v-for="(feature, i) in form.features"
            :key="`feat-${i}`"
            class="grid items-end gap-2 sm:grid-cols-[14rem_1fr_10rem_5rem_auto]"
        >
            <UFormField
                label="Vocabulary"
                :error="form.errors[`features.${i}.slug`]"
            >
                <USelectMenu
                    :model-value="feature.slug || NO_LINK"
                    :items="vocabularyItems"
                    value-key="value"
                    class="w-full"
                    @update:model-value="
                        pickVocabularyEntry(feature, $event as string)
                    "
                />
            </UFormField>
            <UFormField label="Name" :error="form.errors[`features.${i}.name`]">
                <UInput
                    v-model="feature.name"
                    class="w-full"
                    placeholder="Air conditioning"
                />
            </UFormField>
            <UFormField label="Category">
                <UInput
                    v-model="feature.category"
                    class="w-full"
                    placeholder="comfort"
                />
            </UFormField>
            <UFormField
                label="Qty"
                :error="form.errors[`features.${i}.quantity`]"
            >
                <UInput
                    v-model.number="feature.quantity"
                    type="number"
                    min="1"
                    step="1"
                    class="w-full"
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
            @click="form.features.push(emptyFeature())"
        />
    </div>
</template>
