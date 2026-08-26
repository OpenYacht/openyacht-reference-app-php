<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import type { ListingForm } from '@/components/listing-form/types';

defineProps<{ form: ListingForm }>();

const triState = [
    { label: '—', value: 'unknown' },
    { label: 'Yes', value: '1' },
    { label: 'No', value: '0' },
];
</script>

<template>
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
                <UInput v-model="form.compliance.vat_status" class="w-full" />
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
</template>
