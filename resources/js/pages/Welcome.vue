<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';

const toast = useToast();
</script>

<template>
    <Head title="Welcome" />
    <UApp>
        <div
            class="flex min-h-screen flex-col items-center justify-center gap-6 p-6"
        >
            <h1 class="text-2xl font-semibold">OpenYacht Reference App</h1>
            <p class="max-w-md text-center text-sm text-muted">
                A complete, conventional implementation of the OpenYacht
                federation protocol.
            </p>
            <div class="flex items-center gap-3">
                <UButton
                    v-if="$page.props.auth.user"
                    :to="dashboard.url()"
                    label="Dashboard"
                />
                <template v-else>
                    <UButton
                        :to="login.url()"
                        label="Log in"
                        variant="outline"
                    />
                    <UButton :to="register.url()" label="Register" />
                </template>
                <UButton
                    label="Test toast"
                    color="neutral"
                    variant="subtle"
                    @click="
                        toast.add({
                            title: 'Nuxt UI is working',
                            description: 'Toasts render through UApp.',
                        })
                    "
                />
            </div>
        </div>
    </UApp>
</template>
