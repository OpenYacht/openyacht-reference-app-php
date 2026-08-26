<script setup lang="ts">
export type RemoteMedia = {
    layouts: {
        url: string;
        thumbnail_url: string | null;
        caption: string | null;
    }[];
    videos: { url: string; caption: string | null }[];
    tours: { url: string; caption: string | null }[];
    documents: { url: string; caption: string | null }[];
};

defineProps<{ media: RemoteMedia }>();

const linkLabel = (
    item: { url: string; caption: string | null },
    fallback: string,
) => {
    if (item.caption) {
        return item.caption;
    }

    try {
        return new URL(item.url).hostname;
    } catch {
        return fallback;
    }
};
</script>

<template>
    <!-- Rendered from the partner's verbatim payload: layout plans prefer
         the authority thumbnail (LS-16); videos, tours, and documents are
         plain links — external platforms embed themselves. All URLs are
         https-validated server-side (FP-14). -->
    <section v-if="media.layouts.length">
        <h3 class="mb-3 text-lg font-semibold">Layouts</h3>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <a
                v-for="(item, i) in media.layouts"
                :key="i"
                :href="item.url"
                target="_blank"
                rel="noopener"
                class="block"
            >
                <img
                    :src="item.thumbnail_url ?? item.url"
                    :alt="item.caption ?? 'Layout plan'"
                    class="aspect-[4/3] w-full rounded-lg border border-default bg-white object-contain"
                    loading="lazy"
                />
                <p v-if="item.caption" class="mt-1 text-xs text-muted">
                    {{ item.caption }}
                </p>
            </a>
        </div>
    </section>

    <section
        v-if="media.videos.length || media.tours.length"
        class="grid gap-4 sm:grid-cols-2"
    >
        <UCard v-if="media.videos.length">
            <template #header>
                <h3 class="font-semibold">Videos</h3>
            </template>
            <ul class="space-y-2 text-sm">
                <li v-for="(video, i) in media.videos" :key="i">
                    <a
                        :href="video.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 hover:underline"
                    >
                        <UIcon
                            name="i-lucide-play-circle"
                            class="size-4 shrink-0 text-muted"
                        />
                        {{ linkLabel(video, 'Video') }}
                    </a>
                </li>
            </ul>
        </UCard>

        <UCard v-if="media.tours.length">
            <template #header>
                <h3 class="font-semibold">Virtual tours</h3>
            </template>
            <ul class="space-y-2 text-sm">
                <li v-for="(tour, i) in media.tours" :key="i">
                    <a
                        :href="tour.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 hover:underline"
                    >
                        <UIcon
                            name="i-lucide-rotate-3d"
                            class="size-4 shrink-0 text-muted"
                        />
                        {{ linkLabel(tour, 'Virtual tour') }}
                    </a>
                </li>
            </ul>
        </UCard>
    </section>

    <section v-if="media.documents.length">
        <UCard>
            <template #header>
                <h3 class="font-semibold">Documents</h3>
            </template>
            <ul class="space-y-2 text-sm">
                <li v-for="(doc, i) in media.documents" :key="i">
                    <a
                        :href="doc.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 hover:underline"
                    >
                        <UIcon
                            name="i-lucide-file-text"
                            class="size-4 shrink-0 text-muted"
                        />
                        {{ linkLabel(doc, 'Document') }}
                    </a>
                </li>
            </ul>
        </UCard>
    </section>
</template>
