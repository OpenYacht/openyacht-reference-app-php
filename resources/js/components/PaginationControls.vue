<script setup lang="ts">
/*
 * Previous/next paging under a paginated list. The paginator's URLs
 * already carry the active filters (withQueryString), so following one
 * keeps the filter state without the page having to rebuild it.
 */
import { router } from '@inertiajs/vue3';
import type { Paginated } from '@/types/pagination';

defineProps<{
    paginator: Omit<Paginated<unknown>, 'data'>;
}>();

const visit = (url: string | null) => {
    if (url) {
        router.visit(url, { preserveScroll: true });
    }
};
</script>

<template>
    <div v-if="paginator.total > 0" class="flex items-center justify-between">
        <p class="text-sm text-muted">
            Showing {{ paginator.from }}–{{ paginator.to }} of
            {{ paginator.total }}
        </p>
        <div class="flex items-center gap-2">
            <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-chevron-left"
                label="Previous"
                :disabled="!paginator.prev_page_url"
                @click="visit(paginator.prev_page_url)"
            />
            <UButton
                color="neutral"
                variant="outline"
                size="sm"
                trailing-icon="i-lucide-chevron-right"
                label="Next"
                :disabled="!paginator.next_page_url"
                @click="visit(paginator.next_page_url)"
            />
        </div>
    </div>
</template>
