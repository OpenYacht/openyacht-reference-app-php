<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <UApp>
        <div
            class="flex min-h-svh flex-col items-center justify-center gap-6 bg-default p-6 md:p-10"
        >
            <div class="w-full max-w-sm">
                <div class="flex flex-col gap-8">
                    <div class="flex flex-col items-center gap-4">
                        <Link
                            :href="home()"
                            class="flex flex-col items-center gap-2 font-medium"
                        >
                            <img
                                src="/brand/openyacht-lockup-stacked.svg"
                                alt=""
                                class="mb-1 h-24 w-auto dark:hidden"
                            />
                            <img
                                src="/brand/openyacht-lockup-stacked-reversed.svg"
                                alt=""
                                class="mb-1 hidden h-24 w-auto dark:block"
                            />
                            <span class="sr-only">{{ title }}</span>
                        </Link>
                        <div class="space-y-2 text-center">
                            <h1 class="text-xl font-medium">{{ title }}</h1>
                            <p class="text-center text-sm text-muted">
                                {{ description }}
                            </p>
                        </div>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
    </UApp>
</template>
