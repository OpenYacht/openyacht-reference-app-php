<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\CharterYacht;
use App\Models\ExchangeRate;
use App\Models\ImportedMedia;
use App\Models\ImportedYacht;
use App\Models\SaleYacht;
use App\Services\Federation\ListingSerializer;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
 *
 * Copies from a partner unreachable past the hide threshold are withheld
 * from every response here — a vanished authority cannot tombstone its
 * own listings, so this surface would otherwise serve them as current
 * forever (FP-15; config openyacht.staleness).
 */
class YachtsController extends Controller
{
    /**
     * List every displayable yacht as full listing documents.
     *
     * Responses carry complete documents (the protocol wire schema plus
     * this node's x_ extension fields), so a consumer building pages
     * never needs per-yacht detail calls — follow `links.next` until it
     * is null to sweep the whole feed.
     */
    #[QueryParameter('source', description: 'Restrict the feed: `own` for this node\'s inventory, `imported` for partner listings. Omit for both.', type: 'string')]
    #[QueryParameter('price_min', description: 'Lower price bound, expressed in `price_currency`.', type: 'number')]
    #[QueryParameter('price_max', description: 'Upper price bound, expressed in `price_currency`.', type: 'number')]
    #[QueryParameter('price_currency', description: 'ISO 4217 currency the price bounds and price sort are expressed in. Comparison uses the daily ECB reference rates; a currency without a fetched rate is refused with 422 — never silently unconverted results.', type: 'string', default: 'USD')]
    #[QueryParameter('sort', description: '`updated` (newest first) or `price` (converted into `price_currency` at the current rates; unpriced listings last).', type: 'string', default: 'updated')]
    #[QueryParameter('sort_direction', description: '`asc` or `desc` (applies to `sort=price`).', type: 'string', default: 'asc')]
    #[QueryParameter('per_page', description: 'Page size, capped at 200.', type: 'int', default: 25)]
    #[QueryParameter('page', description: 'Page number.', type: 'int', default: 1)]
    public function index(Request $request, ListingSerializer $serializer): JsonResponse
    {
        $source = in_array($request->query('source'), ['own', 'imported'], true)
            ? $request->query('source')
            : 'all';

        foreach (['price_min', 'price_max'] as $bound) {
            if ($request->filled($bound) && ! is_numeric($request->query($bound))) {
                return response()->json(['error' => "{$bound} must be a number."], 422);
            }
        }

        $priceMin = $request->filled('price_min') ? (float) $request->query('price_min') : null;
        $priceMax = $request->filled('price_max') ? (float) $request->query('price_max') : null;
        $priceCurrency = strtoupper((string) $request->query('price_currency', 'USD'));
        $sort = in_array($request->query('sort'), ['updated', 'price'], true)
            ? $request->query('sort')
            : 'updated';

        $filteringByPrice = $priceMin !== null || $priceMax !== null;

        // Price comparison across currencies needs the target currency's
        // ECB rate. Refusals are explicit — never silently unconverted
        // results (there is no fallback rate source by design).
        if ($filteringByPrice || $sort === 'price') {
            if (preg_match('/^[A-Z]{3}$/', $priceCurrency) !== 1) {
                return response()->json(['error' => 'price_currency must be a three-letter ISO 4217 code.'], 422);
            }

            if (ExchangeRate::rateFor($priceCurrency) === null) {
                return response()->json([
                    'error' => "No exchange rate is available for {$priceCurrency}. Rates refresh daily from the ECB reference feed.",
                ], 422);
            }
        }

        $items = collect();

        if ($source !== 'imported') {
            $items = $items->merge(
                SaleYacht::query()
                    ->with(['vessel', 'assignedBroker', 'priceHistory', 'media'])
                    ->whereIn('status', [ListingStatus::Active, ListingStatus::UnderOffer])
                    ->when($filteringByPrice, fn ($query) => $query->priceBetween($priceMin, $priceMax, $priceCurrency))
                    ->get()
                    ->map(fn (SaleYacht $yacht): array => $this->ownItem($yacht, $serializer)),
            );

            // Charter listings carry no asking price by design (their
            // pricing is the rate block), so a priced filter excludes
            // them entirely rather than pretending rates are prices.
            if (! $filteringByPrice) {
                $items = $items->merge(
                    CharterYacht::query()
                        ->with(['vessel', 'assignedBroker', 'media'])
                        ->whereIn('status', [ListingStatus::Active, ListingStatus::UnderOffer])
                        ->get()
                        ->map(fn (CharterYacht $yacht): array => $this->ownItem($yacht, $serializer)),
                );
            }
        }

        if ($source !== 'own') {
            $items = $items->merge(
                ImportedYacht::query()
                    ->with(['media', 'copy.partner:id,domain,last_ok_at,created_at'])
                    ->fromDisplayablePartner()
                    ->when($filteringByPrice, fn ($query) => $query->priceBetween($priceMin, $priceMax, $priceCurrency))
                    ->get()
                    ->map(fn (ImportedYacht $yacht): array => $this->importedItem($yacht)),
            );
        }

        // In-memory merge pagination and sorting: correct and simple at
        // reference-app scale. A high-volume node would paginate per
        // source or maintain a combined index table.
        $sorted = $sort === 'price'
            ? $this->sortByConvertedPrice($items, $priceCurrency, $request->query('sort_direction') === 'desc')
            : $items->sortByDesc(fn (array $item): string => $item['updated_at'] ?? '')->values();

        $perPage = min(max((int) $request->query('per_page', 25), 1), 200);
        $page = max((int) $request->query('page', 1), 1);
        $lastPage = max((int) ceil($sorted->count() / $perPage), 1);

        return response()->json([
            'data' => $sorted->forPage($page, $perPage)->values(),
            'links' => [
                // Followable next link so a static-site build sweep can
                // walk the whole feed without page arithmetic.
                'next' => $page < $lastPage ? $request->fullUrlWithQuery(['page' => $page + 1]) : null,
            ],
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $sorted->count(),
            ],
        ]);
    }

    /**
     * Order items by their price converted into the target currency at
     * the current ECB rates. Unpriced or unconvertible items sort last,
     * newest first among themselves.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function sortByConvertedPrice($items, string $currency, bool $descending): mixed
    {
        $converted = function (array $item) use ($currency): ?float {
            $amount = data_get($item, 'listing.price.amount');
            $itemCurrency = data_get($item, 'listing.price.currency');

            if (! is_string($amount) || ! is_string($itemCurrency)) {
                return null;
            }

            return ExchangeRate::convert((float) $amount, $itemCurrency, $currency);
        };

        [$priced, $unpriced] = $items->partition(fn (array $item): bool => $converted($item) !== null);

        $priced = $priced->sortBy($converted, SORT_REGULAR, $descending);

        return $priced
            ->concat($unpriced->sortByDesc(fn (array $item): string => $item['updated_at'] ?? ''))
            ->values();
    }

    /**
     * Dereference one yacht by its x_key: the uuid of an own listing, or
     * imported-{id} for an imported one.
     */
    public function show(string $key, ListingSerializer $serializer): JsonResponse
    {
        if (preg_match('/^imported-(\d+)$/', $key, $matches) === 1) {
            $imported = ImportedYacht::query()
                ->with(['media', 'copy.partner:id,domain,last_ok_at,created_at'])
                ->fromDisplayablePartner()
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
