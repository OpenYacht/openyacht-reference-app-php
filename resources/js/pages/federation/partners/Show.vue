<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    approve,
    block,
    index,
    refreshKeys,
    show,
    sync,
} from '@/routes/partners';
import { update as updateFieldGroups } from '@/routes/partners/field-groups';

type PartnerKey = {
    key_id: string;
    algorithm: string;
    created_at: string | null;
    pinned: boolean;
};

type Partner = {
    id: number;
    domain: string;
    node_uuid: string | null;
    trust_level: string;
    trust_level_label: string;
    keys: PartnerKey[];
    pinned_key_id: string | null;
    keys_fetched_at: string | null;
    approved_by: string | null;
    last_ok_at: string | null;
    last_synced_at: string | null;
    consecutive_failures: number;
    listing_copies_count: number;
    is_stale: boolean;
    field_groups: string[] | null;
};

const props = defineProps<{
    partner: Partner;
    availableFieldGroups: { value: string; label: string }[];
}>();

// null means every group granted (the pre-grants default).
const grantedFieldGroups = ref<string[]>(
    props.partner.field_groups ??
        props.availableFieldGroups.map((group) => group.value),
);
const savingGrants = ref(false);

const toggleFieldGroup = (value: string) => {
    grantedFieldGroups.value = grantedFieldGroups.value.includes(value)
        ? grantedFieldGroups.value.filter((existing) => existing !== value)
        : [...grantedFieldGroups.value, value];
};

const saveFieldGroups = () => {
    savingGrants.value = true;
    router.put(
        updateFieldGroups.url(props.partner.id),
        { field_groups: grantedFieldGroups.value },
        {
            preserveScroll: true,
            onFinish: () => (savingGrants.value = false),
        },
    );
};

setLayoutProps({
    breadcrumbs: [
        { title: 'Partners', href: index() },
        { title: props.partner.domain, href: show(props.partner.id) },
    ],
});

const toast = useToast();

const act = (url: { url: string; method: string }) => {
    router.post(
        url.url,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.add({
                    title: Object.values(errors)[0] ?? 'Action failed.',
                    color: 'error',
                });
            },
        },
    );
};

const trustColor = (level: string) =>
    ({ verified: 'success', provisional: 'warning', blocked: 'error' })[
        level
    ] ?? 'neutral';
</script>

<template>
    <Head :title="partner.domain" />

    <div class="mx-auto w-full max-w-3xl space-y-8 px-4 py-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-xl font-semibold tracking-tight">
                        {{ partner.domain }}
                    </h2>
                    <UBadge
                        :color="trustColor(partner.trust_level)"
                        variant="subtle"
                        :label="partner.trust_level_label"
                    />
                    <UBadge
                        v-if="partner.is_stale"
                        color="warning"
                        variant="outline"
                        label="Stale"
                    />
                </div>
                <p class="mt-1 text-sm text-muted">
                    {{ partner.listing_copies_count }} synced listings
                    <template v-if="partner.approved_by">
                        · approved by {{ partner.approved_by }}
                    </template>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <UButton
                    v-if="partner.trust_level !== 'verified'"
                    color="success"
                    icon="i-lucide-check"
                    label="Approve"
                    @click="act(approve(partner.id))"
                />
                <UButton
                    v-if="partner.trust_level !== 'blocked'"
                    color="error"
                    variant="soft"
                    icon="i-lucide-ban"
                    label="Block"
                    @click="act(block(partner.id))"
                />
                <UButton
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-key-round"
                    label="Refresh keys"
                    @click="act(refreshKeys(partner.id))"
                />
                <UButton
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-refresh-cw"
                    label="Sync now"
                    @click="act(sync(partner.id))"
                />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <UCard>
                <template #header>
                    <h3 class="font-semibold">Node identity</h3>
                </template>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-muted">Node UUID</dt>
                        <dd class="font-mono text-xs break-all">
                            {{ partner.node_uuid ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted">Keys fetched</dt>
                        <dd>{{ partner.keys_fetched_at ?? 'never' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted">Last reachable</dt>
                        <dd>{{ partner.last_ok_at ?? 'never' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted">Last synced</dt>
                        <dd>{{ partner.last_synced_at ?? 'never' }}</dd>
                    </div>
                    <div v-if="partner.consecutive_failures > 0">
                        <dt class="text-muted">Consecutive failures</dt>
                        <dd class="text-error">
                            {{ partner.consecutive_failures }}
                        </dd>
                    </div>
                </dl>
            </UCard>

            <UCard>
                <template #header>
                    <h3 class="font-semibold">Published keys</h3>
                </template>
                <ul class="space-y-3 text-sm">
                    <li
                        v-for="key in partner.keys"
                        :key="key.key_id"
                        class="flex items-center justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <p class="font-mono text-xs">{{ key.key_id }}</p>
                            <p class="text-xs text-muted">
                                {{ key.algorithm }}
                                <template v-if="key.created_at">
                                    · {{ key.created_at }}
                                </template>
                            </p>
                        </div>
                        <UBadge
                            v-if="key.pinned"
                            color="info"
                            variant="subtle"
                            size="sm"
                            label="Pinned"
                        />
                    </li>
                </ul>
            </UCard>
        </div>

        <UCard>
            <template #header>
                <div>
                    <h3 class="font-semibold">Sharing permissions</h3>
                    <p class="mt-0.5 text-xs text-muted">
                        The field groups this partner receives. Withheld values
                        are nulled server-side before the payload is signed;
                        saving re-gates every visible listing so the partner
                        picks up the change on its next poll.
                    </p>
                </div>
            </template>
            <div class="space-y-4">
                <div class="grid gap-1 sm:grid-cols-2">
                    <UCheckbox
                        v-for="group in availableFieldGroups"
                        :key="group.value"
                        :model-value="grantedFieldGroups.includes(group.value)"
                        :label="group.label"
                        @update:model-value="toggleFieldGroup(group.value)"
                    />
                </div>
                <div class="flex justify-end">
                    <UButton
                        label="Save permissions"
                        :loading="savingGrants"
                        @click="saveFieldGroups"
                    />
                </div>
            </div>
        </UCard>
    </div>
</template>
