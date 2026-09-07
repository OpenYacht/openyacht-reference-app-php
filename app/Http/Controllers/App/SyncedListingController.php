<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Concerns\PresentsRemoteMedia;
use App\Http\Controllers\Controller;
use App\Models\FederationPartner;
use App\Models\ListingCopy;
use App\Services\Federation\CategoryVocabulary;
use App\Services\Federation\RichTextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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
    use FiltersListings, PresentsRemoteMedia;

    /**
     * Card-grid page size: a multiple of the two- and three-column grid
     * widths so a full page never ends on a ragged row.
     */
    private const int PER_PAGE = 24;

    /**
     * The import-state facet, first entry the default: the synced screens
     * exist to show what is on offer that has not been imported yet.
     *
     * @var list<string>
     */
    private const array IMPORT_STATES = ['pending', 'imported', 'all'];

    private function importState(Request $request): string
    {
        $state = trim((string) $request->query('imported'));

        return in_array($state, self::IMPORT_STATES, true) ? $state : self::IMPORT_STATES[0];
    }

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
        $importState = $this->importState($request);

        // A partner whose import type preference excludes this type stays
        // out of the review queue entirely — the copy is still stored
        // (sync substrate), just never surfaced. The same rule scopes the
        // partner filter's options.
        $acceptsType = fn (Builder $partners): Builder => $partners->where(
            fn (Builder $query) => $query
                ->where('import_types', 'both')
                ->orWhere('import_types', $type),
        );

        return Inertia::render('federation/listings/Index', [
            'listingType' => $type,
            'filters' => $filters,
            'importState' => $importState,
            'importStates' => collect(self::IMPORT_STATES)->map(fn (string $state): array => [
                'value' => $state,
                'label' => __('listings.import_states.'.$state),
            ]),
            'categories' => $categories->all(),
            'partners' => $this->partnerOptions(
                $acceptsType(FederationPartner::query())
                    ->whereHas('listingCopies', fn (Builder $copies) => $copies->where('type', $type)),
            ),
            'copies' => ListingCopy::query()
                ->with(['partner:id,domain,node_name,last_ok_at,created_at', 'import:id,listing_copy_id'])
                ->where('type', $type)
                ->whereHas('partner', $acceptsType)
                // The screen is the review queue: what is on offer and not
                // yet taken. Imported copies are hidden by default so they
                // never blur into the offer; the filter brings them back.
                ->when($importState === 'pending', fn ($query) => $query->whereDoesntHave('import'))
                ->when($importState === 'imported', fn ($query) => $query->whereHas('import'))
                ->when($filters['partner'] !== '', fn ($query) => $query
                    ->where('federation_partner_id', (int) $filters['partner']))
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
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->through(fn (ListingCopy $copy): array => [
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
                    'is_hidden' => $copy->partner->isHidden(),
                    'is_tombstoned' => $copy->tombstoned_at !== null,
                    'has_conflict' => $copy->hasUnreviewedConflict(),
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

        $copy->load('partner:id,domain,node_name,last_ok_at,created_at');
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
                'is_hidden' => $copy->partner->isHidden(),
                'is_tombstoned' => $copy->tombstoned_at !== null,
                // ID-9: hard-matched vessels are retained and flagged,
                // never auto-resolved — dismissing the flag is the human
                // review the spec requires.
                'identity_conflicts' => $copy->identity_conflicts ?? [],
                'conflict_reviewed' => $copy->conflict_reviewed_at !== null,
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
                'remote_media' => $this->remoteMediaProps($payload),
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
     * The human review of a flagged vessel-identity conflict (ID-9): the
     * conflict itself is never auto-resolved — dismissing records that a
     * person looked at both records and chose to proceed. A later change
     * to the conflict set clears the dismissal.
     */
    public function dismissConflict(Request $request, ListingCopy $copy): RedirectResponse
    {
        Gate::authorize(Permission::ManageListings->value);

        $copy->update(['conflict_reviewed_at' => now()]);

        activity('federation')
            ->causedBy($request->user())
            ->performedOn($copy)
            ->withProperties(['canonical_uri' => $copy->canonical_uri])
            ->event('conflict_reviewed')
            ->log("Vessel-identity conflict on {$copy->name} reviewed and dismissed");

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.conflict_dismissed'),
        ]);

        return back();
    }
}
