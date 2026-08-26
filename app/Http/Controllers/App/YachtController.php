<?php

namespace App\Http\Controllers\App;

use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Concerns\ManagesOwnListings;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreYachtRequest;
use App\Http\Requests\UpdateYachtRequest;
use App\Models\SaleYacht;
use App\Models\Vessel;
use App\Services\Federation\BuilderRegistry;
use App\Services\Federation\CategoryVocabulary;
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

class YachtController extends Controller
{
    use FiltersListings, ManagesOwnListings;

    public function index(Request $request, CategoryVocabulary $categories): Response
    {
        Gate::authorize('viewAny', SaleYacht::class);

        $filters = $this->listingFilters($request);

        return Inertia::render('yachts/Index', [
            'filters' => $filters,
            'categories' => $categories->all(),
            'yachts' => SaleYacht::query()
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
                ->map(fn (SaleYacht $yacht): array => [
                    'id' => $yacht->id,
                    'uuid' => $yacht->uuid,
                    'name' => $yacht->name,
                    'status' => $yacht->status->value,
                    'status_label' => $yacht->status->label(),
                    'thumbnail_url' => $yacht->getFirstMediaUrl('profile', 'thumbnail') ?: null,
                    'builder_name' => $yacht->vessel->builder_name,
                    'year_built' => $yacht->vessel->year_built,
                    'loa_m' => $yacht->vessel->loa_m,
                    'price_amount' => $yacht->price_amount,
                    'price_currency' => $yacht->price_currency,
                    'updated_at' => $yacht->federation_updated_at?->diffForHumans(),
                ]),
        ]);
    }

    public function create(BuilderRegistry $registry, CategoryVocabulary $categories): Response
    {
        Gate::authorize('create', SaleYacht::class);

        return Inertia::render('yachts/Create', [
            'builders' => $registry->all(),
            'categories' => $categories->all(),
            'map' => $this->mapConfig(),
        ]);
    }

    public function store(StoreYachtRequest $request, BuilderRegistry $registry): RedirectResponse
    {
        $yacht = DB::transaction(function () use ($request, $registry): SaleYacht {
            $vessel = Vessel::create($this->vesselAttributes($request, $registry));

            return SaleYacht::create([
                ...$this->yachtAttributes($request),
                'vessel_id' => $vessel->id,
                'assigned_broker_id' => $request->user()->id,
            ]);
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('yachts.created', ['name' => $yacht->name]),
        ]);

        return to_route('yachts.edit', $yacht);
    }

    public function edit(SaleYacht $yacht, BuilderRegistry $registry, CategoryVocabulary $categories): Response
    {
        Gate::authorize('update', $yacht);

        $yacht->load(['vessel', 'media']);

        return Inertia::render('yachts/Edit', [
            'map' => $this->mapConfig(),
            'sharing' => $this->audienceProps($yacht),
            'yacht' => [
                'id' => $yacht->id,
                'uuid' => $yacht->uuid,
                'canonical_uri' => $yacht->canonicalUri(),
                'status' => $yacht->status->value,
                'status_label' => $yacht->status->label(),
                'allowed_transitions' => collect(ListingStatus::cases())
                    ->filter(fn (ListingStatus $status) => $yacht->canTransitionTo($status))
                    ->map(fn (ListingStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ])
                    ->values(),
                'name' => $yacht->name,
                'summary' => $yacht->summary,
                'condition' => $yacht->condition,
                'price_amount' => $yacht->price_amount,
                'price_currency' => $yacht->price_currency,
                'price_on_application' => $yacht->price_on_application,
                'starting_price' => $yacht->starting_price,
                'location_display' => $yacht->location_display,
                'location_city' => $yacht->location_city,
                'location_state' => $yacht->location_state,
                'location_country' => $yacht->location_country,
                'location_marina' => $yacht->location_marina,
                'location_lat' => $yacht->location_lat,
                'location_lon' => $yacht->location_lon,
                'builder_slug' => $yacht->vessel->builder_slug,
                'builder_name' => $yacht->vessel->builder_name,
                'model_name' => $yacht->vessel->model_name,
                'model_slug' => $yacht->vessel->model_slug,
                'year_built' => $yacht->vessel->year_built,
                'refit_year' => $yacht->vessel->refit_year,
                'loa_m' => $yacht->vessel->loa_m,
                'hin' => $yacht->vessel->hin,
                'imo' => $yacht->vessel->imo,
                'mmsi' => $yacht->vessel->mmsi,
                'official_number' => $yacht->vessel->official_number,
                'previous_names' => implode(', ', $yacht->vessel->previous_names ?? []),
                'specifications' => $yacht->specifications ?? [],
                'descriptions' => $yacht->descriptions ?? [],
                'features' => $yacht->features ?? [],
                'compliance' => $yacht->compliance ?? [],
                'videos' => $yacht->videos ?? [],
                'tours' => $yacht->tours ?? [],
                'profile' => $this->mediaPayload($yacht->getFirstMedia('profile')),
                'gallery' => $yacht->getMedia('gallery')
                    ->map(fn (Media $media) => $this->mediaPayload($media))
                    ->values(),
                'layouts' => $yacht->getMedia('layouts')
                    ->map(fn (Media $media) => $this->mediaPayload($media))
                    ->values(),
                'documents' => $yacht->getMedia('documents')
                    ->map(fn (Media $media) => $this->mediaPayload($media))
                    ->values(),
            ],
            'builders' => $registry->all(),
            'categories' => $categories->all(),
        ]);
    }

    public function update(UpdateYachtRequest $request, SaleYacht $yacht, BuilderRegistry $registry): RedirectResponse
    {
        DB::transaction(function () use ($request, $yacht, $registry): void {
            $yacht->vessel->update($this->vesselAttributes($request, $registry));
            $yacht->update($this->yachtAttributes($request));
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('yachts.updated', ['name' => $yacht->name]),
        ]);

        return back();
    }

    public function transition(Request $request, SaleYacht $yacht): RedirectResponse
    {
        Gate::authorize('update', $yacht);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ListingStatus::class)],
        ]);

        try {
            $yacht->transitionTo(ListingStatus::from($validated['status']));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('yachts.status_changed', ['name' => $yacht->name, 'status' => $yacht->status->label()]),
        ]);

        return back();
    }

    public function storeMedia(Request $request, SaleYacht $yacht): RedirectResponse
    {
        return $this->storeListingMedia($request, $yacht);
    }

    public function updateMedia(Request $request, SaleYacht $yacht, Media $media): RedirectResponse
    {
        return $this->updateListingMedia($request, $yacht, $media);
    }

    public function destroyMedia(SaleYacht $yacht, Media $media): RedirectResponse
    {
        return $this->destroyListingMedia($yacht, $media);
    }

    public function updateAudience(Request $request, SaleYacht $yacht, SharingService $sharing): RedirectResponse
    {
        return $this->updateListingAudience($request, $yacht, $sharing);
    }

    /**
     * @return array<string, mixed>
     */
    private function yachtAttributes(StoreYachtRequest $request): array
    {
        return [
            ...$this->sharedListingAttributes($request),
            'price_amount' => $request->input('price_amount'),
            'price_currency' => $request->input('price_currency'),
            'price_on_application' => $request->boolean('price_on_application'),
            'starting_price' => $request->boolean('starting_price'),
        ];
    }
}
