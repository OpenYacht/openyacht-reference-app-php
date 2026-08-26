<?php

namespace App\Services\Federation;

use App\Enums\AcceptancePolicy;
use App\Enums\ListingStatus;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pull-based listing sync: cold full sync on first run, incremental
 * updated_since polling afterwards (API-2). Tombstones mark copies
 * withdrawn/sold and remove them from public display (ID-7); the
 * updated_since watermark is the authority's meta.generated_at so clock
 * skew between nodes cannot open a gap.
 *
 * // api-design.md §Listings
 * // yacht-identity.md §What everyone else holds
 */
class SyncService
{
    public function __construct(
        private SignedClient $client,
        private ImportService $imports,
        private VesselIdentityMatcher $matcher,
    ) {}

    /**
     * @throws Throwable on sync failure, after recording the failed attempt
     */
    public function sync(FederationPartner $partner): SyncResult
    {
        $partner->update(['last_attempted_at' => now()]);

        try {
            $result = $this->pullAllPages($partner);
        } catch (Throwable $exception) {
            $partner->increment('consecutive_failures');

            throw $exception;
        }

        return $result;
    }

    /**
     * Exponential backoff between failed attempts, capped at 24 hours.
     *
     * // federation-protocol.md §Health and Failure Handling
     */
    public function isDue(FederationPartner $partner): bool
    {
        if ($partner->consecutive_failures === 0 || $partner->last_attempted_at === null) {
            return true;
        }

        $delaySeconds = min(3600 * (2 ** ($partner->consecutive_failures - 1)), 86400);

        return $partner->last_attempted_at->addSeconds($delaySeconds)->isPast();
    }

    private function pullAllPages(FederationPartner $partner): SyncResult
    {
        $created = $updated = $tombstoned = 0;
        $watermark = null;
        $cursor = null;

        // Resolved once per run: the most permissive of the partner's own
        // setting and its groups' — a "trusted partners" group set to
        // auto-publish covers every member.
        $policy = $partner->effectiveAcceptancePolicy();

        do {
            $query = array_filter([
                'page_size' => 100,
                'updated_since' => $partner->last_synced_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'cursor' => $cursor,
            ]);

            $response = $this->client
                ->get($partner, '/openyacht/v1/listings?'.http_build_query($query))
                ->throw();

            $page = $response->json();

            foreach ($page['data'] ?? [] as $item) {
                match ($this->apply($partner, $item, $policy)) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'tombstoned' => $tombstoned++,
                    default => null,
                };
            }

            $watermark ??= data_get($page, 'meta.generated_at');
            $cursor = data_get($page, 'meta.next_cursor');
        } while ($cursor !== null);

        $partner->update([
            'last_ok_at' => now(),
            'consecutive_failures' => 0,
            'last_synced_at' => $watermark !== null ? Carbon::parse($watermark) : now(),
        ]);

