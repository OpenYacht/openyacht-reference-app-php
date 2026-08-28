<script setup lang="ts">
/*
 * Stage tabs for the partner listings pages: Synced (every copy a
 * partner has shared, with provenance) and Imported (the subset curated
 * for display). One tab set per wire type; visiting a tab makes it the
 * sticky default for that type's nav item.
 */
import type { NavigationMenuItem } from '@nuxt/ui';
import { computed, onMounted } from 'vue';
import type {
    PartnerListingStage,
    PartnerListingType,
} from '@/lib/partnerListings';
import {
    partnerListingsIndex,
    rememberPartnerListingsStage,
} from '@/lib/partnerListings';
import { toUrl } from '@/lib/utils';

const props = defineProps<{
    listingType: PartnerListingType;
    stage: PartnerListingStage;
}>();

const items = computed<NavigationMenuItem[]>(() => [
    {
        label: 'Synced',
        icon: 'i-lucide-refresh-cw',
        to: toUrl(partnerListingsIndex(props.listingType, 'synced')),
        active: props.stage === 'synced',
    },
    {
        label: 'Imported',
        icon: 'i-lucide-download',
        to: toUrl(partnerListingsIndex(props.listingType, 'imported')),
        active: props.stage === 'imported',
    },
]);

onMounted(() => {
    rememberPartnerListingsStage(props.listingType, props.stage);
});
</script>

<template>
    <UNavigationMenu :items="items" orientation="horizontal" highlight />
</template>
