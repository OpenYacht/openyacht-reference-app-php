<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type PartnerOption = {
    id: number;
    domain: string;
    node_name: string | null;
    sharing_scope: string;
};

type GroupOption = {
    id: number;
    name: string;
    members_count: number;
};

const props = defineProps<{
    updateUrl: string;
    audience: string;
    selectedPartnerIds: number[];
    selectedGroupIds: number[];
    partners: PartnerOption[];
    groups: GroupOption[];
}>();

const audience = ref(props.audience);
const partnerIds = ref<number[]>([...props.selectedPartnerIds]);
const groupIds = ref<number[]>([...props.selectedGroupIds]);
const partnerSearch = ref('');
const saving = ref(false);

const audienceOptions = [
    {
        value: 'everyone',
        label: 'Everyone',
        hint: 'Every approved standard partner receives this listing; curated partners only if picked below.',
    },
    {
        value: 'selected',
        label: 'Selected partners',
        hint: 'Only the partners and groups picked below.',
    },
    {
        value: 'none',
        label: 'No one',
        hint: 'Withdrawn from every partner feed — tombstoned like a real withdrawal.',
    },
];

// Explicit picks are additive: under an everyone audience they extend
// the listing to curated partners (standard partners already receive
// it), so the everyone section offers only curated partners.
const curatedPartners = computed(() =>
    props.partners.filter((partner) => partner.sharing_scope === 'curated'),
);

// Once the partner list outgrows a handful, checkboxes become a
// searchable list.
const filteredPartners = computed(() => {
    const query = partnerSearch.value.trim().toLowerCase();

    if (query === '') {
        return props.partners;
    }

    return props.partners.filter(
        (partner) =>
            partner.domain.toLowerCase().includes(query) ||
            (partner.node_name ?? '').toLowerCase().includes(query),
    );
});

const togglePartner = (id: number) => {
    partnerIds.value = partnerIds.value.includes(id)
        ? partnerIds.value.filter((existing) => existing !== id)
        : [...partnerIds.value, id];
};

const toggleGroup = (id: number) => {
    groupIds.value = groupIds.value.includes(id)
        ? groupIds.value.filter((existing) => existing !== id)
        : [...groupIds.value, id];
};

const save = () => {
    saving.value = true;
    router.put(
        props.updateUrl,
        {
            audience: audience.value,
            // Picks persist under everyone AND selected (additive); a
            // none audience leaves the stored selection untouched.
            partner_ids: audience.value === 'none' ? [] : partnerIds.value,
            group_ids: audience.value === 'none' ? [] : groupIds.value,
        },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = false),
        },
    );
};
</script>

<template>
    <UCard>
        <template #header>
            <div>
                <h3 class="font-semibold">Sharing</h3>
                <p class="mt-0.5 text-xs text-muted">
                    Who receives this listing over federation. Changes reach
                    each affected partner on its next poll — hidden listings
                    surface as tombstones, revealed ones as updates.
                </p>
            </div>
        </template>

        <div class="space-y-4">
            <fieldset class="space-y-2">
                <label
                    v-for="option in audienceOptions"
                    :key="option.value"
                    class="flex cursor-pointer items-start gap-2 rounded-md border border-default p-3"
                    :class="{
                        'border-primary bg-primary/5':
                            audience === option.value,
                    }"
                >
                    <input
                        v-model="audience"
                        type="radio"
                        name="audience"
                        :value="option.value"
                        class="mt-1"
                    />
                    <span>
                        <span class="block text-sm font-medium">{{
                            option.label
                        }}</span>
                        <span class="block text-xs text-muted">{{
                            option.hint
                        }}</span>
                    </span>
                </label>
            </fieldset>

            <div
                v-if="
                    audience === 'everyone' &&
                    (curatedPartners.length || groups.length)
                "
                class="space-y-4 rounded-md border border-default p-3"
            >
                <p class="text-xs text-muted">
                    Also share with curated partners — they receive only what is
                    picked here, directly or through a group.
                </p>

                <div v-if="groups.length">
                    <p class="mb-2 text-sm font-medium">Partner groups</p>
                    <div class="space-y-1">
                        <UCheckbox
                            v-for="group in groups"
                            :key="group.id"
                            :model-value="groupIds.includes(group.id)"
                            :label="`${group.name} (${group.members_count})`"
                            @update:model-value="toggleGroup(group.id)"
                        />
                    </div>
                </div>

                <div v-if="curatedPartners.length">
                    <p class="mb-2 text-sm font-medium">Curated partners</p>
                    <div class="max-h-56 space-y-1 overflow-y-auto">
                        <UCheckbox
                            v-for="partner in curatedPartners"
                            :key="partner.id"
                            :model-value="partnerIds.includes(partner.id)"
                            :label="partner.node_name ?? partner.domain"
                            :description="
                                partner.node_name ? partner.domain : undefined
                            "
                            @update:model-value="togglePartner(partner.id)"
                        />
                    </div>
                </div>
            </div>

            <div v-if="audience === 'selected'" class="space-y-4">
                <div v-if="groups.length">
                    <p class="mb-2 text-sm font-medium">Partner groups</p>
                    <div class="space-y-1">
                        <UCheckbox
                            v-for="group in groups"
                            :key="group.id"
                            :model-value="groupIds.includes(group.id)"
                            :label="`${group.name} (${group.members_count})`"
                            @update:model-value="toggleGroup(group.id)"
                        />
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-medium">Individual partners</p>
                    <UInput
                        v-if="partners.length > 10"
                        v-model="partnerSearch"
                        placeholder="Search partners…"
                        icon="i-lucide-search"
                        class="mb-2 w-full"
                    />
                    <p v-if="!partners.length" class="text-xs text-muted">
                        No partners yet.
                    </p>
                    <div class="max-h-56 space-y-1 overflow-y-auto">
                        <UCheckbox
                            v-for="partner in filteredPartners"
                            :key="partner.id"
                            :model-value="partnerIds.includes(partner.id)"
                            :label="partner.node_name ?? partner.domain"
                            :description="
                                partner.node_name ? partner.domain : undefined
                            "
                            @update:model-value="togglePartner(partner.id)"
                        />
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <UButton label="Save sharing" :loading="saving" @click="save" />
            </div>
        </div>
    </UCard>
</template>
