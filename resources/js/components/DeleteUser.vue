<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import PasswordInput from '@/components/PasswordInput.vue';

const showDeleteModal = ref(false);
const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Delete account"
            description="Delete your account and all of its resources"
        />
        <div
            class="space-y-4 rounded-lg border border-error/25 bg-error/10 p-4"
        >
            <div class="relative space-y-0.5 text-error">
                <p class="font-medium">Warning</p>
                <p class="text-sm">
                    Please proceed with caution, this cannot be undone.
                </p>
            </div>

            <UButton
                color="error"
                data-test="delete-user-button"
                label="Delete account"
                @click="showDeleteModal = true"
            />

            <UModal
                v-model:open="showDeleteModal"
                title="Are you sure you want to delete your account?"
                description="Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account."
            >
                <template #body>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="() => passwordInput?.focus()"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <UFormField :error="errors.password">
                            <PasswordInput
                                id="password"
                                name="password"
                                ref="passwordInput"
                                placeholder="Password"
                                aria-label="Password"
                            />
                        </UFormField>

                        <div class="flex justify-end gap-2">
                            <UButton
                                type="button"
                                color="neutral"
                                variant="soft"
                                label="Cancel"
                                @click="
                                    () => {
                                        clearErrors();
                                        reset();
                                        showDeleteModal = false;
                                    }
                                "
                            />
                            <UButton
                                type="submit"
                                color="error"
                                :loading="processing"
                                data-test="confirm-delete-user-button"
                                label="Delete account"
                            />
                        </div>
                    </Form>
                </template>
            </UModal>
        </div>
    </div>
</template>
