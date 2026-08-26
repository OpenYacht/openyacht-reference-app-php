<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import type {
    CharterYachtFormFields,
    ListingForm,
} from '@/components/listing-form/types';
import { emptyCrewMember } from '@/components/listing-form/types';

defineProps<{ form: ListingForm<CharterYachtFormFields> }>();
</script>

<template>
    <div class="space-y-3 rounded-lg border border-default p-4">
        <p class="text-sm font-medium">Crew</p>
        <p class="text-xs text-muted">
            A TBA position is unannounced — the role stands, the personal
            fields stay empty.
        </p>
        <div
            v-for="(member, i) in form.crew"
            :key="`crew-${i}`"
            class="space-y-2 rounded-md bg-elevated/50 p-3"
        >
            <div class="grid gap-3 sm:grid-cols-4">
                <UFormField
                    label="Role"
                    :error="form.errors[`crew.${i}.role`]"
                >
                    <UInput
                        v-model="member.role"
                        class="w-full"
                        placeholder="Captain"
                    />
                </UFormField>
                <UFormField label="Name">
                    <UInput
                        v-model="member.name"
                        class="w-full"
                        :disabled="member.tba"
                    />
                </UFormField>
                <UFormField label="Nationality">
                    <UInput
                        v-model="member.nationality"
                        class="w-full"
                        :disabled="member.tba"
                    />
                </UFormField>
                <UFormField
                    label="Photo URL"
                    :error="form.errors[`crew.${i}.photo_url`]"
                >
                    <UInput
                        v-model="member.photo_url"
                        class="w-full"
                        :disabled="member.tba"
                    />
                </UFormField>
            </div>
            <UFormField label="Bio">
                <UTextarea
                    v-model="member.bio"
                    class="w-full"
                    :rows="2"
                    autoresize
                    :disabled="member.tba"
                />
            </UFormField>
            <div class="flex items-center justify-between">
                <UCheckbox
                    v-model="member.tba"
                    label="Position to be announced"
                />
                <UButton
                    color="error"
                    variant="ghost"
                    size="xs"
                    icon="i-lucide-trash-2"
                    label="Remove crew member"
                    @click="form.crew.splice(i, 1)"
                />
            </div>
        </div>
        <UButton
            color="neutral"
            variant="outline"
            size="xs"
            icon="i-lucide-plus"
            label="Add crew member"
            @click="form.crew.push(emptyCrewMember())"
        />

        <div class="rounded-md border border-default bg-elevated/30 p-3">
            <UCheckbox
                v-model="form.crew_attested"
                label="The charter manager or captain has attested that this crew data may be published"
            />
            <p class="mt-1 text-xs text-muted">
                Crew bios and photos are personal data. Without a standing
                attestation, crew is withheld from every partner — the wire
                carries an empty list.
            </p>
        </div>
    </div>
</template>
