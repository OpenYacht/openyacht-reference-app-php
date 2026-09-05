<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { destroy, index, store, test, update } from '@/routes/webhooks';

type DeliveryRow = {
    id: number;
    reason: string;
    attempt: number;
    succeeded: boolean;
    http_status: number | null;
    error: string | null;
    duration_ms: number;
    created_at: string;
};

type EndpointRow = {
    id: number;
    name: string;
    url: string;
    has_secret: boolean;
    is_active: boolean;
    last_succeeded_at: string | null;
    last_failed_at: string | null;
    consecutive_failures: number;
    created_at: string;
    deliveries: DeliveryRow[];
};

defineProps<{
    endpoints: EndpointRow[];
    cooldownMinutes: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Webhooks',
                href: index(),
            },
        ],
    },
});

const showModal = ref(false);
const editing = ref<EndpointRow | null>(null);
const expandedLogs = ref<number[]>([]);

const form = useForm({
    name: '',
    url: '',
    secret: '',
    clear_secret: false,
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (endpoint: EndpointRow) => {
    editing.value = endpoint;
    form.reset();
    form.clearErrors();
    form.name = endpoint.name;
    form.url = endpoint.url;
    showModal.value = true;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
            form.reset();
        },
    };

    if (editing.value) {
        form.put(update.url({ webhook: editing.value.id }), options);
    } else {
        form.post(store.url(), options);
    }
};

const generateSecret = () => {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    form.secret = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
    form.clear_secret = false;
};

const toggleActive = (endpoint: EndpointRow) => {
    router.put(
        update.url({ webhook: endpoint.id }),
        { is_active: !endpoint.is_active },
        { preserveScroll: true },
    );
};

const sendTest = (endpoint: EndpointRow) => {
    router.post(
        test.url({ webhook: endpoint.id }),
        {},
        { preserveScroll: true },
    );
};

const removeEndpoint = (endpoint: EndpointRow) => {
    router.delete(destroy.url({ webhook: endpoint.id }), {
        preserveScroll: true,
    });
};

const toggleLog = (endpoint: EndpointRow) => {
    expandedLogs.value = expandedLogs.value.includes(endpoint.id)
        ? expandedLogs.value.filter((id) => id !== endpoint.id)
        : [...expandedLogs.value, endpoint.id];
};

const healthBadge = (endpoint: EndpointRow) => {
    if (endpoint.deliveries.length === 0) {
        return { color: 'neutral' as const, label: 'Never delivered' };
    }

    if (endpoint.consecutive_failures > 0) {
        return {
            color: 'error' as const,
            label: `Failing · ${endpoint.consecutive_failures} in a row`,
        };
    }

    return { color: 'success' as const, label: 'Healthy' };
};
</script>

