<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard, login } from '@/routes';
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
                <UButton v-else :to="login.url()" label="Log in" />
            </div>
        </div>
    </UApp>
</template>
