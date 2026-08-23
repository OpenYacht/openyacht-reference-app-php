<?php

namespace App\Http\Controllers\App;

use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Concerns\FiltersListings;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreYachtRequest;
use App\Http\Requests\UpdateYachtRequest;
use App\Models\SaleYacht;
use App\Models\Vessel;
use App\Services\Federation\BuilderRegistry;
use App\Services\Federation\CategoryVocabulary;
use App\Services\Federation\RichTextSanitizer;
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
    use FiltersListings;

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

    /**
     * The coordinate-picker map is a data-entry aid only; Mapbox is opt-in
     * and silently degrades to OpenStreetMap when no token is configured.
     *
     * @return array{provider: string, mapbox_token: string|null}
     */
    private function mapConfig(): array
    {
        $provider = config('openyacht.map.provider', 'openstreetmap');
        $token = config('openyacht.map.mapbox_token');
        $token = is_string($token) && $token !== '' ? $token : null;

        if ($provider === 'mapbox' && $token === null) {
            $provider = 'openstreetmap';
        }

        return [
            'provider' => $provider === 'mapbox' ? 'mapbox' : 'openstreetmap',
            'mapbox_token' => $provider === 'mapbox' ? $token : null,
        ];
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
                'profile' => $this->mediaPayload($yacht->getFirstMedia('profile')),
                'gallery' => $yacht->getMedia('gallery')
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
        Gate::authorize('update', $yacht);

        $request->validate([
            'collection' => ['required', 'in:profile,gallery'],
            'file' => ['required', 'file', 'image', 'max:30720'],
        ]);

        $file = $request->file('file');
        [$width, $height] = getimagesize($file->getRealPath()) ?: [null, null];

        $yacht->addMedia($file)
            ->withCustomProperties([
                // The wire's content hash (media_hashes capability).
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'width' => $width,
                'height' => $height,
                'caption' => $request->string('caption')->value() ?: null,
            ])
            ->toMediaCollection($request->string('collection')->value());

        // Media changes are federation-visible.
        $yacht->touch('federation_updated_at');

        return back();
    }

    public function updateMedia(Request $request, SaleYacht $yacht, Media $media): RedirectResponse
    {
        Gate::authorize('update', $yacht);

        abort_unless($media->model_id === $yacht->id && $media->model_type === SaleYacht::class, 404);

        $request->validate(['caption' => ['nullable', 'string', 'max:255']]);

        $media->setCustomProperty('caption', $request->input('caption'));
        $media->save();

        $yacht->touch('federation_updated_at');

        return back();
    }

    public function destroyMedia(SaleYacht $yacht, Media $media): RedirectResponse
    {
        Gate::authorize('update', $yacht);

        abort_unless($media->model_id === $yacht->id && $media->model_type === SaleYacht::class, 404);

        $media->delete();
        $yacht->touch('federation_updated_at');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function yachtAttributes(StoreYachtRequest $request): array
    {
        return [
            'name' => $request->string('name')->value(),
            'summary' => $request->input('summary'),
            'condition' => $request->input('condition'),
            'price_amount' => $request->input('price_amount'),
            'price_currency' => $request->input('price_currency'),
            'price_on_application' => $request->boolean('price_on_application'),
            'starting_price' => $request->boolean('starting_price'),
            'location_display' => $request->input('location_display'),
            'location_city' => $request->input('location_city'),
            'location_state' => $request->input('location_state'),
            'location_country' => $request->input('location_country'),
            'location_marina' => $request->input('location_marina'),
            'location_lat' => $request->input('location_lat'),
            'location_lon' => $request->input('location_lon'),
            'specifications' => $this->specificationsAttributes($request),
            'descriptions' => $this->sanitizedDescriptions($request),
            'features' => $request->input('features'),
            'compliance' => $request->input('compliance'),
        ];
    }

    /**
     * A category chosen from the vendored vocabulary carries its canonical
     * name; anything else is a free-text name with a null slug.
     *
     * @return array<string, mixed>|null
     */
    private function specificationsAttributes(StoreYachtRequest $request): ?array
    {
        $specifications = $request->input('specifications');

        if (! is_array($specifications)) {
            return $specifications;
        }

        $categorySlug = data_get($specifications, 'category.slug');

        if (is_string($categorySlug) && $categorySlug !== '') {
            $specifications['category']['name'] = app(CategoryVocabulary::class)->canonicalName($categorySlug);
        }

        return $specifications;
    }

    /**
     * Authorities SHOULD strip nonconforming markup on ingest
     * (listing-schema.md §Conventions 7); links back to this node's own
     * websites are removed too — descriptions travel to partner websites
     * and must not funnel their visitors back here.
     *
     * @return array<int, array{section: string|null, content: string}>|null
     */
    private function sanitizedDescriptions(StoreYachtRequest $request): ?array
    {
        $descriptions = $request->input('descriptions');

        if (! is_array($descriptions)) {
            return $descriptions;
        }

        $sanitizer = app(RichTextSanitizer::class);

        /** @var list<string> $ownHosts */
        $ownHosts = collect([
            config('openyacht.domain'),
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('openyacht.website'), PHP_URL_HOST),
        ])->filter(fn ($host): bool => is_string($host) && $host !== '')->values()->all();

        return collect($descriptions)
            ->filter(fn ($section): bool => is_array($section) && is_string($section['content'] ?? null))
            ->map(fn (array $section): array => [
                'section' => is_string($section['section'] ?? null) && $section['section'] !== '' ? $section['section'] : null,
                'content' => $sanitizer->sanitize($section['content'], $ownHosts),
            ])
            ->filter(fn (array $section): bool => $section['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function vesselAttributes(StoreYachtRequest $request, BuilderRegistry $registry): array
    {
        $slug = $request->input('builder_slug') ?: null;

        $previousNames = collect(explode(',', (string) $request->input('previous_names')))
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->values()
            ->all();

        return [
            'builder_slug' => $slug,
            // With a registry slug, the canonical registry name is the
            // display truth; without one, the free-text name stands.
            'builder_name' => $slug !== null
                ? $registry->canonicalName($slug)
                : $request->input('builder_name'),
            'model_name' => $request->input('model_name'),
            'model_slug' => $request->input('model_slug'),
            'year_built' => $request->input('year_built'),
            'refit_year' => $request->input('refit_year'),
            'loa_m' => $request->input('loa_m'),
            'hin' => $request->input('hin'),
            'imo' => $request->input('imo'),
            'mmsi' => $request->input('mmsi'),
            'official_number' => $request->input('official_number'),
            'previous_names' => $previousNames,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mediaPayload(?Media $media): ?array
    {
        if ($media === null) {
            return null;
        }

        return [
            'id' => $media->id,
            'url' => $media->getFullUrl(),
            'caption' => $media->getCustomProperty('caption'),
        ];
    }
}
