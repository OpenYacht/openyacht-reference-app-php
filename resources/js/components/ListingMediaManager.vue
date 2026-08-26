<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

export type MediaItem = {
    id: number;
    url: string;
    caption: string | null;
    file_name: string;
};

const props = defineProps<{
    yachtName: string;
    profile: MediaItem | null;
    gallery: MediaItem[];
    layouts: MediaItem[];
    documents: MediaItem[];
    storeUrl: string;
    updateUrl: (mediaId: number) => string;
    destroyUrl: (mediaId: number) => string;
}>();

const profileInput = ref<HTMLInputElement>();
const galleryInput = ref<HTMLInputElement>();
const layoutsInput = ref<HTMLInputElement>();
const documentsInput = ref<HTMLInputElement>();

const upload = (
    collection: 'profile' | 'gallery' | 'layouts' | 'documents',
    input?: HTMLInputElement,
) => {
    const file = input?.files?.[0];

    if (!file) {
        return;
    }

    router.post(
        props.storeUrl,
        { collection, file },
        {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => {
                if (input) {
                    input.value = '';
                }
            },
        },
    );
};

const removeMedia = (media: MediaItem) => {
    router.delete(props.destroyUrl(media.id), { preserveScroll: true });
};

// Caption doubles as the image's alt text on the wire.
const saveCaption = (media: MediaItem, caption: string) => {
    if ((media.caption ?? '') === caption) {
        return;
    }

    router.patch(
        props.updateUrl(media.id),
        { caption },
        { preserveScroll: true },
    );
};
</script>

