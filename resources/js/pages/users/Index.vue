<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { index, store } from '@/routes/users';
import { update } from '@/routes/users/role';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    role_label: string | null;
    created_at: string | null;
};

type RoleOption = {
    value: string;
    label: string;
};

defineProps<{
    users: ManagedUser[];
    roles: RoleOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Users',
                href: index(),
            },
        ],
    },
});

const page = usePage();
const toast = useToast();

const showCreateModal = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: '',
});

const submit = () =>
    form.submit(store(), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });

const changeRole = (user: ManagedUser, role: string) => {
    router.put(
        update.url({ user: user.id }),
        { role },
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.add({
                    title: errors.role ?? 'Could not change the role.',
                    color: 'error',
                });
            },
        },
    );
};
</script>

<template>
    <Head title="Users" />

    <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Users"
                description="Manage who can access this node and what they can do"
            />
            <UButton
                icon="i-lucide-plus"
                label="New user"
                @click="showCreateModal = true"
            />
        </div>

        <div class="overflow-hidden rounded-lg border border-default">
            <div
                v-for="user in users"
                :key="user.id"
                class="flex flex-col gap-3 border-b border-default p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <UAvatar :alt="user.name" size="md" />
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="truncate font-medium">{{ user.name }}</p>
                            <UBadge
                                v-if="user.id === page.props.auth.user.id"
                                color="neutral"
                                variant="subtle"
                                size="sm"
                                label="You"
                            />
                        </div>
                        <p class="truncate text-sm text-muted">
                            {{ user.email }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <UBadge
                        v-if="user.id === page.props.auth.user.id"
                        color="neutral"
                        variant="outline"
                        :label="user.role_label ?? 'No role'"
                    />
                    <USelect
                        v-else
                        :model-value="user.role ?? undefined"
                        :items="roles"
                        value-key="value"
                        placeholder="No role"
                        class="w-44"
                        @update:model-value="changeRole(user, $event as string)"
                    />
                </div>
            </div>
        </div>

        <p class="text-sm text-muted">
            Role changes take effect immediately and are recorded in the
            activity log.
        </p>

        <UModal
            v-model:open="showCreateModal"
            title="New user"
            description="Self-registration is disabled, so accounts are created here. The address is treated as verified — no confirmation email is sent."
        >
            <template #body>
                <form class="space-y-4" @submit.prevent="submit">
                    <UFormField label="Name" :error="form.errors.name" required>
                        <UInput
                            v-model="form.name"
                            class="w-full"
                            autocomplete="off"
                            placeholder="Alex Marlow"
                        />
                    </UFormField>

                    <UFormField
                        label="Email"
                        :error="form.errors.email"
                        required
                    >
                        <UInput
                            v-model="form.email"
                            type="email"
                            class="w-full"
                            autocomplete="off"
                            placeholder="alex@example.com"
                        />
                    </UFormField>

                    <UFormField
                        label="Password"
                        :error="form.errors.password"
                        help="Share it with them directly — they can change it under Settings."
                        required
                    >
                        <UInput
                            v-model="form.password"
                            type="password"
                            class="w-full"
                            autocomplete="new-password"
                        />
                    </UFormField>

                    <UFormField label="Role" :error="form.errors.role" required>
                        <USelect
                            v-model="form.role"
                            :items="roles"
                            value-key="value"
                            placeholder="Select a role"
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
                            label="Create user"
                        />
                    </div>
                </form>
            </template>
        </UModal>
    </div>
</template>
