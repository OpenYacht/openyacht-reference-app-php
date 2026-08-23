<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { index as directoryIndex } from '@/routes/node-directory';
import { index, show, store } from '@/routes/partners';

type Partner = {
    id: number;
    domain: string;
    trust_level: string;
    trust_level_label: string;
    listing_copies_count: number;
    last_ok_at: string | null;
    last_synced_at: string | null;
    consecutive_failures: number;
    is_stale: boolean;
};

defineProps<{
    partners: Partner[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Partners',
                href: index(),
            },
        ],
    },
});

const trustColor = (level: string) =>
    ({ verified: 'success', provisional: 'warning', blocked: 'error' })[
        level
    ] ?? 'neutral';
</script>

<template>
    <Head title="Partners" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
        <Heading
            title="Federation partners"
            description="Brokerages this node exchanges listings with"
        />

        <div class="flex max-w-2xl items-start gap-2">
            <Form
                v-bind="store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="flex flex-1 items-start gap-2"
            >
                <UFormField class="flex-1" :error="errors.domain">
                    <UInput
                        name="domain"
                        placeholder="openyacht.partner-brokerage.com"
                        class="w-full"
                        aria-label="Partner domain"
                    />
                </UFormField>
                <UButton
                    type="submit"
                    :loading="processing"
                    icon="i-lucide-plus"
                    label="Add partner"
                />
            </Form>
            <UButton
                :to="directoryIndex.url()"
                icon="i-lucide-book-open"
                variant="outline"
                color="neutral"
                label="Find partners"
            />
        </div>

        <div
            v-if="partners.length"
            class="overflow-hidden rounded-lg border border-default"
        >
            <Link
                v-for="partner in partners"
                :key="partner.id"
                :href="show(partner.id)"
                class="flex flex-col gap-2 border-b border-default p-4 transition-colors last:border-b-0 hover:bg-elevated/50 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="truncate font-medium">{{ partner.domain }}</p>
                        <UBadge
                            :color="trustColor(partner.trust_level)"
                            variant="subtle"
                            size="sm"
                            :label="partner.trust_level_label"
                        />
                        <UBadge
                            v-if="partner.is_stale"
                            color="warning"
                            variant="outline"
                            size="sm"
                            label="Stale"
                        />
                    </div>
                    <p class="text-sm text-muted">
                        {{ partner.listing_copies_count }} listings
                        <template v-if="partner.last_synced_at">
                            · synced {{ partner.last_synced_at }}
                        </template>
                        <template v-if="partner.consecutive_failures > 0">
                            · {{ partner.consecutive_failures }} failed attempts
                        </template>
                    </p>
                </div>
                <UIcon
                    name="i-lucide-chevron-right"
                    class="hidden size-4 shrink-0 text-muted sm:block"
                />
            </Link>
        </div>

        <p v-else class="text-sm text-muted">
            No partners yet. Add one by its identity domain — the node will
            fetch its discovery document and store it for your approval.
        </p>
    </div>
</template>
