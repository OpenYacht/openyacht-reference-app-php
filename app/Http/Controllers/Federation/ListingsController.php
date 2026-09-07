<?php

namespace App\Http\Controllers\Federation;

use App\Enums\FederationErrorCode;
use App\Enums\ListingStatus;
use App\Enums\SharingScope;
use App\Http\Controllers\Controller;
use App\Http\Responses\FederationErrorResponse;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use App\Services\Federation\ListingCursor;
use App\Services\Federation\ListingSerializer;
use App\Services\Federation\SharingService;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serves this node's own listings to verified partners. Copies of other
 * nodes' listings live in their own table and are never served from here
 * (ID-4); drafts are never distributed (LS-7); updated_since results
 * include tombstones for every listing that became invisible (API-3) —
 * whether it ended or was unshared, indistinguishably.
 *
 * Sale and charter listings live in separate tables (the table is the
 * type) but share one wire feed: the same constraints run against both
 * tables and the two result sets merge on (effective_updated_at, uuid) —
 * the cursor's keyset. The effective timestamp is
 * GREATEST(federation_updated_at, latest visibility event) per partner:
 * sharing transitions move a listing past any partner's watermark
 * without re-serving it to everyone else.
 *
 * // api-design.md §Listings
 * // wordpress-plugin-notes.md §Granular sharing
 */
class ListingsController extends Controller
{
    private const EPOCH = '1000-01-01 00:00:00';

    public function index(Request $request, ListingSerializer $serializer): JsonResponse
    {
        /** @var FederationPartner $partner */
        $partner = $request->attributes->get('openyacht_partner');

        $pageSize = min(max((int) $request->query('page_size', 50), 1), (int) config('openyacht.limits.page_size_max'));

        $updatedSince = null;

        if ($request->filled('updated_since')) {
            try {
                $updatedSince = Carbon::parse((string) $request->query('updated_since'));
            } catch (InvalidFormatException) {
                return FederationErrorResponse::make(
                    FederationErrorCode::ValidationError,
                    'updated_since must be an RFC 3339 timestamp.',
                );
            }
        }

        $cursor = ListingCursor::decode($request->query('cursor'));

        /** @var Collection<int, SaleYacht|CharterYacht> $combined */
        $combined = $this
            ->feedPage(SaleYacht::query()->with(['vessel', 'assignedBroker', 'priceHistory', 'media']), 'sale_yachts', $partner, $updatedSince, $cursor, $pageSize)
            ->concat($this->feedPage(CharterYacht::query()->with(['vessel', 'assignedBroker', 'media']), 'charter_yachts', $partner, $updatedSince, $cursor, $pageSize));

        $merged = $combined
            ->sortBy(fn (SaleYacht|CharterYacht $yacht): string => $yacht->effective_updated_at.'|'.$yacht->uuid)
            ->values();

        $hasMore = $merged->count() > $pageSize;
        $page = $merged->take($pageSize);

        $meta = [
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'protocol_version' => '1.0',
        ];

        if ($hasMore) {
            $last = $page->last();
            $meta['next_cursor'] = ListingCursor::encode([
                'updated_at' => Carbon::parse($last->effective_updated_at, 'UTC')->toIso8601String(),
                'uuid' => $last->uuid,
            ]);
        }

        return response()->json([
            // Unshared rows tombstone at the transition, ended rows with
            // their real status, the rest as the gated payload — the same
            // decision a push delivery makes (ListingSerializer::feedItem).
            'data' => $page
                ->map(fn (SaleYacht|CharterYacht $yacht): array => $serializer->feedItem(
                    $yacht,
                    $partner,
                    (bool) $yacht->visible_now,
                    Carbon::parse($yacht->effective_updated_at, 'UTC'),
                ))
                ->values(),
            'meta' => $meta,
        ]);
    }

    /**
     * The dereference target for canonical URIs. Terminal listings stay
     * served (with status) for the retention window, then 410 Gone.
     */
    public function show(Request $request, string $uuid, ListingSerializer $serializer, SharingService $sharing): JsonResponse
    {
        /** @var FederationPartner $partner */
        $partner = $request->attributes->get('openyacht_partner');

        // UUIDs are unique across both tables (uuid7, minted at creation),
        // so a canonical URI dereferences to exactly one listing type.
        $yacht = SaleYacht::query()
            ->with(['vessel', 'assignedBroker', 'priceHistory', 'media'])
            ->where('uuid', $uuid)
            ->first()
            ?? CharterYacht::query()
                ->with(['vessel', 'assignedBroker', 'media'])
                ->where('uuid', $uuid)
                ->first();

        // Drafts are never distributed — and never revealed (LS-7). A
        // listing not shared with this partner is equally NOT_FOUND: the
        // same response as for a listing that does not exist (no leak).
        if ($yacht === null
            || $yacht->status === ListingStatus::Draft
            || ! $sharing->isVisibleTo($yacht, $partner)) {
            return FederationErrorResponse::make(FederationErrorCode::NotFound, 'No such listing.');
        }

        $retentionEnds = $yacht->federation_updated_at
            ?->addMonths((int) config('openyacht.terminal_retention_months'));

        if ($yacht->status->isTerminal() && $retentionEnds !== null && $retentionEnds->isPast()) {
            return FederationErrorResponse::make(FederationErrorCode::Gone, 'This listing has ended and its retention window has passed.');
        }

        return response()->json($serializer->serialize($yacht, $partner));
    }

