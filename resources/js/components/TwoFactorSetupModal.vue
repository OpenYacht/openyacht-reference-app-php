<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { confirm } from '@/routes/two-factor';
import type { TwoFactorConfigContent } from '@/types';

type Props = {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
};

const { resolvedAppearance } = useAppearance();

const props = defineProps<Props>();
const isOpen = defineModel<boolean>('isOpen');

const { copy, copied } = useClipboard();
const { qrCodeSvg, manualSetupKey, clearSetupData, fetchSetupData, errors } =
    useTwoFactorAuth();

const showVerificationStep = ref(false);
const code = ref<string[]>([]);

const codeValue = computed(() => code.value.join(''));

const pinInputContainerRef = useTemplateRef('pinInputContainerRef');

const modalConfig = computed<TwoFactorConfigContent>(() => {
    if (props.twoFactorEnabled) {
        return {
            title: 'Two-factor authentication enabled',
            description:
                'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
            buttonText: 'Close',
        };
    }

    if (showVerificationStep.value) {
        return {
            title: 'Verify authentication code',
            description: 'Enter the 6-digit code from your authenticator app',
            buttonText: 'Continue',
        };
    }

    return {
        title: 'Enable two-factor authentication',
        description:
            'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
        buttonText: 'Continue',
    };
});

const handleModalNextStep = () => {
    if (props.requiresConfirmation) {
        showVerificationStep.value = true;

        nextTick(() => {
            pinInputContainerRef.value?.querySelector('input')?.focus();
        });

        return;
    }

    clearSetupData();
    isOpen.value = false;
};

const resetModalState = () => {
    if (props.twoFactorEnabled) {
        clearSetupData();
    }

    showVerificationStep.value = false;
    code.value = [];
};

watch(
    () => isOpen.value,
    async (isOpen) => {
        if (!isOpen) {
            resetModalState();

            return;
        }

        if (!qrCodeSvg.value) {
            await fetchSetupData();
        }
    },
);
</script>

<template>
    <UModal
        v-model:open="isOpen"
        :title="modalConfig.title"
        :description="modalConfig.description"
    >
        <template #body>
            <div
                class="relative flex w-auto flex-col items-center justify-center space-y-5"
            >
                <template v-if="!showVerificationStep">
                    <AlertError v-if="errors?.length" :errors="errors" />
                    <template v-else>
                        <div
                            class="relative mx-auto flex max-w-md items-center overflow-hidden"
                        >
                            <div
                                class="relative mx-auto aspect-square w-64 overflow-hidden rounded-lg border border-default"
                            >
                                <div
                                    v-if="!qrCodeSvg"
                                    class="absolute inset-0 z-10 flex aspect-square h-auto w-full animate-pulse items-center justify-center bg-default"
                                >
                                    <UIcon
                                        name="i-lucide-loader-circle"
                                        class="size-6 animate-spin"
                                    />
                                </div>
                                <div
                                    v-else
                                    class="relative z-10 overflow-hidden border p-5"
                                >
                                    <div
                                        v-html="qrCodeSvg"
                                        class="flex aspect-square size-full items-center justify-center"
                                        :style="{
                                            filter:
                                                resolvedAppearance === 'dark'
                                                    ? 'invert(1) brightness(1.5)'
                                                    : undefined,
                                        }"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="flex w-full items-center space-x-5">
                            <UButton block @click="handleModalNextStep">
                                {{ modalConfig.buttonText }}
                            </UButton>
                        </div>

                        <USeparator label="or, enter the code manually" />

                        <div
                            class="flex w-full items-center justify-center space-x-2"
                        >
                            <div
                                v-if="!manualSetupKey"
                                class="flex w-full items-center justify-center rounded-lg bg-elevated p-3"
                            >
                                <UIcon
                                    name="i-lucide-loader-circle"
                                    class="size-5 animate-spin"
                                />
                            </div>
                            <UFieldGroup v-else class="w-full">
                                <UInput
                                    type="text"
                                    readonly
                                    :model-value="manualSetupKey"
                                    class="w-full"
                                />
                                <UButton
                                    color="neutral"
                                    variant="outline"
                                    :icon="
                                        copied
                                            ? 'i-lucide-check'
                                            : 'i-lucide-copy'
                                    "
                                    aria-label="Copy setup key"
                                    @click="copy(manualSetupKey || '')"
                                />
                            </UFieldGroup>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <Form
                        v-bind="confirm.form()"
                        error-bag="confirmTwoFactorAuthentication"
                        reset-on-error
                        @finish="code = []"
                        @success="isOpen = false"
                        v-slot="{ errors, processing }"
                        class="w-full"
                    >
                        <input type="hidden" name="code" :value="codeValue" />
                        <div
                            ref="pinInputContainerRef"
                            class="relative w-full space-y-3"
                        >
                            <div
                                class="flex w-full flex-col items-center justify-center space-y-3 py-2"
                            >
                                <UPinInput
                                    id="otp"
                                    v-model="code"
                                    :length="6"
                                    otp
                                    :disabled="processing"
                                    autofocus
                                />
                                <p
                                    v-if="errors?.code"
                                    class="text-sm text-error"
                                >
                                    {{ errors.code }}
                                </p>
                            </div>

                            <div class="flex w-full items-center space-x-5">
                                <UButton
                                    type="button"
                                    color="neutral"
                                    variant="outline"
                                    class="w-auto flex-1 justify-center"
                                    :disabled="processing"
                                    label="Back"
                                    @click="showVerificationStep = false"
                                />
                                <UButton
                                    type="submit"
                                    class="w-auto flex-1 justify-center"
                                    :loading="processing"
                                    :disabled="codeValue.length < 6"
                                    label="Confirm"
                                />
                            </div>
                        </div>
                    </Form>
                </template>
            </div>
        </template>
    </UModal>
</template>
