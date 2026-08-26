<?php

namespace App\Http\Controllers\App;

use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Concerns\ManagesOwnListings;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCharterYachtRequest;
use App\Http\Requests\UpdateCharterYachtRequest;
use App\Models\CharterYacht;
use App\Models\Vessel;
use App\Services\Federation\BuilderRegistry;
use App\Services\Federation\CategoryVocabulary;
use App\Services\Federation\DestinationRegistry;
use App\Services\Federation\SharingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The charter fleet's own screens. Sale and charter listings are never
 * mixed in one list — separate routes and pages, not filter tabs — and a
 * listing's type is chosen by which page creates it, then fixed for life:
 * the two types live in separate tables, so there is nothing to mutate.
 */
class CharterYachtController extends Controller
{
    use FiltersListings, ManagesOwnListings;

    public function index(Request $request, CategoryVocabulary $categories): Response
    {
        Gate::authorize('viewAny', CharterYacht::class);

        $filters = $this->listingFilters($request);

        return Inertia::render('charter-yachts/Index', [
            'filters' => $filters,
            'categories' => $categories->all(),
            'yachts' => CharterYacht::query()
                ->with(['vessel:id,builder_name,model_name,year_built,loa_m', 'media'])
                // Broker scope is enforced in the query, not the UI.
                ->when(
                    ! $request->user()->can(Permission::ManageListings->value),
                    fn ($query) => $query->where('assigned_broker_id', $request->user()->id),
                )
                ->when($filters['q'] !== '', fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('name', 'like', "%{$filters['q']}%")
                        ->orWhereHas('vessel', fn ($vessel) => $vessel
                            ->where('builder_name', 'like', "%{$filters['q']}%")
                            ->orWhere('model_name', 'like', "%{$filters['q']}%")),
                ))
                ->when($filters['location'] !== '', fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('location_display', 'like', "%{$filters['location']}%")
                        ->orWhere('location_city', 'like', "%{$filters['location']}%")
                        ->orWhere('location_country', 'like', "%{$filters['location']}%")
                        ->orWhere('location_marina', 'like', "%{$filters['location']}%"),
                ))
                ->when($filters['category'] !== '', fn ($query) => $query
                    ->where('specifications->category->slug', $filters['category']))
                ->when($filters['loa_min'] !== null, fn ($query) => $query
                    ->whereHas('vessel', fn ($vessel) => $vessel->where('loa_m', '>=', $filters['loa_min'])))
                ->when($filters['loa_max'] !== null, fn ($query) => $query
                    ->whereHas('vessel', fn ($vessel) => $vessel->where('loa_m', '<=', $filters['loa_max'])))
                ->orderByDesc('federation_updated_at')
                ->get()
                ->map(fn (CharterYacht $yacht): array => [
                    'id' => $yacht->id,
                    'uuid' => $yacht->uuid,
                    'name' => $yacht->name,
                    'status' => $yacht->status->value,
                    'status_label' => $yacht->status->label(),
                    'thumbnail_url' => $yacht->getFirstMediaUrl('profile', 'thumbnail') ?: null,
                    'builder_name' => $yacht->vessel->builder_name,
                    'year_built' => $yacht->vessel->year_built,
                    'loa_m' => $yacht->vessel->loa_m,
                    // Charter listings have no asking price; the card
                    // shows the rate range instead.
                    'rates' => $yacht->rates ?? [],
                    'updated_at' => $yacht->federation_updated_at?->diffForHumans(),
                ]),
        ]);
    }

    public function create(BuilderRegistry $registry, CategoryVocabulary $categories, DestinationRegistry $destinations): Response
    {
        Gate::authorize('create', CharterYacht::class);

        return Inertia::render('charter-yachts/Create', [
            'builders' => $registry->all(),
            'categories' => $categories->all(),
            'destinations' => $destinations->all(),
            'map' => $this->mapConfig(),
        ]);
    }

    public function store(StoreCharterYachtRequest $request, BuilderRegistry $registry, DestinationRegistry $destinations): RedirectResponse
    {
        $yacht = DB::transaction(function () use ($request, $registry, $destinations): CharterYacht {
            $vessel = Vessel::create($this->vesselAttributes($request, $registry));

            return CharterYacht::create([
                ...$this->charterAttributes($request, $destinations),
                'crew_attested_at' => $request->boolean('crew_attested') ? now() : null,
                'vessel_id' => $vessel->id,
                'assigned_broker_id' => $request->user()->id,
            ]);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('yachts.created', ['name' => $yacht->name]),
        ]);

        return to_route('charter-yachts.edit', $yacht);
    }

    public function edit(CharterYacht $charterYacht, BuilderRegistry $registry, CategoryVocabulary $categories, DestinationRegistry $destinations): Response
    {
        Gate::authorize('update', $charterYacht);

        $charterYacht->load(['vessel', 'media']);

        return Inertia::render('charter-yachts/Edit', [
            'map' => $this->mapConfig(),
            'sharing' => $this->audienceProps($charterYacht),
            'yacht' => [
                'id' => $charterYacht->id,
                'uuid' => $charterYacht->uuid,
                'canonical_uri' => $charterYacht->canonicalUri(),
                'status' => $charterYacht->status->value,
                'status_label' => $charterYacht->status->label(),
                'allowed_transitions' => collect(ListingStatus::cases())
                    ->filter(fn (ListingStatus $status) => $charterYacht->canTransitionTo($status))
                    ->map(fn (ListingStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ])
                    ->values(),
                'name' => $charterYacht->name,
                'summary' => $charterYacht->summary,
                'condition' => $charterYacht->condition,
                'location_display' => $charterYacht->location_display,
                'location_city' => $charterYacht->location_city,
                'location_state' => $charterYacht->location_state,
                'location_country' => $charterYacht->location_country,
                'location_marina' => $charterYacht->location_marina,
                'location_lat' => $charterYacht->location_lat,
                'location_lon' => $charterYacht->location_lon,
                'builder_slug' => $charterYacht->vessel->builder_slug,
                'builder_name' => $charterYacht->vessel->builder_name,
                'model_name' => $charterYacht->vessel->model_name,
                'model_slug' => $charterYacht->vessel->model_slug,
                'year_built' => $charterYacht->vessel->year_built,
                'refit_year' => $charterYacht->vessel->refit_year,
                'loa_m' => $charterYacht->vessel->loa_m,
                'hin' => $charterYacht->vessel->hin,
                'imo' => $charterYacht->vessel->imo,
                'mmsi' => $charterYacht->vessel->mmsi,
                'official_number' => $charterYacht->vessel->official_number,
                'previous_names' => implode(', ', $charterYacht->vessel->previous_names ?? []),
                'specifications' => $charterYacht->specifications ?? [],
                'descriptions' => $charterYacht->descriptions ?? [],
                'features' => $charterYacht->features ?? [],
                'compliance' => $charterYacht->compliance ?? [],
                'rates' => $charterYacht->rates ?? [],
                'operating_areas' => $charterYacht->operating_areas ?? [],
                'summer_base_port' => $charterYacht->summer_base_port,
                'winter_base_port' => $charterYacht->winter_base_port,
                'crew' => $charterYacht->crew ?? [],
                'crew_attested' => $charterYacht->crew_attested_at !== null,
                'profile' => $this->mediaPayload($charterYacht->getFirstMedia('profile')),
                'gallery' => $charterYacht->getMedia('gallery')
                    ->map(fn (Media $media) => $this->mediaPayload($media))
                    ->values(),
            ],
            'builders' => $registry->all(),
            'categories' => $categories->all(),
            'destinations' => $destinations->all(),
        ]);
    }

    public function update(UpdateCharterYachtRequest $request, CharterYacht $charterYacht, BuilderRegistry $registry, DestinationRegistry $destinations): RedirectResponse
    {
        DB::transaction(function () use ($request, $charterYacht, $registry, $destinations): void {
            $charterYacht->vessel->update($this->vesselAttributes($request, $registry));
            $charterYacht->update([
                ...$this->charterAttributes($request, $destinations),
                // A standing attestation keeps its original timestamp;
                // unticking revokes it (crew leaves the wire, LS-15).
                'crew_attested_at' => $request->boolean('crew_attested')
                    ? ($charterYacht->crew_attested_at ?? now())
                    : null,
            ]);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('yachts.updated', ['name' => $charterYacht->name]),
        ]);

        return back();
    }

    public function transition(Request $request, CharterYacht $charterYacht): RedirectResponse
    {
        Gate::authorize('update', $charterYacht);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ListingStatus::class)],
        ]);

        try {
            $charterYacht->transitionTo(ListingStatus::from($validated['status']));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('yachts.status_changed', ['name' => $charterYacht->name, 'status' => $charterYacht->status->label()]),
        ]);

        return back();
    }

    public function storeMedia(Request $request, CharterYacht $charterYacht): RedirectResponse
    {
        return $this->storeListingMedia($request, $charterYacht);
    }

    public function updateMedia(Request $request, CharterYacht $charterYacht, Media $media): RedirectResponse
    {
        return $this->updateListingMedia($request, $charterYacht, $media);
    }

    public function destroyMedia(CharterYacht $charterYacht, Media $media): RedirectResponse
    {
        return $this->destroyListingMedia($charterYacht, $media);
    }

    public function updateAudience(Request $request, CharterYacht $charterYacht, SharingService $sharing): RedirectResponse
    {
        return $this->updateListingAudience($request, $charterYacht, $sharing);
    }

    /**
     * @return array<string, mixed>
     */
    private function charterAttributes(StoreCharterYachtRequest $request, DestinationRegistry $destinations): array
    {
        return [
            ...$this->sharedListingAttributes($request),
            'rates' => $request->input('rates'),
            'operating_areas' => $this->operatingAreasAttributes($request, $destinations),
            'summer_base_port' => $request->input('summer_base_port'),
            'winter_base_port' => $request->input('winter_base_port'),
            'crew' => $this->crewAttributes($request),
        ];
    }

    /**
     * An area chosen from the vendored registry carries its canonical
     * name; anything else is a free-text name with a null slug —
     * authorities never invent destination slugs.
     *
     * @return array<int, array{name: string, slug: string|null, season: string|null}>|null
     */
    private function operatingAreasAttributes(StoreCharterYachtRequest $request, DestinationRegistry $destinations): ?array
    {
        $areas = $request->input('operating_areas');

        if (! is_array($areas)) {
            return $areas;
        }

        return collect($areas)
            ->map(function (array $area) use ($destinations): array {
                $slug = is_string($area['slug'] ?? null) && $area['slug'] !== '' ? $area['slug'] : null;

                return [
                    'name' => $slug !== null ? $destinations->canonicalName($slug) : $area['name'],
                    'slug' => $slug,
                    'season' => is_string($area['season'] ?? null) && $area['season'] !== '' ? $area['season'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A TBA position is unannounced: role and tba stand, the personal
     * fields are nulled whatever the form sent (listing-schema.md §Charter).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function crewAttributes(StoreCharterYachtRequest $request): ?array
    {
        $crew = $request->input('crew');

        if (! is_array($crew)) {
            return $crew;
        }

        return collect($crew)
            ->map(fn (array $member): array => [
                'role' => $member['role'],
                'name' => ($member['tba'] ?? false) ? null : ($member['name'] ?? null),
                'nationality' => ($member['tba'] ?? false) ? null : ($member['nationality'] ?? null),
                'bio' => ($member['tba'] ?? false) ? null : ($member['bio'] ?? null),
                'photo_url' => ($member['tba'] ?? false) ? null : ($member['photo_url'] ?? null),
                'tba' => (bool) ($member['tba'] ?? false),
            ])
            ->values()
            ->all();
    }
}
