<?php

namespace App\Services\Federation;

use App\Enums\Audience;
use App\Enums\ListingStatus;
use App\Enums\SharingScope;
use App\Enums\VisibilityTransition;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Models\SaleYacht;
use App\Models\VisibilityEvent;
use Illuminate\Support\Collection;

/**
 * Per-listing, per-partner sharing, ported from the WordPress plugin's
 * live-verified implementation. Audience rules narrow what each
 * partner's feed contains; every visibility change is recorded as an
 * append-only transition so the feed can replay it against any
 * watermark. Unsharing surfaces as a tombstone indistinguishable from
 * withdrawn (no information leak); re-sharing surfaces the listing
 * again.
 *
 * Audience rules compose INSIDE the node's served-set rule (drafts never
 * serve, blocked partners never read) — they only ever narrow it.
 *
 * Visibility is answered in exactly two places: isVisibleTo() here and
 * the one SQL fragment in the feed query
 * (ListingsController::visibleSql) — the two must stay mirrored.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
class SharingService
{
    /**
     * The rule, stated once: visible ⇔ audience is not "none" AND
     * (an explicit pivot matches OR (audience is "everyone" AND the
     * partner's sharing scope is standard)). Pivots are additive — an
     * explicit selection grants under any non-none audience, which is how
     * a curated partner is reached at all; the everyone audience covers
     * standard partners only.
     */
    public function isVisibleTo(SaleYacht|CharterYacht $listing, FederationPartner $partner): bool
    {
        if ($listing->audience === Audience::None) {
            return false;
        }

        if ($listing->audience === Audience::Everyone && $partner->sharing_scope === SharingScope::Standard) {
            return true;
        }

        return $listing->audiencePartners()->whereKey($partner->id)->exists()
            || $listing->audienceGroups()
                ->whereHas('members', fn ($query) => $query->whereKey($partner->id))
                ->exists();
    }

    /**
     * Change a listing's audience, recording a became-hidden /
     * became-visible transition for every partner whose view changed.
     * A selected audience is the union of individually selected partners
     * and the members of selected groups.
     *
     * @param  list<int>  $selectedPartnerIds
     * @param  list<int>  $selectedGroupIds
     * @return array{hidden: int, revealed: int}
     */
    public function setAudience(SaleYacht|CharterYacht $listing, Audience $audience, array $selectedPartnerIds = [], array $selectedGroupIds = []): array
    {
        $selectedPartnerIds = array_values(array_unique(array_map(intval(...), $selectedPartnerIds)));
        $selectedGroupIds = array_values(array_unique(array_map(intval(...), $selectedGroupIds)));
        $now = now();
        $hidden = 0;
        $revealed = 0;

        $groupMemberIds = PartnerGroup::query()
            ->whereKey($selectedGroupIds)
            ->with('members:id')
            ->get()
            ->flatMap(fn (PartnerGroup $group) => $group->members->modelKeys())
            ->all();

        $visibleAfter = fn (FederationPartner $partner): bool => match ($audience) {
            Audience::Everyone => $partner->sharing_scope === SharingScope::Standard
                || in_array($partner->id, $selectedPartnerIds, true)
                || in_array($partner->id, $groupMemberIds, true),
            Audience::None => false,
            Audience::Selected => in_array($partner->id, $selectedPartnerIds, true)
                || in_array($partner->id, $groupMemberIds, true),
        };

        // Drafts are never distributed (LS-7): audience changes before
        // first publication need no transitions — no partner ever saw the
        // listing, so there is nothing to tombstone or resurface.
        if ($listing->status !== ListingStatus::Draft) {
            foreach (FederationPartner::all() as $partner) {
                $before = $this->isVisibleTo($listing, $partner);
                $after = $visibleAfter($partner);

                if ($before === $after) {
                    continue;
                }

                VisibilityEvent::create([
                    'listing_uuid' => $listing->uuid,
                    'federation_partner_id' => $partner->id,
                    'event' => $after ? VisibilityTransition::Visible : VisibilityTransition::Hidden,
                    'occurred_at' => $now,
                ]);
                $after ? $revealed++ : $hidden++;
            }
        }

        // Pivots persist under everyone AND selected (additive: under
        // everyone they extend the audience to curated partners). A none
        // audience leaves them untouched — hiding a listing must not
        // destroy its curated selection; none already hides everything.
        if ($audience !== Audience::None) {
            $listing->audiencePartners()->sync($selectedPartnerIds);
            $listing->audienceGroups()->sync($selectedGroupIds);
        }
        // Not mass-assignable and deliberately not stamped: the concern's
        // updating hook skips federation_updated_at for audience-only
        // changes — the events above move exactly the affected partners.
        $listing->audience = $audience;
        $listing->save();

        if ($hidden + $revealed > 0) {
            activity('sharing')
                ->performedOn($listing)
                ->withProperties(['uuid' => $listing->uuid, 'audience' => $audience->value, 'hidden' => $hidden, 'revealed' => $revealed])
                ->event('audience_changed')
                ->log("Audience for {$listing->uuid} set to {$audience->value}: {$hidden} partner(s) lose visibility, {$revealed} gain it");
        }

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Change a partner's sharing scope, recording a visibility transition
     * for every listing whose view changes: narrowing to curated
     * tombstones everything the partner saw only through the everyone
     * audience (explicit shares survive), and widening back to standard
     * resurfaces the same set. No listing row is touched, so
     * federation_updated_at cannot move.
     *
     * @return array{hidden: int, revealed: int}
     */
    public function setSharingScope(FederationPartner $partner, SharingScope $scope): array
    {
        if ($partner->sharing_scope === $scope) {
            return ['hidden' => 0, 'revealed' => 0];
        }

        $now = now();
        $hidden = 0;
        $revealed = 0;

        // Drafts are never distributed (LS-7); every other status can have
        // been seen and so can need a tombstone or a resurface.
        $listings = SaleYacht::query()->where('status', '!=', ListingStatus::Draft)->get()
            ->concat(CharterYacht::query()->where('status', '!=', ListingStatus::Draft)->get());

        $before = [];

        foreach ($listings as $listing) {
            $before[$listing->uuid] = $this->isVisibleTo($listing, $partner);
        }

        $partner->update(['sharing_scope' => $scope]);

        foreach ($listings as $listing) {
            $isVisible = $this->isVisibleTo($listing, $partner);

            if ($before[$listing->uuid] === $isVisible) {
                continue;
            }

            VisibilityEvent::create([
                'listing_uuid' => $listing->uuid,
                'federation_partner_id' => $partner->id,
                'event' => $isVisible ? VisibilityTransition::Visible : VisibilityTransition::Hidden,
                'occurred_at' => $now,
            ]);
            $isVisible ? $revealed++ : $hidden++;
        }

        if ($hidden + $revealed > 0) {
            activity('sharing')
                ->performedOn($partner)
                ->withProperties(['domain' => $partner->domain, 'sharing_scope' => $scope->value, 'hidden' => $hidden, 'revealed' => $revealed])
                ->event('sharing_scope_changed')
                ->log("Sharing scope for {$partner->domain} set to {$scope->value}: {$hidden} listing(s) hidden, {$revealed} revealed");
        }

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Replace a partner's direct shares within one listing type — the
     * partner-page picker's write path. Same pivot table the per-listing
     * sharing card edits, same diffing: snapshot visibility per affected
     * listing, apply, re-ask, emit a transition per changed listing.
     * Group-derived visibility is untouched (removing a direct share
     * emits no tombstone while a group still grants the listing), and
     * drafts accept shares silently — nothing was ever distributed, so
     * there is no transition to record until activation (LS-7).
     *
     * @param  'sale'|'charter'  $type
     * @param  list<string>  $uuids  the partner's new full direct-share list within the type
     * @return array{hidden: int, revealed: int}
     */
    public function replaceDirectSharesForPartner(FederationPartner $partner, string $type, array $uuids): array
    {
        $uuids = array_values(array_unique(array_map(strval(...), $uuids)));
        $query = $type === 'sale' ? SaleYacht::query() : CharterYacht::query();

        $currentUuids = $query->clone()
            ->whereHas('audiencePartners', fn ($q) => $q->whereKey($partner->id))
            ->pluck('uuid')
            ->all();

        /** @var Collection<int, SaleYacht|CharterYacht> $affected */
        $affected = $query->clone()
            ->whereIn('uuid', array_unique([...$currentUuids, ...$uuids]))
            ->get();

        $now = now();
        $hidden = 0;
        $revealed = 0;
        $before = [];

        foreach ($affected as $listing) {
            $before[$listing->uuid] = $this->isVisibleTo($listing, $partner);
        }

        foreach ($affected as $listing) {
            in_array($listing->uuid, $uuids, true)
                ? $listing->audiencePartners()->syncWithoutDetaching([$partner->id])
                : $listing->audiencePartners()->detach($partner->id);
        }

        foreach ($affected as $listing) {
            if ($listing->status === ListingStatus::Draft) {
                continue;
            }

            $isVisible = $this->isVisibleTo($listing, $partner);

            if ($before[$listing->uuid] === $isVisible) {
                continue;
            }

            VisibilityEvent::create([
                'listing_uuid' => $listing->uuid,
                'federation_partner_id' => $partner->id,
                'event' => $isVisible ? VisibilityTransition::Visible : VisibilityTransition::Hidden,
                'occurred_at' => $now,
            ]);
            $isVisible ? $revealed++ : $hidden++;
        }

        if ($hidden + $revealed > 0) {
            activity('sharing')
                ->performedOn($partner)
                ->withProperties(['domain' => $partner->domain, 'type' => $type, 'hidden' => $hidden, 'revealed' => $revealed])
                ->event('direct_shares_replaced')
                ->log("Direct {$type} shares for {$partner->domain} replaced: {$hidden} listing(s) hidden, {$revealed} revealed");
        }

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Change a group's membership, recording visibility transitions for
     * every (listing, partner) pair whose view changes — a partner added
     * to a group immediately receives every listing that selects it, and
     * a removed partner gets tombstones (unless still visible another
     * way), without touching any listing.
     *
     * @param  list<int>  $partnerIds  the group's new full member list
     * @return array{hidden: int, revealed: int}
     */
    public function replaceGroupMembers(PartnerGroup $group, array $partnerIds): array
    {
        $partnerIds = array_values(array_unique(array_map(intval(...), $partnerIds)));
        $affected = FederationPartner::query()
            ->whereKey(array_merge($group->members()->pluck('federation_partners.id')->all(), $partnerIds))
            ->get();
        $now = now();
        $hidden = 0;
        $revealed = 0;

        // Snapshot each affected partner's visibility on each listing that
        // selects this group, apply the change, then diff — membership is
        // just another way for the answer to isVisibleTo() to change.
        $listings = $this->findByUuids($group->listingUuidsSelecting())
            ->reject(fn (SaleYacht|CharterYacht $listing): bool => $listing->status === ListingStatus::Draft);

        $before = [];

        foreach ($listings as $listing) {
            foreach ($affected as $partner) {
                $before[$listing->uuid][$partner->id] = $this->isVisibleTo($listing, $partner);
            }
        }

        $group->members()->sync($partnerIds);

        foreach ($listings as $listing) {
            foreach ($affected as $partner) {
                $wasVisible = $before[$listing->uuid][$partner->id];
                $isVisible = $this->isVisibleTo($listing, $partner);

                if ($wasVisible === $isVisible) {
                    continue;
                }

                VisibilityEvent::create([
                    'listing_uuid' => $listing->uuid,
                    'federation_partner_id' => $partner->id,
                    'event' => $isVisible ? VisibilityTransition::Visible : VisibilityTransition::Hidden,
                    'occurred_at' => $now,
                ]);
                $isVisible ? $revealed++ : $hidden++;
            }
        }

        if ($hidden + $revealed > 0) {
            activity('sharing')
                ->performedOn($group)
                ->withProperties(['group' => $group->name, 'hidden' => $hidden, 'revealed' => $revealed])
                ->event('group_membership_changed')
                ->log("Group \"{$group->name}\" membership changed: {$hidden} (listing, partner) pair(s) lose visibility, {$revealed} gain it");
        }

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Delete a group safely: empty its membership first so the visibility
     * transitions land in the event log, then remove its rows (the
     * membership and listing-selection rows cascade).
     */
    public function deleteGroup(PartnerGroup $group): void
    {
        $this->replaceGroupMembers($group, []);
        $group->delete();

        activity('sharing')
            ->withProperties(['group' => $group->name])
            ->event('group_deleted')
            ->log("Partner group \"{$group->name}\" deleted");
    }

    /**
     * A partner's served view changed wholesale (its field-group grants):
     * lift every visible listing's effective timestamp for that partner so
     * its next poll picks up the re-gated payloads instead of waiting for
     * the next content change (API-4). The feed's hidden-tombstone branch
     * keys on the hidden event specifically, so refreshed rows serialise
     * as normal listings.
     *
     * @return int listings refreshed
     */
    public function refreshPartnerFeed(FederationPartner $partner, string $reason = 'grants changed'): int
    {
        $now = now();
        $refreshed = 0;

        foreach ([SaleYacht::query(), CharterYacht::query()] as $query) {
            $listings = $query
                ->whereIn('status', [ListingStatus::Active, ListingStatus::UnderOffer])
                ->get()
                ->filter(fn (SaleYacht|CharterYacht $listing): bool => $this->isVisibleTo($listing, $partner));

            foreach ($listings as $listing) {
                VisibilityEvent::create([
                    'listing_uuid' => $listing->uuid,
                    'federation_partner_id' => $partner->id,
                    'event' => VisibilityTransition::Refreshed,
                    'occurred_at' => $now,
                ]);
                $refreshed++;
            }
        }

        if ($refreshed > 0) {
            activity('sharing')
                ->performedOn($partner)
                ->withProperties(['domain' => $partner->domain, 'reason' => $reason, 'refreshed' => $refreshed])
                ->event('feed_refreshed')
                ->log("Feed refreshed for {$partner->domain} ({$reason}): {$refreshed} listing(s) will resend");
        }

        return $refreshed;
    }

    /**
     * Resolve canonical UUIDs to listings across both typed tables.
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, SaleYacht|CharterYacht>
     */
    private function findByUuids(array $uuids)
    {
        return SaleYacht::query()->whereIn('uuid', $uuids)->get()
            ->concat(CharterYacht::query()->whereIn('uuid', $uuids)->get());
    }
}
