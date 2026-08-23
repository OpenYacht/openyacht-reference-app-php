<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/roles';
import { update } from '@/routes/roles/permissions';

type RoleRow = {
    value: string;
    label: string;
    editable: boolean;
    users_count: number;
    permissions: string[];
};

type PermissionOption = {
    value: string;
    label: string;
};

const props = defineProps<{
    roles: RoleRow[];
    permissions: PermissionOption[];
    canEdit: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Roles',
                href: index(),
            },
        ],
    },
});

const toast = useToast();

const togglePermission = (
    role: RoleRow,
    permission: string,
    granted: boolean,
) => {
    const permissions = granted
        ? [...role.permissions, permission]
        : role.permissions.filter((name) => name !== permission);

    router.put(
        update.url({ role: role.value }),
        { permissions },
        {
            preserveScroll: true,
            onError: (errors) => {
                toast.add({
                    title:
                        errors.permissions ??
                        'Could not update the permissions.',
                    color: 'error',
                });
            },
        },
    );
};
</script>

<template>
    <Head title="Roles" />

    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
        <Heading
            title="Roles & permissions"
            description="What each role is allowed to do on this node"
        />

        <div class="overflow-x-auto rounded-lg border border-default">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-default bg-elevated/50">
                        <th class="p-3 text-left font-medium">Permission</th>
                        <th
                            v-for="role in props.roles"
                            :key="role.value"
                            class="p-3 text-center font-medium"
                        >
                            <div>{{ role.label }}</div>
                            <div class="text-xs font-normal text-muted">
                                {{ role.users_count }}
                                {{ role.users_count === 1 ? 'user' : 'users' }}
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="permission in props.permissions"
                        :key="permission.value"
                        class="border-b border-default last:border-b-0"
                    >
                        <td class="p-3">{{ permission.label }}</td>
                        <td
                            v-for="role in props.roles"
                            :key="role.value"
                            class="p-3 text-center"
                        >
                            <UCheckbox
                                :model-value="
                                    role.permissions.includes(permission.value)
                                "
                                :disabled="!props.canEdit || !role.editable"
                                :aria-label="`${permission.label} for ${role.label}`"
                                class="inline-flex"
                                @update:model-value="
                                    togglePermission(
                                        role,
                                        permission.value,
                                        $event === true,
                                    )
                                "
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-sm text-muted">
            The super admin role always holds every permission. Permission
            changes take effect immediately and are recorded in the activity
            log.
        </p>
    </div>
</template>
