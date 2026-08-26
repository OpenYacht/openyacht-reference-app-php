<?php

namespace App\Enums;

/**
 * A partner's acceptance policy — the consumer-side decision of what
 * happens to listings after sync. Entirely local: the spec's only
 * human approval is the partner itself (FP-13); it has no per-listing
 * acceptance step, and ID-7's 24-hour propagation obligation already
 * makes everything after the first accept automatic. Sync always stores
 * the copy; this policy only decides whether the copy is also published
 * (imported for display) without a person clicking per listing.
 *
 * // yacht-identity.md §What everyone else holds, §Lifecycle (ID-7)
 */
enum AcceptancePolicy: string
{
    /** Every listing waits in the synced-listings queue for a person. */
    case Review = 'review';

    /**
     * Publish listings that pass the completeness check; queue the rest.
     * The recommended default for a verified partner: field-group gating
     * (LS-14) means a legitimately shared listing can arrive with pricing
     * or exact location withheld, and auto-publishing those puts
     * POA-shaped holes on a public site.
     */
    case AcceptComplete = 'accept_complete';

    /** Publish everything the partner shares (usage terms permitting). */
    case AcceptAll = 'accept_all';

    public function label(): string
    {
        return __('federation.acceptance_policies.'.$this->value);
    }
}
