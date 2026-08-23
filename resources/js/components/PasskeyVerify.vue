<script setup lang="ts">
import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
};

const props = defineProps<Props>();

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    ...(props.routes
        ? {
              routes: {
                  options: props.routes.options.url,
                  submit: props.routes.submit.url,
              },
          }
        : {}),
    onSuccess: (response) => {
        router.visit(response.redirect ?? '/dashboard');
    },
});
</script>

<template>
    <div v-if="isSupported">
        <div class="grid gap-2">
            <UButton
                type="button"
                color="neutral"
                variant="outline"
                block
                icon="i-lucide-key-round"
                :loading="isLoading"
                @click="verify"
            >
                {{
                    isLoading
                        ? (props.loadingLabel ?? 'Authenticating...')
                        : (props.label ?? 'Sign in with a passkey')
                }}
            </UButton>

            <p v-if="error" class="text-center text-sm text-error">
                {{ error }}
            </p>
        </div>

        <USeparator
            class="my-6"
            :label="props.separator ?? 'Or continue with email'"
            :ui="{ label: 'text-xs uppercase text-muted' }"
        />
    </div>
</template>
