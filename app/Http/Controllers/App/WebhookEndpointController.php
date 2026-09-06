<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\ChangeNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin management of the outbound change-notification webhooks (see
 * ChangeNotifier). The secret is write-only: the page only ever learns
 * whether one is set.
 */
class WebhookEndpointController extends Controller
{
    private const RECENT_DELIVERIES = 10;

    /**
     * Scheduled-ping intervals offered in the admin, in minutes. Any whole
     * number of minutes between one hour and one week is accepted; these
     * are the presets the select shows.
     *
     * @var list<int>
     */
    private const SCHEDULE_PRESETS = [60, 360, 720, 1440, 10080];

    private const SCHEDULE_RULES = ['nullable', 'integer', 'min:60', 'max:10080'];

    public function index(): Response
    {
        Gate::authorize(Permission::ManageSettings->value);

        return Inertia::render('webhooks/Index', [
            'endpoints' => WebhookEndpoint::query()
                ->with(['deliveries' => fn ($query) => $query->latest('id')->limit(self::RECENT_DELIVERIES)])
                ->latest()
                ->get()
                ->map(fn (WebhookEndpoint $endpoint): array => [
                    'id' => $endpoint->id,
                    'name' => $endpoint->name,
                    'url' => $endpoint->url,
                    'has_secret' => $endpoint->secret !== null,
                    'is_active' => $endpoint->is_active,
                    'schedule_interval_minutes' => $endpoint->schedule_interval_minutes,
                    'last_notified_at' => $endpoint->last_notified_at?->diffForHumans(),
                    'last_succeeded_at' => $endpoint->last_succeeded_at?->diffForHumans(),
                    'last_failed_at' => $endpoint->last_failed_at?->diffForHumans(),
                    'consecutive_failures' => $endpoint->consecutive_failures,
                    'created_at' => $endpoint->created_at->toDateString(),
                    'deliveries' => $endpoint->deliveries
                        ->map(fn (WebhookDelivery $delivery): array => [
                            'id' => $delivery->id,
                            'reason' => $delivery->reason,
                            'attempt' => $delivery->attempt,
                            'succeeded' => $delivery->succeeded,
                            'http_status' => $delivery->http_status,
                            'error' => $delivery->error,
                            'duration_ms' => $delivery->duration_ms,
                            'created_at' => $delivery->created_at->toDateTimeString(),
                        ])
                        ->values()
                        ->all(),
                ]),
            'cooldownMinutes' => (int) config('openyacht.change_notifications.cooldown_minutes'),
            'schedulePresets' => self::SCHEDULE_PRESETS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'secret' => ['nullable', 'string', 'max:255'],
            'schedule_interval_minutes' => self::SCHEDULE_RULES,
        ]);

        $endpoint = WebhookEndpoint::create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => ($validated['secret'] ?? '') === '' ? null : $validated['secret'],
            'schedule_interval_minutes' => $validated['schedule_interval_minutes'] ?? null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('webhooks.created', ['name' => $endpoint->name]),
        ]);

        return to_route('webhooks.index');
    }

    /**
     * Partial update: the list's enable/disable toggle sends is_active
     * alone; the edit dialog sends name, url, the schedule and optionally
     * a new secret (blank keeps the current one, clear_secret removes it).
     */
    public function update(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'required', 'string', 'max:2000', 'url:http,https'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_secret' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'schedule_interval_minutes' => ['sometimes', ...self::SCHEDULE_RULES],
        ]);

        $attributes = array_intersect_key($validated, array_flip(['name', 'url', 'is_active', 'schedule_interval_minutes']));

        if (($validated['secret'] ?? '') !== '') {
            $attributes['secret'] = $validated['secret'];
        } elseif ($validated['clear_secret'] ?? false) {
            $attributes['secret'] = null;
        }

        $webhook->update($attributes);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(match (true) {
                array_keys($validated) === ['is_active'] && $webhook->is_active => 'webhooks.enabled',
                array_keys($validated) === ['is_active'] => 'webhooks.disabled',
                default => 'webhooks.updated',
            }, ['name' => $webhook->name]),
        ]);

        return back();
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $webhook->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('webhooks.deleted', ['name' => $webhook->name]),
        ]);

        return back();
    }

    /**
     * Queue a test ping to this endpoint alone, bypassing the cooldown
     * and the active flag, so a freshly added URL can be proven before
     * relying on it.
     */
    public function test(WebhookEndpoint $webhook, ChangeNotifier $notifier): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $notifier->test($webhook);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('webhooks.test_queued', ['name' => $webhook->name]),
        ]);

        return back();
    }
}
