<?php

namespace App\Services\Federation;

use App\Enums\ListingStatus;
use App\Enums\VisibilityTransition;
use App\Jobs\DeliverSubscriptionChange;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use App\Models\VisibilityEvent;
use Illuminate\Support\Collection;

/**
 * The authority half of push subscriptions (API-10): partners register
 * one HTTPS callback each, and every change a partner's feed would
 * report is delivered there as a signed POST instead of waiting for
 * its next poll.
 *
 * Changes are not tracked separately for push — they are derived from
 * the two things the polled feed already keys on: the
 * federation_updated_at stamp on the listing (content and lifecycle
 * changes) and the per-partner visibility events (shares, unshares,
 * grant refreshes). Each source queues one delivery per subscribed
 * partner; the delivery job asks payloadFor() at send time, which
 * makes the same decision as the feed for that (listing, partner) pair,
 * so a retried delivery carries the current state rather than a stale
 * snapshot and the consumer can deduplicate on (id, updated_at).
 *
 * // api-design.md §Subscriptions
 */
class SubscriptionService
{
    public function __construct(
        private SharingService $sharing,
        private ListingSerializer $serializer,
        private OutboundUrlGuard $guard,
    ) {}

    /**
     * Register (or replace) the partner's callback. The URL is
     * partner-supplied, so it passes the outbound guard before it is
     * stored — this node will POST to it from the server.
     *
     * @throws BlockedOutboundHost when the callback is not a plain HTTPS URL on a public host
     */
    public function register(FederationPartner $partner, string $callbackUrl): void
    {
        $this->guard->assertPublicHttpsUrl($callbackUrl);

        $replaced = $partner->push_callback_url;

        $partner->update([
            'push_callback_url' => $callbackUrl,
            'push_callback_registered_at' => now(),
        ]);

        activity('federation')
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain, 'callback' => $callbackUrl, 'replaced' => $replaced])
            ->event('subscription_registered')
            ->log("{$partner->domain} subscribed to pushes at {$callbackUrl}");
    }

    /**
     * Remove the partner's callback. Deliveries already queued notice
     * the missing callback when they run and do nothing.
     */
    public function unregister(FederationPartner $partner): void
    {
        if ($partner->push_callback_url === null) {
            return;
        }

        $partner->update([
            'push_callback_url' => null,
            'push_callback_registered_at' => null,
        ]);

        activity('federation')
            ->performedOn($partner)
            ->withProperties(['domain' => $partner->domain])
            ->event('subscription_removed')
            ->log("{$partner->domain} unsubscribed from pushes");
    }

    /**
     * A listing's federation_updated_at moved (content edit, lifecycle
     * transition, first publication): queue a delivery to every
     * subscribed partner. Which partners actually receive anything is
     * decided per pair at send time — a partner the listing is not
     * visible to gets nothing, the same as its feed.
     */
    public function listingChanged(SaleYacht|CharterYacht $listing): void
    {
        foreach ($this->subscribers() as $partner) {
            DeliverSubscriptionChange::dispatch($partner, $listing->uuid);
        }
    }

    /**
     * A visibility transition was recorded for one partner: queue a
     * delivery to that partner alone — hidden becomes a tombstone,
     * visible and refreshed resend the listing.
     */
    public function visibilityChanged(VisibilityEvent $event): void
    {
        $partner = FederationPartner::query()->find($event->federation_partner_id);

        if ($partner === null || ! $partner->receivesPushes()) {
            return;
        }

        DeliverSubscriptionChange::dispatch($partner, $event->listing_uuid);
    }

    /**
     * What to deliver to one partner for one listing right now — the
     * feed's per-row decision computed for a single pair: nothing for a
     * draft or a listing the partner never saw; a tombstone stamped at
     * the transition for one that became invisible; the listing (or its
     * ended-status tombstone) otherwise. The effective timestamp is
     * GREATEST(federation_updated_at, latest visibility event), as in
     * the feed.
     *
     * @return array<string, mixed>|null
     */
    public function payloadFor(SaleYacht|CharterYacht $listing, FederationPartner $partner): ?array
    {
        if ($listing->status === ListingStatus::Draft || $listing->federation_updated_at === null) {
            return null;
        }

        $latestEvent = VisibilityEvent::query()
            ->where('federation_partner_id', $partner->id)
            ->where('listing_uuid', $listing->uuid)
            ->latest('id')
            ->first();

        $visibleNow = $this->sharing->isVisibleTo($listing, $partner);

        // Invisible and never hidden: the partner never saw it, and its
        // feed would not list it either.
        if (! $visibleNow && $latestEvent?->event !== VisibilityTransition::Hidden) {
            return null;
        }

        $effectiveUpdatedAt = $latestEvent !== null && $latestEvent->occurred_at->greaterThan($listing->federation_updated_at)
            ? $latestEvent->occurred_at
            : $listing->federation_updated_at;

        return $this->serializer->feedItem($listing, $partner, $visibleNow, $effectiveUpdatedAt);
    }

    /**
     * Resolve a canonical UUID to its listing, whichever table holds it
     * (UUIDs never collide across the two).
     */
    public function findListing(string $uuid): SaleYacht|CharterYacht|null
    {
        return SaleYacht::query()
            ->with(['vessel', 'assignedBroker', 'priceHistory', 'media'])
            ->where('uuid', $uuid)
            ->first()
            ?? CharterYacht::query()
                ->with(['vessel', 'assignedBroker', 'media'])
                ->where('uuid', $uuid)
                ->first();
    }

    /**
     * The partners currently entitled to pushes.
     *
     * @return Collection<int, FederationPartner>
     */
    private function subscribers(): Collection
    {
        return FederationPartner::query()
            ->whereNotNull('push_callback_url')
            ->get()
            ->filter(fn (FederationPartner $partner): bool => $partner->receivesPushes())
            ->values();
    }
}
