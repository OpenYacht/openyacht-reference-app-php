<script lang="ts">
export type ListingBadge = {
    label: string;
    color?: 'neutral' | 'primary' | 'success' | 'info' | 'warning' | 'error';
    variant?: 'subtle' | 'outline' | 'solid' | 'soft';
};
</script>

<script setup lang="ts">
/*
 * The normalised card every listing index uses (own, imported, synced):
 * hero image with overlaid badges (image and title both link to the
 * detail page when one exists), muted meta line, body
 * slot for page-specific lines (price, provenance, actions), and optional
 * attribution. One component keeps the three pages from drifting apart.
 */
import { Link  } from '@inertiajs/vue3';
import type {InertiaLinkProps} from '@inertiajs/vue3';

defineProps<{
    title: string;
    href?: InertiaLinkProps['href'];
    image?: string | null;
    imageSrcset?: string | null;
    badges?: ListingBadge[];
    meta?: string | null;
    attribution?: string | null;
    /** Media is still being fetched — show a spinner, not "no image". */
    pending?: boolean;
    dimmed?: boolean;
}>();
</script>

<template>
    <UCard
        :ui="{ body: 'p-0 sm:p-0' }"
        class="overflow-hidden"
        :class="{ 'opacity-60': dimmed }"
    >
        <component
            :is="href ? Link : 'div'"
            :href="href"
            class="relative block aspect-video bg-elevated"
        >
            <img
                v-if="image"
                :src="image"
                :srcset="imageSrcset ?? undefined"
                sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"
                :alt="title"
                class="absolute inset-0 h-full w-full object-cover"
                loading="lazy"
                referrerpolicy="no-referrer"
            />
            <div
                v-else
                class="absolute inset-0 flex items-center justify-center"
            >
                <UIcon
                    :name="
                        pending ? 'i-lucide-loader-circle' : 'i-lucide-image-off'
                    "
                    class="size-8 text-muted"
                    :class="{ 'animate-spin': pending }"
                />
            </div>
            <div class="absolute top-2 right-2 flex gap-1">
                <UBadge
                    v-for="badge in badges"
                    :key="badge.label"
                    :color="badge.color ?? 'neutral'"
                    :variant="badge.variant ?? 'solid'"
                    size="sm"
                    :label="badge.label"
                />
            </div>
        </component>

        <div class="space-y-2 p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <Link
                        v-if="href"
                        :href="href"
                        class="block truncate font-semibold hover:underline"
                    >
                        {{ title }}
                    </Link>
                    <p v-else class="truncate font-semibold">{{ title }}</p>
                    <slot name="meta">
                        <p v-if="meta" class="truncate text-sm text-muted">
                            {{ meta }}
                        </p>
                    </slot>
                </div>
                <slot name="trailing" />
            </div>

            <slot />

            <p v-if="attribution" class="text-xs text-dimmed italic">
                {{ attribution }}
            </p>
        </div>
    </UCard>
</template>