    /**
     * One typed table's page of the feed: the same constraints run
     * against sale_yachts and charter_yachts, and the two result sets
     * merge on (effective_updated_at, uuid). The table name is a
     * caller-supplied literal so the composed fragments stay
     * injection-checkable literal strings.
     *
     * @param  Builder<SaleYacht>|Builder<CharterYacht>  $query
     * @param  literal-string  $table
     * @param  array{updated_at: Carbon, uuid: string}|null  $cursor
     * @return Collection<int, SaleYacht|CharterYacht>
     */
    private function feedPage(Builder $query, string $table, FederationPartner $partner, ?Carbon $updatedSince, ?array $cursor, int $pageSize): Collection
    {
        $visible = $this->visibleSql($table, $partner);
        $effective = $this->effectiveSql($table);

        $query
            ->select("{$table}.*")
            ->selectRaw("{$visible} as visible_now", [$partner->id, $partner->id])
            ->selectRaw("{$effective} as effective_updated_at")
            ->selectRaw('ev.event as last_event')
            ->leftJoinSub($this->latestEventPerListing($partner), 'ev', 'ev.listing_uuid', '=', "{$table}.uuid")
            ->where("{$table}.status", '!=', ListingStatus::Draft)
            ->orderByRaw("{$effective}")
            ->orderBy("{$table}.uuid");

        if ($updatedSince !== null) {
            // Everything whose federation-visible state changed at or
            // after the timestamp for THIS partner — content changes,
            // grant refreshes, and listings that became invisible, which
            // appear as tombstones (API-3). The invisible branch keys on
            // the hidden event specifically: refreshed rows serialise as
            // normal listings.
            $since = $updatedSince->utc()->format('Y-m-d H:i:s');
            $query->whereRaw(
                "(({$visible} AND {$effective} >= ?) OR (NOT {$visible} AND ev.event = 'hidden' AND ev.occurred_at >= ?))",
                [$partner->id, $partner->id, $since, $partner->id, $partner->id, $since],
            );
        } else {
            // Cold sync: the currently visible inventory only; terminal
            // listings remain dereferenceable at their canonical URIs.
            $query
                ->whereRaw($visible, [$partner->id, $partner->id])
                ->whereIn("{$table}.status", [ListingStatus::Active, ListingStatus::UnderOffer]);
        }

        if ($cursor !== null) {
            $position = $cursor['updated_at']->utc()->format('Y-m-d H:i:s');
            $query->whereRaw(
                "({$effective} > ? OR ({$effective} = ? AND {$table}.uuid > ?))",
                [$position, $position, $cursor['uuid']],
            );
        }

        /** @var Collection<int, SaleYacht|CharterYacht> $rows */
        $rows = $query->limit($pageSize + 1)->get();

        return $rows;
    }

    /**
     * The requesting partner's latest visibility event per listing, as a
     * joinable derived table (listing_uuid, event, occurred_at).
     */
    private function latestEventPerListing(FederationPartner $partner): \Illuminate\Database\Query\Builder
    {
        $latestIds = DB::table('visibility_events')
            ->selectRaw('listing_uuid, MAX(id) as max_id')
            ->where('federation_partner_id', $partner->id)
            ->groupBy('listing_uuid');

        return DB::table('visibility_events as e')
            ->joinSub($latestIds, 'latest', 'latest.max_id', '=', 'e.id')
            ->select(['e.listing_uuid', 'e.event', 'e.occurred_at']);
    }

    /**
     * The SQL mirror of SharingService::isVisibleTo() — visible ⇔
     * audience is not "none" AND (an explicit pivot matches OR (audience
     * is "everyone" AND the partner's sharing scope is standard)). Pivots
     * are additive: an explicit selection (individually or via a group)
     * grants under any non-none audience, which is how a curated partner
     * is reached at all. The scope is a property of the requesting
     * partner, resolved before the query is built, so it branches here
     * rather than binding; both branches carry exactly two positional
     * bindings (partner id, twice) so every call site binds identically.
     * The two implementations must stay mirrored.
     *
     * @param  literal-string  $table
     * @return literal-string
     */
    private function visibleSql(string $table, FederationPartner $partner): string
    {
        $pivotMatch = "(EXISTS (SELECT 1 FROM listing_audience_partners a WHERE a.listing_uuid = {$table}.uuid AND a.federation_partner_id = ?)"
            .' OR EXISTS (SELECT 1 FROM listing_audience_groups ag INNER JOIN partner_group_members gm ON gm.partner_group_id = ag.partner_group_id'
            ." WHERE ag.listing_uuid = {$table}.uuid AND gm.federation_partner_id = ?))";

        // Curated partners never receive the everyone grant — only pivots.
        if ($partner->sharing_scope === SharingScope::Curated) {
            return "({$table}.audience != 'none' AND {$pivotMatch})";
        }

        return "({$table}.audience = 'everyone' OR ({$table}.audience != 'none' AND {$pivotMatch}))";
    }

    /**
     * GREATEST(federation_updated_at, latest event) as a portable CASE —
     * GREATEST() does not exist on SQLite. Timestamps compare as
     * 'Y-m-d H:i:s' strings, which orders correctly on every engine.
     *
     * @param  literal-string  $table
     * @return literal-string
     */
    private function effectiveSql(string $table): string
    {
        return "(CASE WHEN COALESCE(ev.occurred_at, '".self::EPOCH."') > {$table}.federation_updated_at"
            ." THEN ev.occurred_at ELSE {$table}.federation_updated_at END)";
    }
}
