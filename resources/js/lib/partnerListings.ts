import { reactive } from 'vue';
import { index as importedCharterIndex } from '@/routes/imported-charter-yachts';
import { index as importedSaleIndex } from '@/routes/imported-yachts';
import { index as syncedCharterIndex } from '@/routes/synced-charter-listings';
import { index as syncedSaleIndex } from '@/routes/synced-listings';

/*
 * Partner listings live under one nav item per wire type (sale and
 * charter are never mixed), with the two pipeline stages — synced
 * copies and curated imports — as tabs. The last-visited stage is
 * remembered per type so the nav item reopens where the user left off.
 */

export type PartnerListingType = 'sale' | 'charter';
export type PartnerListingStage = 'synced' | 'imported';

export const partnerListingsLabel = (type: PartnerListingType): string =>
    type === 'charter' ? 'Partner charter' : 'Partner sale';

export const partnerListingsIndex = (
    type: PartnerListingType,
    stage: PartnerListingStage,
): ReturnType<typeof syncedSaleIndex> => {
    if (stage === 'imported') {
        return type === 'charter'
            ? importedCharterIndex()
            : importedSaleIndex();
    }

    return type === 'charter' ? syncedCharterIndex() : syncedSaleIndex();
};

const storageKey = (type: PartnerListingType): string =>
    `partner-listings-tab:${type}`;

const storedStage = (type: PartnerListingType): PartnerListingStage => {
    try {
        return localStorage.getItem(storageKey(type)) === 'imported'
            ? 'imported'
            : 'synced';
    } catch {
        // Storage can be unavailable (private mode, blocked site data).
        return 'synced';
    }
};

/*
 * Reactive so the sidebar link updates as soon as a tab is visited;
 * localStorage alone would only be re-read on the next navigation.
 */
const stages = reactive<Record<PartnerListingType, PartnerListingStage>>({
    sale: storedStage('sale'),
    charter: storedStage('charter'),
});

export const rememberPartnerListingsStage = (
    type: PartnerListingType,
    stage: PartnerListingStage,
): void => {
    stages[type] = stage;

    try {
        localStorage.setItem(storageKey(type), stage);
    } catch {
        // Losing the sticky default is fine; the tab itself still works.
    }
};

export const lastPartnerListingsStage = (
    type: PartnerListingType,
): PartnerListingStage => stages[type];
