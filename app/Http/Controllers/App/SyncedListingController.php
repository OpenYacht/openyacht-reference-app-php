<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Controller;
use App\Models\ListingCopy;
use App\Services\Federation\CategoryVocabulary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Copies of partners' listings, with provenance and attribution.
 * Stale partners flag every copy in the UI (FP-15); attribution follows
 * the listing's usage block (ID-10).
 */
class SyncedListingController extends Controller
{
    use FiltersListings;

    public function index(Request $request, CategoryVocabulary $categories): Response
    {
        $filters = $this->listingFilters($request);

        return Inertia::render('federation/listings/Index', [
            'filters' => $filters,
            'categories' => $categories->all(),
            'copies' => ListingCopy::query()
                ->with(['partner:id,domain,last_ok_at', 'import:id,listing_copy_id'])
                ->when($filters['q'] !== '', fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('name', 'like', "%{$filters['q']}%")
                        ->orWhere('authority_domain', 'like', "%{$filters['q']}%"),
                ))
                // Copies hold only the verbatim payload; facets read its JSON.
                ->when($filters['location'] !== '', fn ($query) => $query
                    ->where('payload->listing->location->display', 'like', "%{$filters['location']}%"))
                ->when($filters['category'] !== '', fn ($query) => $query
                    ->where('payload->specifications->category->slug', $filters['category']))
                ->when($filters['loa_min'] !== null, function ($query) use ($filters): void {
                    $this->whereJsonNumeric($query, 'payload->vessel->loa_m', '>=', $filters['loa_min']);
                })
                ->when($filters['loa_max'] !== null, function ($query) use ($filters): void {
                    $this->whereJsonNumeric($query, 'payload->vessel->loa_m', '<=', $filters['loa_max']);
                })
                ->orderByDesc('listing_updated_at')
                ->get()
                ->map(fn (ListingCopy $copy): array => [
                    'id' => $copy->id,
                    'imported' => $copy->import !== null,
                    'importable' => $copy->tombstoned_at === null
                        && data_get($copy->payload, 'usage.display') !== false,
                    'thumbnail_url' => $this->httpsUrlOrNull(
                        data_get($copy->payload, 'media.profile.thumbnail_url'),
                    ),
                    'name' => $copy->name,
                    'type' => $copy->type,
                    'status' => $copy->status->value,
                    'status_label' => $copy->status->label(),
                    'authority_domain' => $copy->authority_domain,
                    'canonical_uri' => $copy->canonical_uri,
                    'listing_updated_at' => $copy->listing_updated_at?->diffForHumans(),
                    'received_at' => $copy->received_at->diffForHumans(),
                    'signature_verified' => $copy->signature_verified,
                    'is_stale' => $copy->partner->isStale(),
                    'is_tombstoned' => $copy->tombstoned_at !== null,
                    'attribution' => data_get($copy->payload, 'usage.attribution_required') === true
                        ? data_get($copy->payload, 'usage.attribution_text')
                        : null,
                ]),
        ]);
    }

    /**
     * Inbound media URLs are untrusted (FP-14): only https URLs are ever
     * rendered.
     */
    private function httpsUrlOrNull(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
