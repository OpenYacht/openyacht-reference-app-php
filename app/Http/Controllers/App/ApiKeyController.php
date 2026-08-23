<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(): Response
    {
        Gate::authorize(Permission::ManageSettings->value);

        return Inertia::render('api-keys/Index', [
            'keys' => ApiKey::query()
                ->latest()
                ->get()
                ->map(fn (ApiKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'key_prefix' => $key->key_prefix,
                    'scopes' => $key->scopes,
                    'domains' => $key->domains,
                    'rate_limit' => $key->rate_limit,
                    'is_active' => $key->is_active,
                    'last_used_at' => $key->last_used_at?->diffForHumans(),
                    'created_at' => $key->created_at->toDateString(),
                ]),
            'availableScopes' => collect(ApiKey::AVAILABLE_SCOPES)
                ->map(fn (string $description, string $scope): array => [
                    'value' => $scope,
                    'description' => $description,
                ])
                ->values(),
            // The plaintext of a freshly created key — present exactly once,
            // straight after creation.
            'newKey' => session('new_api_key'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(array_keys(ApiKey::AVAILABLE_SCOPES))],
            'domains' => ['nullable', 'string', 'max:1000'],
            'rate_limit' => ['required', 'integer', 'between:1,10000'],
        ]);

        $domains = collect(explode(',', (string) ($validated['domains'] ?? '')))
            ->map(fn (string $domain): string => trim($domain))
            ->filter()
            ->values()
            ->all();

        $generated = ApiKey::generate(
            name: $validated['name'],
            scopes: $validated['scopes'],
            domains: $domains === [] ? null : $domains,
            rateLimit: $validated['rate_limit'],
            createdBy: $request->user(),
        );

        return to_route('api-keys.index')
            ->with('new_api_key', $generated['plaintext']);
    }

    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $apiKey->update($validated);

        return back();
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $apiKey->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('api_keys.deleted', ['name' => $apiKey->name]),
        ]);

        return back();
    }
}
