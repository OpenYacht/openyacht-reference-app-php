<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\CharterYacht;
use App\Models\ImportedMedia;
use App\Models\ImportedYacht;
use App\Models\SaleYacht;
use App\Services\Federation\ListingSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The unified yacht feed for API consumers: every displayable yacht —
 * this node's own listings and its imported partner listings — in one
 * standard shape: the protocol's wire schema. Non-wire extras a website
 * needs (source, provenance, local media renditions) ride along as
 * x_-prefixed private extension fields, the spec's own extension
 * mechanism (listing-schema.md §Conventions 3).
 *
 * ?source=own gives just this node's inventory (a "featured yachts"
 * view); ?source=imported gives just the partner listings.
 */
class YachtsController extends Controller
{
    public function index(Request $request, ListingSerializer $serializer): JsonResponse
    {
        $source = in_array($request->query('source'), ['own', 'imported'], true)
            ? $request->query('source')
            : 'all';

        $items = collect();

        if ($source !== 'imported') {
            $items = $items->merge(
                SaleYacht::query()
                    ->with(['vessel', 'assignedBroker', 'priceHistory', 'media'])
                    ->whereIn('status', [ListingStatus::Active, ListingStatus::UnderOffer])
                    ->get()
                    ->map(fn (SaleYacht $yacht): array => $this->ownItem($yacht, $serializer)),
            )->merge(
                CharterYacht::query()
                    ->with(['vessel', 'assignedBroker', 'media'])
                    ->whereIn('status', [ListingStatus::Active, ListingStatus::UnderOffer])
                    ->get()
                    ->map(fn (CharterYacht $yacht): array => $this->ownItem($yacht, $serializer)),
            );
        }

        if ($source !== 'own') {
            $items = $items->merge(
                ImportedYacht::query()
                    ->with(['media', 'copy.partner:id,domain,last_ok_at'])
                    ->get()
                    ->map(fn (ImportedYacht $yacht): array => $this->importedItem($yacht)),
            );
        }

        // In-memory merge pagination: correct and simple at reference-app
        // scale. A high-volume node would paginate per source or maintain
        // a combined index table.
        $sorted = $items->sortByDesc(fn (array $item): string => $item['updated_at'] ?? '')->values();

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        return response()->json([
            'data' => $sorted->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($sorted->count() / $perPage), 1),
                'per_page' => $perPage,
                'total' => $sorted->count(),
            ],
        ]);
    }

    /**
     * Dereference one yacht by its x_key: the uuid of an own listing, or
     * imported-{id} for an imported one.
     */
    public function show(string $key, ListingSerializer $serializer): JsonResponse
    {
        if (preg_match('/^imported-(\d+)$/', $key, $matches) === 1) {
            $imported = ImportedYacht::query()
                ->with(['media', 'copy.partner:id,domain,last_ok_at'])
                ->find((int) $matches[1]);

            return $imported === null
                ? response()->json(['error' => 'Not found.'], 404)
                : response()->json(['data' => $this->importedItem($imported)]);
        }

        // UUIDs are unique across both own-listing tables (uuid7, minted
        // at creation), so the key dereferences to exactly one type.
        $yacht = SaleYacht::query()
            ->with(['vessel', 'assignedBroker', 'priceHistory', 'media'])
            ->where('uuid', $key)
            ->where('status', '!=', ListingStatus::Draft)
            ->first()
            ?? CharterYacht::query()
                ->with(['vessel', 'assignedBroker', 'media'])
                ->where('uuid', $key)
                ->where('status', '!=', ListingStatus::Draft)
                ->first();

        return $yacht === null
            ? response()->json(['error' => 'Not found.'], 404)
            : response()->json(['data' => $this->ownItem($yacht, $serializer)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownItem(SaleYacht|CharterYacht $yacht, ListingSerializer $serializer): array
    {
        return [
            ...$serializer->serialize($yacht, null),
            'x_key' => $yacht->uuid,
            'x_source' => 'own',
            'x_provenance' => null,
            'x_attribution_text' => null,
            'x_local_media' => null,
        ];
    }

    /**
     * The stored wire payload, exactly as the authority distributed it,
     * plus this node's extension fields.
     *
     * @return array<string, mixed>
     */
    private function importedItem(ImportedYacht $yacht): array
    {
        return [
            ...($yacht->copy->payload ?? []),
            'x_key' => "imported-{$yacht->id}",
            'x_source' => 'imported',
            'x_provenance' => [
                ...$yacht->copy->provenance(),
                'is_stale' => $yacht->copy->partner->isStale(),
            ],
            'x_attribution_text' => $yacht->attribution_text,
            'x_local_media' => $yacht->media
                ->sortBy('sort')
                ->values()
                ->map(fn (ImportedMedia $media): array => [
                    'kind' => $media->kind,
                    'caption' => $media->caption,
                    'sort' => $media->sort,
                    'srcset' => $media->urlsFor(),
                    'hero_srcset' => $media->kind === 'profile' ? $media->urlsFor('crop_') : null,
                ]),
        ];
    }
}
