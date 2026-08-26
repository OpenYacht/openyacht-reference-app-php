<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import type {
    CharterYachtFormFields,
    ListingForm,
} from '@/components/listing-form/types';
import { emptyRate } from '@/components/listing-form/types';

defineProps<{ form: ListingForm<CharterYachtFormFields> }>();

const currencies = ['EUR', 'USD', 'GBP', 'CHF', 'AUD'];
const rateTypes = [
    { label: 'Weekly', value: 'weekly' },
    { label: 'Daily', value: 'daily' },
];
</script>

<template>
    <div class="space-y-3 rounded-lg border border-default p-4">
        <p class="text-sm font-medium">Charter rates</p>
        <p class="text-xs text-muted">
            A fixed single rate uses the same minimum and maximum. Money is
            digit strings; a season is <code>summer</code>,
            <code>winter</code>, or a free label for special periods. Rates
            travel under the pricing field group — partners without it
            receive an empty list.
        </p>
        <div
            v-for="(rate, i) in form.rates"
            :key="`rate-${i}`"
            class="space-y-3 rounded-md bg-elevated/50 p-3"
        >
            <div class="grid gap-3 sm:grid-cols-4">
                <UFormField
                    label="Season"
                    :error="form.errors[`rates.${i}.season`]"
                >
                    <UInput
                        v-model="rate.season"
                        class="w-full"
                        placeholder="summer"
                    />
                </UFormField>
                <UFormField
                    label="Rate type"
                    :error="form.errors[`rates.${i}.rate_type`]"
                >
                    <USelect
                        v-model="rate.rate_type"
                        :items="rateTypes"
                        value-key="value"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="Amount from"
                    :error="form.errors[`rates.${i}.amount_min`]"
                >
                    <UInput
                        v-model="rate.amount_min"
                        class="w-full"
                        placeholder="125000"
                    />
                </UFormField>
                <UFormField
                    label="Amount to"
                    :error="form.errors[`rates.${i}.amount_max`]"
                >
                    <UInput
                        v-model="rate.amount_max"
                        class="w-full"
                        placeholder="135000"
                    />
                </UFormField>
                <UFormField
                    label="Currency"
                    :error="form.errors[`rates.${i}.currency`]"
                >
                    <USelect
                        v-model="rate.currency"
                        :items="currencies"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="Contract terms"
                    :error="form.errors[`rates.${i}.contract_terms`]"
                >
                    <UInput
                        v-model="rate.contract_terms"
                        class="w-full"
                        placeholder="MYBA"
                    />
                </UFormField>
                <UFormField
                    label="APA %"
                    :error="form.errors[`rates.${i}.apa_percent`]"
                >
                    <UInput
                        v-model.number="rate.apa_percent"
                        type="number"
                        step="0.1"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="VAT %"
                    :error="form.errors[`rates.${i}.vat_percent`]"
                >
                    <UInput
                        v-model.number="rate.vat_percent"
                        type="number"
                        step="0.1"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="Valid from"
                    :error="form.errors[`rates.${i}.valid_from`]"
                >
                    <UInput
                        v-model="rate.valid_from"
                        type="date"
                        class="w-full"
                    />
                </UFormField>
                <UFormField
                    label="Valid to"
                    :error="form.errors[`rates.${i}.valid_to`]"
                >
                    <UInput
                        v-model="rate.valid_to"
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
                label="Remove rate"
                @click="form.rates.splice(i, 1)"
            />
        </div>
        <UButton
            color="neutral"
            variant="outline"
            size="xs"
            icon="i-lucide-plus"
            label="Add rate"
            @click="form.rates.push(emptyRate())"
        />
    </div>
</template>
