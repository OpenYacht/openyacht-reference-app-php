<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import PartnerGroupsCard from '@/components/PartnerGroupsCard.vue';
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
    is_hidden: boolean;
};

type Group = {
    id: number;
    name: string;
    member_ids: number[];
    acceptance_policy: string | null;
};

defineProps<{
    partners: Partner[];
    groups: Group[];
    acceptancePolicyOptions: { value: string; label: string }[];
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

// The contact the partner's administrators can reply to; the acting
// user is the default, editable per request.
const defaultContactEmail = usePage().props.auth.user.email;

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

        <UCard>
            <template #header>
                <div>
                    <h3 class="font-semibold">Add a partner</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        The node fetches the partner's discovery document,
                        stores it for your approval, and sends a signed
                        partnership request so it appears on their side too.
                        Your message and contact email travel with the request.
                    </p>
                </div>
            </template>
            <Form
                v-bind="store.form()"
                reset-on-success
                v-slot="{ errors, processing }"
                class="space-y-4"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <UFormField
                        label="Partner domain"
                        :error="errors.domain"
                        required
                    >
                        <UInput
                            name="domain"
                            placeholder="openyacht.partner-brokerage.com"
                            class="w-full"
                        />
                    </UFormField>
                    <UFormField
                        label="Contact email"
                        :error="errors.contact_email"
                    >
                        <UInput
                            name="contact_email"
                            type="email"
                            :default-value="defaultContactEmail"
                            class="w-full"
                        />
                    </UFormField>
                </div>
                <UFormField label="Message" :error="errors.message">
                    <UTextarea
                        name="message"
                        class="w-full"
                        :rows="2"
                        autoresize
                        placeholder="Who you are and why you would like to federate — optional; a standard introduction is sent if left blank."
                    />
                </UFormField>
                <div class="flex flex-wrap justify-end gap-2">
                    <UButton
                        :to="directoryIndex.url()"
                        icon="i-lucide-book-open"
                        variant="outline"
                        color="neutral"
                        label="Find partners"
                    />
                    <UButton
                        type="submit"
                        :loading="processing"
                        icon="i-lucide-send"
                        label="Add and send request"
                    />
                </div>
            </Form>
        </UCard>

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
                            v-if="partner.is_hidden"
                            color="neutral"
                            variant="outline"
                            size="sm"
                            label="Hidden from public"
                        />
                        <UBadge
                            v-else-if="partner.is_stale"
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
            fetch its discovery document, store it for your approval, and
            introduce itself to the partner.
        </p>

        <PartnerGroupsCard
            :groups="groups"
            :partners="partners"
            :acceptance-policy-options="acceptancePolicyOptions"
        />
    </div>
</template>
