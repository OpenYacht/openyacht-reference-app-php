<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { destroy, index, store, update } from '@/routes/api-keys';

type ApiKeyRow = {
    id: number;
    name: string;
    key_prefix: string;
    scopes: string[];
    domains: string[] | null;
    rate_limit: number;
    is_active: boolean;
    last_used_at: string | null;
    created_at: string;
};

type ScopeOption = {
    value: string;
    description: string;
};

const props = defineProps<{
    keys: ApiKeyRow[];
    availableScopes: ScopeOption[];
    newKey: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'API keys',
                href: index(),
            },
        ],
    },
});

const showCreateModal = ref(false);
const toast = useToast();

const form = useForm({
    name: '',
    scopes: [] as string[],
    domains: '',
    rate_limit: 60,
});

const submit = () =>
    form.submit(store(), {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });

const toggleScope = (scope: string, granted: boolean) => {
    form.scopes = granted
        ? [...form.scopes, scope]
        : form.scopes.filter((name) => name !== scope);
};

const toggleActive = (key: ApiKeyRow) => {
    router.put(
        update.url({ apiKey: key.id }),
        { is_active: !key.is_active },
        { preserveScroll: true },
    );
};

const removeKey = (key: ApiKeyRow) => {
    router.delete(destroy.url({ apiKey: key.id }), { preserveScroll: true });
};

const copyNewKey = async () => {
    if (props.newKey) {
        await navigator.clipboard.writeText(props.newKey);
        toast.add({ title: 'Key copied to clipboard.' });
    }
};
</script>

<template>
    <Head title="API keys" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="API keys"
                description="Keys for websites and feeds consuming this node's yacht data"
            />
            <UButton
                icon="i-lucide-plus"
                label="New key"
                @click="showCreateModal = true"
            />
        </div>

        <UAlert
            v-if="newKey"
            color="warning"
            variant="subtle"
            icon="i-lucide-key-round"
            title="Copy this key now — it will not be shown again"
        >
            <template #description>
                <div class="mt-1 flex items-center gap-2">
                    <code
                        class="rounded bg-elevated px-2 py-1 font-mono text-xs break-all"
                        >{{ newKey }}</code
                    >
                    <UButton
                        color="neutral"
                        variant="outline"
                        size="xs"
                        icon="i-lucide-copy"
                        aria-label="Copy key"
                        @click="copyNewKey"
                    />
                </div>
            </template>
        </UAlert>

        <div
            v-if="keys.length"
            class="overflow-hidden rounded-lg border border-default"
        >
            <div
                v-for="key in keys"
                :key="key.id"
                class="flex flex-col gap-2 border-b border-default p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                :class="{ 'opacity-60': !key.is_active }"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium">{{ key.name }}</p>
                        <code class="font-mono text-xs text-muted"
                            >{{ key.key_prefix }}…</code
                        >
                        <UBadge
                            v-for="scope in key.scopes"
                            :key="scope"
                            color="neutral"
                            variant="subtle"
                            size="sm"
                            :label="scope"
                        />
                        <UBadge
                            v-if="!key.is_active"
                            color="error"
                            variant="outline"
                            size="sm"
                            label="Revoked"
                        />
                    </div>
                    <p class="text-sm text-muted">
                        {{ key.rate_limit }} req/min
                        <template v-if="key.domains?.length">
                            · {{ key.domains.join(', ') }}
                        </template>
                        · last used {{ key.last_used_at ?? 'never' }} · created
                        {{ key.created_at }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <UButton
                        color="neutral"
                        variant="outline"
                        size="sm"
                        :label="key.is_active ? 'Revoke' : 'Reactivate'"
                        @click="toggleActive(key)"
                    />
                    <UButton
                        color="error"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-trash-2"
                        aria-label="Delete key"
                        @click="removeKey(key)"
                    />
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-muted">
            No API keys yet. Create one to let a website or feed consume this
            node's yacht data.
        </p>

        <UModal
            v-model:open="showCreateModal"
            title="New API key"
            description="The key is shown once after creation — store it in the consumer's secret storage."
        >
            <template #body>
                <form class="space-y-4" @submit.prevent="submit">
                    <UFormField label="Name" :error="form.errors.name" required>
                        <UInput
                            v-model="form.name"
                            class="w-full"
                            placeholder="Marketing website"
                        />
                    </UFormField>

                    <UFormField label="Scopes" :error="form.errors.scopes">
                        <div class="space-y-2">
                            <UCheckbox
                                v-for="scope in availableScopes"
                                :key="scope.value"
                                :model-value="form.scopes.includes(scope.value)"
                                :label="scope.value"
                                :description="scope.description"
                                @update:model-value="
                                    toggleScope(scope.value, $event === true)
                                "
                            />
                        </div>
                    </UFormField>

                    <UFormField
                        label="Allowed domains"
                        :error="form.errors.domains"
                        help="Optional, comma separated — browser requests from other origins are refused"
                    >
                        <UInput
                            v-model="form.domains"
                            class="w-full"
                            placeholder="example.com, www.example.com"
                        />
                    </UFormField>

                    <UFormField
                        label="Rate limit (requests per minute)"
                        :error="form.errors.rate_limit"
                    >
                        <UInput
                            v-model.number="form.rate_limit"
                            type="number"
                            class="w-full"
                        />
                    </UFormField>

                    <div class="flex justify-end gap-2">
                        <UButton
                            type="button"
                            color="neutral"
                            variant="soft"
                            label="Cancel"
                            @click="showCreateModal = false"
                        />
                        <UButton
                            type="submit"
                            :loading="form.processing"
                            label="Create key"
                        />
                    </div>
                </form>
            </template>
        </UModal>
    </div>
</template>