<template>
    <Head title="Webhooks" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Webhooks"
                description="Consumers that are told when this node's public content changes"
            />
            <UButton
                icon="i-lucide-plus"
                label="New webhook"
                @click="openCreate"
            />
        </div>

        <UAlert
            color="neutral"
            variant="subtle"
            icon="i-lucide-info"
            title="How change notifications work"
        >
            <template #description>
                Each endpoint receives one POST per batch of changes — a sync
                cycle, an import, an edit to a published listing — never one per
                listing, with a {{ cooldownMinutes }}-minute cooldown between
                pings. The body carries only a timestamp, a reason and counts;
                consumers fetch the details from the data API. The secret, when
                set, is sent as the
                <code class="font-mono text-xs"
                    >X-OpenYacht-Webhook-Secret</code
                >
                header.
            </template>
        </UAlert>

        <div
            v-if="endpoints.length"
            class="overflow-hidden rounded-lg border border-default"
        >
            <div
                v-for="endpoint in endpoints"
                :key="endpoint.id"
                class="border-b border-default last:border-b-0"
            >
                <div
                    class="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between"
                    :class="{ 'opacity-60': !endpoint.is_active }"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium">{{ endpoint.name }}</p>
                            <UBadge
                                :color="healthBadge(endpoint).color"
                                variant="subtle"
                                size="sm"
                                :label="healthBadge(endpoint).label"
                            />
                            <UBadge
                                v-if="endpoint.has_secret"
                                color="neutral"
                                variant="outline"
                                size="sm"
                                icon="i-lucide-lock"
                                label="Secret set"
                            />
                            <UBadge
                                v-if="!endpoint.is_active"
                                color="error"
                                variant="outline"
                                size="sm"
                                label="Disabled"
                            />
                        </div>
                        <code
                            class="mt-1 block font-mono text-xs break-all text-muted"
                            >{{ endpoint.url }}</code
                        >
                        <p class="mt-1 text-sm text-muted">
                            last success
                            {{ endpoint.last_succeeded_at ?? 'never' }}
                            <template v-if="endpoint.last_failed_at">
                                · last failure {{ endpoint.last_failed_at }}
                            </template>
                            · added {{ endpoint.created_at }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <UButton
                            color="neutral"
                            variant="outline"
                            size="sm"
                            icon="i-lucide-send"
                            label="Send test"
                            @click="sendTest(endpoint)"
                        />
                        <UButton
                            color="neutral"
                            variant="outline"
                            size="sm"
                            :label="endpoint.is_active ? 'Disable' : 'Enable'"
                            @click="toggleActive(endpoint)"
                        />
                        <UButton
                            color="neutral"
                            variant="ghost"
                            size="sm"
                            icon="i-lucide-pencil"
                            aria-label="Edit webhook"
                            @click="openEdit(endpoint)"
                        />
                        <UButton
                            color="error"
                            variant="ghost"
                            size="sm"
                            icon="i-lucide-trash-2"
                            aria-label="Delete webhook"
                            @click="removeEndpoint(endpoint)"
                        />
                    </div>
                </div>

                <div class="px-4 pb-3">
                    <UButton
                        color="neutral"
                        variant="link"
                        size="xs"
                        :icon="
                            expandedLogs.includes(endpoint.id)
                                ? 'i-lucide-chevron-down'
                                : 'i-lucide-chevron-right'
                        "
                        :label="`Delivery log (${endpoint.deliveries.length})`"
                        :disabled="endpoint.deliveries.length === 0"
                        @click="toggleLog(endpoint)"
                    />

                    <div
                        v-if="
                            expandedLogs.includes(endpoint.id) &&
                            endpoint.deliveries.length
                        "
                        class="mt-2 overflow-x-auto rounded border border-default"
                    >
                        <table class="w-full text-left text-xs">
                            <thead class="bg-elevated text-muted">
                                <tr>
                                    <th class="px-3 py-2 font-medium">When</th>
                                    <th class="px-3 py-2 font-medium">
                                        Result
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Reason
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Duration
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="delivery in endpoint.deliveries"
                                    :key="delivery.id"
                                    class="border-t border-default align-top"
                                >
                                    <td
                                        class="px-3 py-2 font-mono whitespace-nowrap"
                                    >
                                        {{ delivery.created_at }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <UBadge
                                            :color="
                                                delivery.succeeded
                                                    ? 'success'
                                                    : 'error'
                                            "
                                            variant="subtle"
                                            size="sm"
                                            :label="
                                                delivery.http_status
                                                    ? `HTTP ${delivery.http_status}`
                                                    : 'Failed'
                                            "
                                        />
                                        <span
                                            v-if="delivery.attempt > 1"
                                            class="ml-1 text-muted"
                                            >attempt
                                            {{ delivery.attempt }}</span
                                        >
                                    </td>
                                    <td
                                        class="px-3 py-2 break-words text-muted"
                                    >
                                        {{ delivery.reason }}
                                        <p
                                            v-if="
                                                !delivery.succeeded &&
                                                delivery.error
                                            "
                                            class="mt-1 text-error"
                                        >
                                            {{ delivery.error }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right font-mono whitespace-nowrap text-muted"
                                    >
                                        {{ delivery.duration_ms }} ms
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-muted">
            No webhooks yet. Add one to have a website rebuild, or a cache
            clear, whenever this node's public listings change.
        </p>

        <UModal
            v-model:open="showModal"
            :title="editing ? 'Edit webhook' : 'New webhook'"
            description="The endpoint receives a POST with a timestamp, a reason and change counts."
        >
            <template #body>
                <form class="space-y-4" @submit.prevent="submit">
                    <UFormField label="Name" :error="form.errors.name" required>
                        <UInput
                            v-model="form.name"
                            class="w-full"
                            placeholder="Marketing website deploy hook"
                        />
                    </UFormField>

                    <UFormField label="URL" :error="form.errors.url" required>
                        <UInput
                            v-model="form.url"
                            type="url"
                            class="w-full"
                            placeholder="https://example.com/hooks/openyacht"
                        />
                    </UFormField>

                    <UFormField
                        label="Secret"
                        :error="form.errors.secret"
                        :help="
                            editing?.has_secret
                                ? 'Leave blank to keep the current secret. It cannot be shown again — generate a new one to rotate it.'
                                : 'Optional. Sent as X-OpenYacht-Webhook-Secret so the consumer can reject pings from anyone else.'
                        "
                    >
                        <div class="flex gap-2">
                            <UInput
                                v-model="form.secret"
                                class="w-full font-mono"
                                autocomplete="off"
                                :disabled="form.clear_secret"
                            />
                            <UButton
                                type="button"
                                color="neutral"
                                variant="outline"
                                icon="i-lucide-dices"
                                label="Generate"
                                @click="generateSecret"
                            />
                        </div>
                    </UFormField>

                    <UCheckbox
                        v-if="editing?.has_secret"
                        v-model="form.clear_secret"
                        label="Remove the secret"
                        description="Pings will be sent without the header."
                    />

                    <div class="flex justify-end gap-2">
                        <UButton
                            type="button"
                            color="neutral"
                            variant="soft"
                            label="Cancel"
                            @click="showModal = false"
                        />
                        <UButton
                            type="submit"
                            :loading="form.processing"
                            :label="editing ? 'Save changes' : 'Add webhook'"
                        />
                    </div>
                </form>
            </template>
        </UModal>
    </div>
</template>
