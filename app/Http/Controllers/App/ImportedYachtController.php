<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Controller;
use App\Models\ImportedMedia;
use App\Models\ImportedYacht;
use App\Models\ListingCopy;
use App\Services\Federation\CategoryVocabulary;
use App\Services\Federation\ImportService;
use App\Services\Federation\RichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Sale and charter imports are never mixed in one list — one index per
 * wire type, mirroring the own-listing screens.
 */
class ImportedYachtController extends Controller
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
        Gate::authorize('viewAny', ImportedYacht::class);

        $filters = $this->listingFilters($request);

        return Inertia::render('imported-yachts/Index', [
            'listingType' => $type,
            'filters' => $filters,
            'categories' => $categories->all(),
            'yachts' => ImportedYacht::query()
                ->with(['media', 'copy.partner:id,domain,last_ok_at'])
                ->where('type', $type)
                ->when($filters['q'] !== '', fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('name', 'like', "%{$filters['q']}%")
                        ->orWhere('builder_name', 'like', "%{$filters['q']}%")
                        ->orWhere('model_name', 'like', "%{$filters['q']}%"),
                ))
                ->when($filters['location'] !== '', fn ($query) => $query
                    ->where('location_display', 'like', "%{$filters['location']}%"))
                // Category lives in the copy's verbatim payload, not on the
                // curated row.
                ->when($filters['category'] !== '', fn ($query) => $query
                    ->whereHas('copy', fn ($copy) => $copy
                        ->where('payload->specifications->category->slug', $filters['category'])))
                ->when($filters['loa_min'] !== null, fn ($query) => $query->where('loa_m', '>=', $filters['loa_min']))
                ->when($filters['loa_max'] !== null, fn ($query) => $query->where('loa_m', '<=', $filters['loa_max']))
                ->orderBy('name')
                ->get()
                ->map(function (ImportedYacht $yacht): array {
                    $profile = $yacht->profileMedia();

                    return [
                        'id' => $yacht->id,
                        'name' => $yacht->name,
                        'type' => $yacht->type,
                        'status' => $yacht->status->value,
                        'status_label' => $yacht->status->label(),
                        'builder_name' => $yacht->builder_name,
                        'model_name' => $yacht->model_name,
                        'year_built' => $yacht->year_built,
                        'loa_m' => $yacht->loa_m,
                        'price_amount' => $yacht->price_amount,
                        'price_currency' => $yacht->price_currency,
                        // Charter copies have no asking price; the card
                        // falls back to the payload's rate range.
                        'charter_rates' => data_get($yacht->copy->payload, 'charter.rates', []),
                        'location_display' => $yacht->location_display,
                        'attribution_text' => $yacht->attribution_text,
                        'authority_domain' => $yacht->copy->authority_domain,
                        'is_stale' => $yacht->copy->partner->isStale(),
                        'media_synced_at' => $yacht->media_synced_at?->diffForHumans(),
                        'media_count' => $yacht->media->count(),
                        'hero' => $profile instanceof ImportedMedia
                            ? $profile->urlsFor('crop_')
                            : [],
                    ];
                }),
        ]);
    }

    public function show(ImportedYacht $importedYacht, RichTextSanitizer $sanitizer): Response
    {
        Gate::authorize('view', $importedYacht);

        $importedYacht->load(['media', 'copy.partner:id,domain,last_ok_at']);
        $payload = $importedYacht->copy->payload ?? [];
        $profile = $importedYacht->profileMedia();

        $descriptionSections = data_get($payload, 'descriptions');
        $descriptionSections = is_array($descriptionSections) ? $descriptionSections : [];

        return Inertia::render('imported-yachts/Show', [
            'yacht' => [
                'id' => $importedYacht->id,
                'name' => $importedYacht->name,
                'type' => $importedYacht->type,
                'status' => $importedYacht->status->value,
                'status_label' => $importedYacht->status->label(),
                'builder_name' => $importedYacht->builder_name,
                'model_name' => $importedYacht->model_name,
                'year_built' => $importedYacht->year_built,
                'loa_m' => $importedYacht->loa_m,
                'price_amount' => $importedYacht->price_amount,
                'price_currency' => $importedYacht->price_currency,
                'location_display' => $importedYacht->location_display,
                'summary' => $importedYacht->summary,
                'attribution_text' => $importedYacht->attribution_text,
                'is_stale' => $importedYacht->copy->partner->isStale(),
                'hero' => $profile instanceof ImportedMedia ? $profile->urlsFor('crop_') : [],
                'gallery' => $importedYacht->media
                    ->sortBy('sort')
                    ->values()
                    ->map(fn (ImportedMedia $media): array => [
                        'id' => $media->id,
                        'kind' => $media->kind,
                        'caption' => $media->caption,
                        'srcset' => $media->urlsFor(),
                    ]),
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
                'charter' => data_get($payload, 'charter'),
                'brokers' => data_get($payload, 'listing.brokers', []),
                'price_history' => data_get($payload, 'listing.price_history', []),
                'compliance' => data_get($payload, 'compliance'),
                'provenance' => $importedYacht->copy->provenance(),
                'payload' => $payload,
            ],
        ]);
    }

    public function store(Request $request, ListingCopy $copy, ImportService $imports): RedirectResponse
    {
        Gate::authorize(Permission::ManageListings->value);

        try {
            $imports->import($copy, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['copy' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('listings.imported', ['name' => $copy->name ?? $copy->canonical_uri]),
        ]);

        return back();
    }

    public function destroy(ImportedYacht $importedYacht, ImportService $imports): RedirectResponse
    {
        Gate::authorize(Permission::ManageListings->value);

        $name = $importedYacht->name;
        $imports->remove($importedYacht);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('listings.import_removed', ['name' => $name]),
        ]);

        return back();
    }
}
