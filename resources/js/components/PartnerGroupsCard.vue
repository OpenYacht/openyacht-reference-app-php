<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import {
    destroy as destroyGroup,
    store as storeGroup,
    update as updateGroup,
} from '@/routes/partner-groups';

type Partner = {
    id: number;
    domain: string;
};

type Group = {
    id: number;
    name: string;
    member_ids: number[];
};

const props = defineProps<{
    groups: Group[];
    partners: Partner[];
}>();

const newName = ref('');
const creating = ref(false);
const savingId = ref<number | null>(null);

// Local editable membership per group, reset from props on each render.
const memberDraft = reactive<Record<number, number[]>>(
    Object.fromEntries(
        props.groups.map((group) => [group.id, [...group.member_ids]]),
    ),
);

const toggleMember = (group: Group, partnerId: number) => {
    const current = memberDraft[group.id] ?? [...group.member_ids];
    memberDraft[group.id] = current.includes(partnerId)
        ? current.filter((id) => id !== partnerId)
        : [...current, partnerId];
};

const create = () => {
    creating.value = true;
    router.post(
        storeGroup.url(),
        { name: newName.value },
        {
            preserveScroll: true,
            onSuccess: () => (newName.value = ''),
            onFinish: () => (creating.value = false),
        },
    );
};

const save = (group: Group) => {
    savingId.value = group.id;
    router.put(
        updateGroup.url(group.id),
        {
            name: group.name,
            member_ids: memberDraft[group.id] ?? group.member_ids,
        },
        {
            preserveScroll: true,
            onFinish: () => (savingId.value = null),
        },
    );
};

const remove = (group: Group) => {
    router.delete(destroyGroup.url(group.id), { preserveScroll: true });
};
</script>

<template>
    <UCard>
        <template #header>
            <div>
                <h3 class="font-semibold">Partner groups</h3>
                <p class="mt-0.5 text-xs text-muted">
                    Named sets of partners that listings can share with as one
                    audience. Membership changes take effect on each partner's
                    next poll — joining a group delivers its listings, leaving
                    it delivers tombstones.
                </p>
            </div>
        </template>

        <div class="space-y-4">
            <form class="flex items-start gap-2" @submit.prevent="create">
                <UInput
                    v-model="newName"
                    placeholder="Group name (e.g. Offices)"
                    class="flex-1"
                    aria-label="New group name"
                />
                <UButton
                    type="submit"
                    icon="i-lucide-plus"
                    label="Create group"
                    :disabled="!newName.trim()"
                    :loading="creating"
                />
            </form>

            <div
                v-for="group in groups"
                :key="group.id"
                class="rounded-lg border border-default p-4"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="font-medium">{{ group.name }}</p>
                    <div class="flex gap-2">
                        <UButton
                            size="xs"
                            :loading="savingId === group.id"
                            label="Save members"
                            @click="save(group)"
                        />
                        <UButton
                            size="xs"
                            color="error"
                            variant="outline"
                            icon="i-lucide-trash-2"
                            aria-label="Delete group"
                            @click="remove(group)"
                        />
                    </div>
                </div>
                <div class="mt-3 grid gap-1 sm:grid-cols-2">
                    <UCheckbox
                        v-for="partner in partners"
                        :key="partner.id"
                        :model-value="
                            (
                                memberDraft[group.id] ?? group.member_ids
                            ).includes(partner.id)
                        "
                        :label="partner.domain"
                        @update:model-value="toggleMember(group, partner.id)"
                    />
                    <p
                        v-if="!partners.length"
                        class="text-xs text-muted sm:col-span-2"
                    >
                        No partners to add yet.
                    </p>
                </div>
            </div>

            <p v-if="!groups.length" class="text-sm text-muted">
                No groups yet.
            </p>
        </div>
    </UCard>
</template>
