<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Controller;
use App\Models\ListingCopy;
use App\Services\Federation\CategoryVocabulary;
use App\Services\Federation\RichTextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Copies of partners' listings, with provenance and attribution.
 * Stale partners flag every copy in the UI (FP-15); attribution follows
 * the listing's usage block (ID-10). Sale and charter copies are never
 * mixed in one list — one index per wire type, mirroring the own-listing
 * screens.
 */
class SyncedListingController extends Controller
{
    use FiltersListings;

    public function index(Request $request, CategoryVocabulary $categories): Response
    {
        return $this->typedIndex($request, $categories, 'sale');
    }

    public function charterIndex(Request $request, CategoryVocabulary $categories): Response
    {
        return $this->typedIndex($request, $categories, 'charter');
    }

    private function typedIndex(Request $request, CategoryVocabulary $categories, string $type): Response
    {
        Gate::authorize('viewAny', ListingCopy::class);

        $filters = $this->listingFilters($request);

        return Inertia::render('federation/listings/Index', [
            'listingType' => $type,
            'filters' => $filters,
            'categories' => $categories->all(),
            'copies' => ListingCopy::query()
                ->with(['partner:id,domain,node_name,last_ok_at', 'import:id,listing_copy_id'])
                ->where('type', $type)
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
                    'price_amount' => data_get($copy->payload, 'listing.price.amount'),
                    'price_currency' => data_get($copy->payload, 'listing.price.currency'),
                    // Charter copies carry price: null by design; the card
                    // falls back to the payload's rate range.
                    'charter_rates' => data_get($copy->payload, 'charter.rates', []),
                    // The partner's display name, never the canonical URI:
                    // that URI is the partner's signed API and dereferences
                    // to nothing in a browser.
                    'node_name' => $copy->partner->node_name ?? $copy->authority_domain,
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
     * A synced copy rendered from its verbatim payload — remote media
     * only (https-validated, FP-14), descriptions sanitised (LS-5), and
     * the provenance block displayed rather than linked: the canonical
     * URI is the partner's signed API, not a browsable page.
     */
    public function show(ListingCopy $copy, RichTextSanitizer $sanitizer): Response
    {
        Gate::authorize('view', $copy);

        $copy->load('partner:id,domain,node_name,last_ok_at');
        $payload = $copy->payload ?? [];

        $descriptionSections = data_get($payload, 'descriptions');
        $descriptionSections = is_array($descriptionSections) ? $descriptionSections : [];

        $gallery = data_get($payload, 'media.gallery');

        return Inertia::render('federation/listings/Show', [
            'copy' => [
                'id' => $copy->id,
                'name' => $copy->name,
                'type' => $copy->type,
                'status' => $copy->status->value,
                'status_label' => $copy->status->label(),
                'imported' => $copy->import()->exists(),
                'importable' => $copy->tombstoned_at === null
                    && data_get($payload, 'usage.display') !== false,
                'is_stale' => $copy->partner->isStale(),
                'is_tombstoned' => $copy->tombstoned_at !== null,
                'node_name' => $copy->partner->node_name ?? $copy->authority_domain,
                'builder_name' => data_get($payload, 'vessel.builder.name'),
                'model_name' => data_get($payload, 'vessel.model.name'),
                'year_built' => data_get($payload, 'vessel.year_built'),
                'loa_m' => data_get($payload, 'vessel.loa_m'),
                'price_amount' => data_get($payload, 'listing.price.amount'),
                'price_currency' => data_get($payload, 'listing.price.currency'),
                'location_display' => data_get($payload, 'listing.location.display'),
                'summary' => data_get($payload, 'listing.summary'),
                'hero_url' => $this->httpsUrlOrNull(data_get($payload, 'media.profile.url')),
                // The grid prefers the authority-served thumbnail_url when
                // present (listing-schema.md §Media, LS-16) — this screen is
                // the pre-import preview the field exists for; full
                // resolution stays one click away via url.
                'gallery' => collect(is_array($gallery) ? $gallery : [])
                    ->map(fn ($item): ?array => is_array($item) && $this->httpsUrlOrNull($item['url'] ?? null) !== null
                        ? [
                            'url' => $item['url'],
                            'thumbnail_url' => $this->httpsUrlOrNull($item['thumbnail_url'] ?? null),
                            'caption' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
                        ]
                        : null)
                    ->filter()
                    ->values(),
                'vessel' => data_get($payload, 'vessel'),
                'specifications' => data_get($payload, 'specifications'),
                'descriptions' => collect($descriptionSections)
                    ->filter(fn ($section): bool => is_array($section) && is_string($section['content'] ?? null))
                    ->map(fn (array $section): array => [
                        'section' => is_string($section['section'] ?? null) ? $section['section'] : null,
                        // LS-5: sanitise before rendering, regardless of
                        // what the authority sent.
                        'content' => $sanitizer->sanitize($section['content']),
                    ])
                    ->values(),
                'features' => data_get($payload, 'features', []),
                'brokers' => data_get($payload, 'listing.brokers', []),
                'price_history' => data_get($payload, 'listing.price_history', []),
                'compliance' => data_get($payload, 'compliance'),
                'attribution' => data_get($payload, 'usage.attribution_required') === true
                    ? data_get($payload, 'usage.attribution_text')
                    : null,
                'provenance' => $copy->provenance(),
                'payload' => $payload,
            ],
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
