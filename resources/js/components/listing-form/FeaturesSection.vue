<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import type { ListingForm } from '@/components/listing-form/types';

defineProps<{ form: ListingForm }>();
</script>

<template>
    <div class="space-y-3 rounded-lg border border-default p-4">
        <p class="text-sm font-medium">Features</p>
        <div
            v-for="(feature, i) in form.features"
            :key="`feat-${i}`"
            class="flex items-end gap-2"
        >
            <UFormField label="Category" class="w-40">
                <UInput
                    v-model="feature.category"
                    class="w-full"
                    placeholder="comfort"
                />
            </UFormField>
            <UFormField
                label="Name"
                class="flex-1"
                :error="form.errors[`features.${i}.name`]"
            >
                <UInput
                    v-model="feature.name"
                    class="w-full"
                    placeholder="Air conditioning"
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
            @click="form.features.push({ category: '', name: '', slug: '' })"
        />
    </div>
</template>
