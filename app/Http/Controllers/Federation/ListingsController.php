<?php

namespace App\Http\Controllers\Federation;

use App\Enums\FederationErrorCode;
use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\FederationErrorResponse;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\SaleYacht;
use App\Services\Federation\ListingCursor;
use App\Services\Federation\ListingSerializer;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Serves this node's own listings to verified partners. Copies of other
 * nodes' listings live in their own table and are never served from here
 * (ID-4); drafts are never distributed (LS-7); updated_since results
 * include tombstones for every listing that became invisible (API-3).
 *
 * Sale and charter listings live in separate tables (the table is the
 * type) but share one wire feed: the same constraints run against both
 * tables and the two result sets merge on (federation_updated_at, uuid) —
 * the cursor's keyset — so a delta poll walks one consistent sequence
 * across both types. Two lean indexed queries per page, no joins.
 *
 * // api-design.md §Listings
 */
class ListingsController extends Controller
{
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

        $fetch = function (Builder $query) use ($updatedSince, $cursor, $pageSize): Collection {
            $query
                ->where('status', '!=', ListingStatus::Draft)
                ->orderBy('federation_updated_at')
                ->orderBy('uuid');

            if ($updatedSince !== null) {
                // Everything whose federation-visible state changed at or
                // after the timestamp — including listings that became
                // invisible, which appear below as tombstones (API-3).
                $query->where('federation_updated_at', '>=', $updatedSince);
            } else {
                // Cold sync: the currently visible inventory only; terminal
                // listings remain dereferenceable at their canonical URIs.
                $query->whereIn('status', [ListingStatus::Active, ListingStatus::UnderOffer]);
            }

            if ($cursor !== null) {
                $query->where(function ($outer) use ($cursor): void {
                    $outer->where('federation_updated_at', '>', $cursor['updated_at'])
                        ->orWhere(function ($inner) use ($cursor): void {
                            $inner->where('federation_updated_at', $cursor['updated_at'])
                                ->where('uuid', '>', $cursor['uuid']);
                        });
                });
            }

            return $query->limit($pageSize + 1)->get();
        };

        $merged = $fetch(SaleYacht::query()->with(['vessel', 'assignedBroker', 'priceHistory', 'media']))
            ->concat($fetch(CharterYacht::query()->with(['vessel', 'assignedBroker', 'media'])))
            ->sortBy(fn (SaleYacht|CharterYacht $yacht): string => $yacht->federation_updated_at->utc()->format('Y-m-d H:i:s').'|'.$yacht->uuid)
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
                'updated_at' => $last->federation_updated_at->utc()->toIso8601String(),
                'uuid' => $last->uuid,
            ]);
        }

        return response()->json([
            'data' => $page
                ->map(fn (SaleYacht|CharterYacht $yacht): array => $yacht->status->isTerminal()
                    ? $serializer->tombstone($yacht)
                    : $serializer->serialize($yacht, $partner))
                ->values(),
            'meta' => $meta,
        ]);
    }

    /**
     * The dereference target for canonical URIs. Terminal listings stay
     * served (with status) for the retention window, then 410 Gone.
     */
    public function show(Request $request, string $uuid, ListingSerializer $serializer): JsonResponse
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

        // Drafts are never distributed — and never revealed (LS-7).
        if ($yacht === null || $yacht->status === ListingStatus::Draft) {
            return FederationErrorResponse::make(FederationErrorCode::NotFound, 'No such listing.');
        }

        $retentionEnds = $yacht->federation_updated_at
            ?->addMonths((int) config('openyacht.terminal_retention_months'));

        if ($yacht->status->isTerminal() && $retentionEnds !== null && $retentionEnds->isPast()) {
            return FederationErrorResponse::make(FederationErrorCode::Gone, 'This listing has ended and its retention window has passed.');
        }

        return response()->json($serializer->serialize($yacht, $partner));
    }
}
