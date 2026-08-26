<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import type { ListingForm } from '@/components/listing-form/types';

defineProps<{ form: ListingForm }>();
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <UFormField label="Listing name" :error="form.errors.name" required>
                <UInput
                    v-model="form.name"
                    class="w-full"
                    placeholder="OASIS"
                />
            </UFormField>

            <UFormField label="Condition" :error="form.errors.condition">
                <USelect
                    v-model="form.condition"
                    :items="[
                        { label: 'New', value: 'new' },
                        { label: 'Used', value: 'used' },
                    ]"
                    value-key="value"
                    class="w-full"
                    placeholder="Select"
                />
            </UFormField>
        </div>

        <UFormField label="Summary" :error="form.errors.summary">
            <UTextarea
                v-model="form.summary"
                class="w-full"
                :rows="3"
                autoresize
                placeholder="Plain-text teaser — no markup"
            />
        </UFormField>
    </div>
</template>
