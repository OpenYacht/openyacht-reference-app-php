<script setup lang="ts">
/* eslint-disable vue/no-mutating-props --
 * The parent passes its Inertia useForm object, which is designed to be
 * mutated by field bindings wherever it is used.
 */
import type { ListingForm } from '@/components/listing-form/types';

defineProps<{ form: ListingForm }>();
</script>

<template>
    <div class="space-y-4 rounded-lg border border-default p-4">
        <div>
            <p class="text-sm font-medium">Videos &amp; virtual tours</p>
            <p class="text-xs text-muted">
                External platform links (YouTube, Vimeo, Matterport…) — the wire
                carries the URL, receivers embed it themselves
            </p>
        </div>

        <div class="space-y-3">
            <p class="text-xs font-medium text-muted">Videos</p>
            <div
                v-for="(video, i) in form.videos"
                :key="`video-${i}`"
                class="flex items-end gap-2"
            >
                <UFormField
                    label="URL"
                    class="flex-1"
                    :error="form.errors[`videos.${i}.url`]"
                >
                    <UInput
                        v-model="video.url"
                        class="w-full"
                        placeholder="https://vimeo.com/…"
                    />
                </UFormField>
                <UFormField label="Caption" class="w-56">
                    <UInput
                        v-model="video.caption"
                        class="w-full"
                        placeholder="Walkthrough"
                    />
                </UFormField>
                <UButton
                    color="error"
                    variant="ghost"
                    icon="i-lucide-trash-2"
                    aria-label="Remove video"
                    @click="form.videos.splice(i, 1)"
                />
            </div>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add video"
                @click="form.videos.push({ url: '', caption: '' })"
            />
        </div>

        <div class="space-y-3">
            <p class="text-xs font-medium text-muted">Virtual tours</p>
            <div
                v-for="(tour, i) in form.tours"
                :key="`tour-${i}`"
                class="flex items-end gap-2"
            >
                <UFormField
                    label="URL"
                    class="flex-1"
                    :error="form.errors[`tours.${i}.url`]"
                >
                    <UInput
                        v-model="tour.url"
                        class="w-full"
                        placeholder="https://my.matterport.com/show/…"
                    />
                </UFormField>
                <UFormField label="Caption" class="w-56">
                    <UInput
                        v-model="tour.caption"
                        class="w-full"
                        placeholder="Main deck tour"
                    />
                </UFormField>
                <UButton
                    color="error"
                    variant="ghost"
                    icon="i-lucide-trash-2"
                    aria-label="Remove tour"
                    @click="form.tours.splice(i, 1)"
                />
            </div>
            <UButton
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                label="Add virtual tour"
                @click="form.tours.push({ url: '', caption: '' })"
            />
        </div>
    </div>
</template>