<template>
    <section class="space-y-6 rounded-lg border border-default p-4">
        <div>
            <h3 class="font-semibold">Media</h3>
            <p class="mt-0.5 text-xs text-muted">
                One large image per item goes on the wire — receivers resize to
                their own needs. Captions double as alt text.
            </p>
        </div>

        <!-- Profile: the explicit hero partners must use (LS-8). -->
        <div class="space-y-2">
            <p class="text-sm font-medium">Profile</p>
            <p class="text-xs text-muted">
                The explicit hero image partners must use
            </p>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div
                    class="relative aspect-video w-full overflow-hidden rounded-lg border border-default bg-elevated sm:w-80"
                >
                    <img
                        v-if="profile"
                        :src="profile.url"
                        :alt="yachtName"
                        class="h-full w-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-full items-center justify-center text-sm text-muted"
                    >
                        No profile image
                    </div>
                </div>
                <div class="flex-1 space-y-2">
                    <UInput
                        v-if="profile"
                        :model-value="profile.caption ?? ''"
                        placeholder="Caption / alt text"
                        size="sm"
                        class="w-full"
                        @blur="
                            saveCaption(
                                profile!,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <input
                        ref="profileInput"
                        type="file"
                        accept="image/*"
                        class="hidden"
                        @change="upload('profile', profileInput)"
                    />
                    <UButton
                        color="neutral"
                        variant="outline"
                        size="sm"
                        icon="i-lucide-upload"
                        :label="profile ? 'Replace profile' : 'Upload profile'"
                        @click="profileInput?.click()"
                    />
                </div>
            </div>
        </div>

        <!-- Gallery: full-width grid, built for volume. -->
        <div class="space-y-2">
            <p class="text-sm font-medium">Gallery</p>
            <div
                v-if="gallery.length"
                class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4"
            >
                <div v-for="media in gallery" :key="media.id" class="space-y-1">
                    <div
                        class="group relative aspect-[4/3] overflow-hidden rounded-md border border-default"
                    >
                        <img
                            :src="media.url"
                            :alt="media.caption ?? yachtName"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <UButton
                            color="error"
                            variant="solid"
                            size="xs"
                            icon="i-lucide-x"
                            aria-label="Remove image"
                            class="absolute top-1 right-1 opacity-0 transition-opacity group-hover:opacity-100"
                            @click="removeMedia(media)"
                        />
                    </div>
                    <UInput
                        :model-value="media.caption ?? ''"
                        placeholder="Caption / alt text"
                        size="xs"
                        class="w-full"
                        @blur="
                            saveCaption(
                                media,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </div>
            </div>
            <p v-else class="text-xs text-muted">No gallery images yet.</p>
            <input
                ref="galleryInput"
                type="file"
                accept="image/*"
                class="hidden"
                @change="upload('gallery', galleryInput)"
            />
            <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-image-plus"
                label="Add gallery image"
                @click="galleryInput?.click()"
            />
        </div>

        <!-- Layouts: GA/deck plans as images; plan PDFs go to documents. -->
        <div class="space-y-2">
            <p class="text-sm font-medium">Layouts</p>
            <p class="text-xs text-muted">
                General arrangement and deck plans as images — plan PDFs belong
                in documents
            </p>
            <div
                v-if="layouts.length"
                class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4"
            >
                <div v-for="media in layouts" :key="media.id" class="space-y-1">
                    <div
                        class="group relative aspect-[4/3] overflow-hidden rounded-md border border-default bg-white"
                    >
                        <img
                            :src="media.url"
                            :alt="media.caption ?? 'Layout plan'"
                            class="h-full w-full object-contain"
                            loading="lazy"
                        />
                        <UButton
                            color="error"
                            variant="solid"
                            size="xs"
                            icon="i-lucide-x"
                            aria-label="Remove layout"
                            class="absolute top-1 right-1 opacity-0 transition-opacity group-hover:opacity-100"
                            @click="removeMedia(media)"
                        />
                    </div>
                    <UInput
                        :model-value="media.caption ?? ''"
                        placeholder="Caption (e.g. General arrangement)"
                        size="xs"
                        class="w-full"
                        @blur="
                            saveCaption(
                                media,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </div>
            </div>
            <p v-else class="text-xs text-muted">No layout plans yet.</p>
            <input
                ref="layoutsInput"
                type="file"
                accept="image/*"
                class="hidden"
                @change="upload('layouts', layoutsInput)"
            />
            <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-layout-panel-top"
                label="Add layout plan"
                @click="layoutsInput?.click()"
            />
        </div>

        <!-- Documents: brochures, plan PDFs, sample menus — served only
             under the documents field group (LS-14). -->
        <div class="space-y-2">
            <p class="text-sm font-medium">Documents</p>
            <p class="text-xs text-muted">
                Brochures, plan PDFs, sample menus — partners receive these only
                when granted the documents permission
            </p>
            <ul v-if="documents.length" class="space-y-2">
                <li
                    v-for="media in documents"
                    :key="media.id"
                    class="flex items-center gap-2 rounded-md border border-default p-2"
                >
                    <UIcon
                        name="i-lucide-file-text"
                        class="size-4 shrink-0 text-muted"
                    />
                    <a
                        :href="media.url"
                        target="_blank"
                        rel="noopener"
                        class="min-w-0 truncate text-sm hover:underline"
                    >
                        {{ media.caption ?? media.file_name }}
                    </a>
                    <UInput
                        :model-value="media.caption ?? ''"
                        placeholder="Caption"
                        size="xs"
                        class="ml-auto w-48"
                        @blur="
                            saveCaption(
                                media,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <UButton
                        color="error"
                        variant="ghost"
                        size="xs"
                        icon="i-lucide-x"
                        aria-label="Remove document"
                        @click="removeMedia(media)"
                    />
                </li>
            </ul>
            <p v-else class="text-xs text-muted">No documents yet.</p>
            <input
                ref="documentsInput"
                type="file"
                accept="application/pdf"
                class="hidden"
                @change="upload('documents', documentsInput)"
            />
            <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-file-plus"
                label="Add document (PDF)"
                @click="documentsInput?.click()"
            />
        </div>
    </section>
</template>