        return new SyncResult($created, $updated, $tombstoned);
    }

    /**
     * Apply one listing or tombstone to the copies table. The canonical
     * URI is compared as an opaque string (ID-2); copies carry the
     * mandatory provenance fields (ID-3) and are stored verbatim, never
     * substantively modified (ID-5).
     *
     * @param  array<string, mixed>  $item
     */
    private function apply(FederationPartner $partner, array $item, AcceptancePolicy $policy): string
    {
        $canonicalUri = $item['id'] ?? null;

        if (! is_string($canonicalUri)) {
            return 'skipped';
        }

        if (($item['tombstone'] ?? false) === true) {
            $status = ListingStatus::tryFrom($item['status'] ?? '') ?? ListingStatus::Withdrawn;

            $copy = ListingCopy::query()
                ->where('federation_partner_id', $partner->id)
                ->where('canonical_uri', $canonicalUri)
                ->whereNull('tombstoned_at')
                ->first();

            if ($copy !== null) {
                $copy->update([
                    'status' => $status,
                    'tombstoned_at' => now(),
                    'listing_updated_at' => isset($item['updated_at']) ? Carbon::parse($item['updated_at']) : now(),
                ]);

                // The listing ended: its projection and cached media are
                // removed per the usage terms (ID-7, ID-10).
                $this->imports->expire($copy);
            }

            return 'tombstoned';
        }

        $copy = ListingCopy::query()->updateOrCreate(
            [
                'federation_partner_id' => $partner->id,
                'canonical_uri' => $canonicalUri,
            ],
            [
                'authority_domain' => $partner->domain,
                'type' => $item['type'] ?? 'sale',
                'status' => ListingStatus::tryFrom($item['status'] ?? '') ?? ListingStatus::Active,
                'name' => data_get($item, 'listing.name'),
                'payload' => $item,
                'listing_updated_at' => isset($item['updated_at']) ? Carbon::parse($item['updated_at']) : null,
                'received_at' => now(),
                // Obtained directly from the authority on its identity
                // domain over verified TLS, via a signed request.
                'signature_verified' => true,
                'tombstoned_at' => null,
            ],
        );

        $this->reconcileIdentityConflicts($copy);

        if (! $copy->wasRecentlyCreated) {
            $this->imports->refresh($copy);
        }

        // Sync is not publication: the copy is always stored, and whether
        // it is also published is the partner's acceptance policy — the
        // spec has no per-listing approval step, and everything after the
        // first accept is already automatic (ID-7).
        if ($copy->import()->doesntExist() && $this->shouldAutoPublish($policy, $copy)) {
            $this->imports->import($copy, auto: true);
        }

        return $copy->wasRecentlyCreated ? 'created' : 'updated';
    }

    /**
     * Publish every already-synced, still-unimported copy the partner's
     * effective acceptance policy now allows. Called when a policy
     * loosens — putting a partner in a trusted group should publish its
     * queued backlog, not wait for each listing's next upstream change.
     *
     * @return int copies published
     */
    public function publishEligibleBacklog(FederationPartner $partner): int
    {
        $policy = $partner->effectiveAcceptancePolicy();
        $published = 0;

        $backlog = ListingCopy::query()
            ->where('federation_partner_id', $partner->id)
            ->whereNull('tombstoned_at')
            ->whereDoesntHave('import')
            ->get();

        foreach ($backlog as $copy) {
            if ($this->shouldAutoPublish($policy, $copy)) {
                $this->imports->import($copy, auto: true);
                $published++;
            }
        }

        return $published;
    }

    /**
     * Recompute the copy's vessel-identity conflicts. A changed conflict
     * set invalidates any earlier human review — a new match must reach a
     * person even if an old one was dismissed (ID-9).
     */
    private function reconcileIdentityConflicts(ListingCopy $copy): void
    {
        $conflicts = $this->matcher->conflictsFor($copy);

        if ($conflicts === ($copy->identity_conflicts ?? [])) {
            return;
        }

        $copy->update([
            'identity_conflicts' => $conflicts === [] ? null : $conflicts,
            'conflict_reviewed_at' => null,
        ]);
    }

    /**
     * The acceptance policy, bounded by what stays outside any policy:
     * usage.display false is a ceiling no local setting can raise
     * (ID-10), and an unreviewed vessel-identity conflict always reaches
     * a person before the listing reaches a page (ID-9).
     */
    private function shouldAutoPublish(AcceptancePolicy $policy, ListingCopy $copy): bool
    {
        if ($policy === AcceptancePolicy::Review) {
            return false;
        }

        if (data_get($copy->payload, 'usage.display') === false) {
            return false;
        }

        if ($copy->hasUnreviewedConflict()) {
            return false;
        }

        return $policy === AcceptancePolicy::AcceptAll
            || $this->passesCompletenessCheck($copy);
    }

    /**
     * The completeness check behind accept_complete: field-group gating
     * (LS-14) means a legitimately shared listing can arrive with pricing
     * withheld, and auto-publishing it puts POA-shaped holes on a public
     * site. Incomplete listings queue for a person instead; a partner who
     * shares little sees little published, which is the correct outcome.
     */
    private function passesCompletenessCheck(ListingCopy $copy): bool
    {
        $payload = $copy->payload ?? [];

        $hasPricing = data_get($payload, 'listing.price.amount') !== null
            || ($copy->type === 'charter' && data_get($payload, 'charter.rates', []) !== []);

        return $copy->status === ListingStatus::Active
            && data_get($payload, 'media.profile') !== null
            && $hasPricing
            && data_get($payload, 'vessel.loa_m') !== null;
    }
}
