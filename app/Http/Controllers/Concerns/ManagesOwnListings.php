<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\Audience;
use App\Models\CharterYacht;
use App\Models\FederationPartner;
use App\Models\PartnerGroup;
use App\Models\SaleYacht;
use App\Services\Federation\BuilderRegistry;
use App\Services\Federation\CategoryVocabulary;
use App\Services\Federation\RichTextSanitizer;
use App\Services\Federation\SharingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What the sale and charter own-listing controllers share: media actions
 * (which are identical — the LS-8 rules don't vary by type), the vessel
 * and shared-field mappers, and the map-picker config. The type-specific
 * attribute mapping (price vs the charter block) stays in each
 * controller.
 */
trait ManagesOwnListings
{
    /**
     * The coordinate-picker map is a data-entry aid only; Mapbox is opt-in
     * and silently degrades to OpenStreetMap when no token is configured.
     *
     * @return array{provider: string, mapbox_token: string|null}
     */
    protected function mapConfig(): array
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

    /**
     * The sharing card's props for an edit page: the listing's current
     * audience and selections, and every partner and group it could
     * select. Editing the audience is part of editing the listing —
     * per-partner grants and groups stay federation configuration.
     *
     * @return array<string, mixed>
     */
    protected function audienceProps(SaleYacht|CharterYacht $yacht): array
    {
        return [
            'audience' => $yacht->audience->value,
            'selected_partner_ids' => $yacht->audiencePartners()->pluck('federation_partners.id'),
            'selected_group_ids' => $yacht->audienceGroups()->pluck('partner_groups.id'),
            'partners' => FederationPartner::query()
                ->orderBy('domain')
                ->get()
                ->map(fn (FederationPartner $partner): array => [
                    'id' => $partner->id,
                    'domain' => $partner->domain,
                    'node_name' => $partner->node_name,
                    // Curated partners are excluded from the everyone
                    // audience, so the card offers them under it explicitly.
                    'sharing_scope' => $partner->sharing_scope->value,
                ]),
            'groups' => PartnerGroup::query()
                ->withCount('members')
                ->orderBy('name')
                ->get()
                ->map(fn (PartnerGroup $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'members_count' => $group->members_count,
                ]),
        ];
    }

    /**
     * Change the listing's audience through the sharing service, which
     * records a visibility transition for every partner whose view
     * changed — the feed replays those as tombstones and reappearances.
     */
    protected function updateListingAudience(Request $request, SaleYacht|CharterYacht $yacht, SharingService $sharing): RedirectResponse
    {
        Gate::authorize('update', $yacht);

        $validated = $request->validate([
            'audience' => ['required', Rule::enum(Audience::class)],
            'partner_ids' => ['array'],
            'partner_ids.*' => ['integer', Rule::exists('federation_partners', 'id')],
            'group_ids' => ['array'],
            'group_ids.*' => ['integer', Rule::exists('partner_groups', 'id')],
        ]);

        $result = $sharing->setAudience(
            $yacht,
            Audience::from($validated['audience']),
            $validated['partner_ids'] ?? [],
            $validated['group_ids'] ?? [],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.audience_updated', [
                'hidden' => $result['hidden'],
                'revealed' => $result['revealed'],
            ]),
        ]);

        return back();
    }

    protected function storeListingMedia(Request $request, SaleYacht|CharterYacht $yacht): RedirectResponse
    {
        Gate::authorize('update', $yacht);

        $request->validate([
            'collection' => ['required', 'in:profile,gallery,layouts,documents'],
            // Layouts are GA/deck plans as images; plan PDFs and brochures
            // go to documents (listing-schema.md §Media).
            'file' => $request->input('collection') === 'documents'
                ? ['required', 'file', 'mimes:pdf', 'max:30720']
                : ['required', 'file', 'image', 'max:30720'],
        ]);

        $file = $request->file('file');
        $isImage = $request->input('collection') !== 'documents';
        [$width, $height] = $isImage ? (getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];

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

    protected function updateListingMedia(Request $request, SaleYacht|CharterYacht $yacht, Media $media): RedirectResponse
    {
        Gate::authorize('update', $yacht);

        abort_unless($media->model_id === $yacht->id && $media->model_type === $yacht::class, 404);

        $request->validate(['caption' => ['nullable', 'string', 'max:255']]);

        $media->setCustomProperty('caption', $request->input('caption'));
        $media->save();

        $yacht->touch('federation_updated_at');

        return back();
    }

    protected function destroyListingMedia(SaleYacht|CharterYacht $yacht, Media $media): RedirectResponse
    {
        Gate::authorize('update', $yacht);

        abort_unless($media->model_id === $yacht->id && $media->model_type === $yacht::class, 404);

        $media->delete();
        $yacht->touch('federation_updated_at');

        return back();
    }

    /**
     * A category chosen from the vendored vocabulary carries its canonical
     * name; anything else is a free-text name with a null slug. A category
     * left blank is stored as null — the wire's vocab def anchors on a
     * non-null name (LS-1).
     *
     * @return array<string, mixed>|null
     */
    protected function specificationsAttributes(FormRequest $request): ?array
    {
        $specifications = $request->input('specifications');

        if (! is_array($specifications)) {
            return $specifications;
        }

        $categorySlug = data_get($specifications, 'category.slug');

        if (is_string($categorySlug) && $categorySlug !== '') {
            $specifications['category']['name'] = app(CategoryVocabulary::class)->canonicalName($categorySlug);
        }

        if (! is_string(data_get($specifications, 'category.name'))) {
            $specifications['category'] = null;
        }

        return $specifications;
    }

    /**
     * Stored features mirror the wire shape, all four keys present. The
     * count lives in quantity alone — null means "present, count
     * unstated" (listing-schema.md §Features).
     *
     * @return array<int, array{category: string|null, name: string, slug: string|null, quantity: int|null}>|null
     */
    protected function featureAttributes(FormRequest $request): ?array
    {
        $features = $request->input('features');

        if (! is_array($features)) {
            return $features;
        }

        return collect($features)
            ->filter(fn ($feature): bool => is_array($feature) && is_string($feature['name'] ?? null))
            ->map(fn (array $feature): array => [
                'category' => is_string($feature['category'] ?? null) ? $feature['category'] : null,
                'name' => $feature['name'],
                'slug' => is_string($feature['slug'] ?? null) ? $feature['slug'] : null,
                'quantity' => is_numeric($feature['quantity'] ?? null) ? (int) $feature['quantity'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Authorities SHOULD strip nonconforming markup on ingest
     * (listing-schema.md §Conventions 7); links back to this node's own
     * websites are removed too — descriptions travel to partner websites
     * and must not funnel their visitors back here.
     *
     * @return array<int, array{section: string|null, content: string}>|null
     */
    protected function sanitizedDescriptions(FormRequest $request): ?array
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
    protected function vesselAttributes(FormRequest $request, BuilderRegistry $registry): array
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
     * The shared (non-price, non-charter) listing columns both types map
     * from the same form fields.
     *
     * @return array<string, mixed>
     */
    protected function sharedListingAttributes(FormRequest $request): array
    {
        return [
            'name' => $request->string('name')->value(),
            'summary' => $request->input('summary'),
            'condition' => $request->input('condition'),
            'location_display' => $request->input('location_display'),
            'location_city' => $request->input('location_city'),
            'location_state' => $request->input('location_state'),
            'location_country' => $request->input('location_country'),
            'location_marina' => $request->input('location_marina'),
            'location_lat' => $request->input('location_lat'),
            'location_lon' => $request->input('location_lon'),
            'specifications' => $this->specificationsAttributes($request),
            'descriptions' => $this->sanitizedDescriptions($request),
            'features' => $this->featureAttributes($request),
            'compliance' => $request->input('compliance'),
            'videos' => $this->mediaLinkAttributes($request, 'videos'),
            'tours' => $this->mediaLinkAttributes($request, 'tours'),
        ];
    }

    /**
     * Normalise a videos/tours input to stored {url, caption} entries,
     * dropping blank rows.
     *
     * @return array<int, array{url: string, caption: string|null}>|null
     */
    protected function mediaLinkAttributes(FormRequest $request, string $key): ?array
    {
        $links = $request->input($key);

        if (! is_array($links)) {
            return null;
        }

        return collect($links)
            ->filter(fn ($link): bool => is_array($link) && is_string($link['url'] ?? null) && $link['url'] !== '')
            ->map(fn (array $link): array => [
                'url' => $link['url'],
                'caption' => is_string($link['caption'] ?? null) && $link['caption'] !== '' ? $link['caption'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function mediaPayload(?Media $media): ?array
    {
        if ($media === null) {
            return null;
        }

        return [
            'id' => $media->id,
            'url' => $media->getFullUrl(),
            'caption' => $media->getCustomProperty('caption'),
            'file_name' => $media->file_name,
        ];
    }
}
