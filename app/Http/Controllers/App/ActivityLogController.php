<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogPruner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * A read-only viewer over the audit trail (spatie/activitylog): the
 * lifecycle events this node records — partners approved/blocked, keys
 * repinned, permissions changed, listings shared, users managed. Paired
 * with the retention setting the daily prune enforces.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request, ActivityLogPruner $pruner): Response
    {
        Gate::authorize(Permission::ManageSettings->value);

        $filters = [
            'log_name' => (string) $request->query('log_name', ''),
            'event' => (string) $request->query('event', ''),
            'causer_id' => (string) $request->query('causer_id', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
        ];

        $activities = Activity::query()
            ->with('causer')
            ->when($filters['log_name'] !== '', fn ($query) => $query->where('log_name', $filters['log_name']))
            ->when($filters['event'] !== '', fn ($query) => $query->where('event', $filters['event']))
            ->when($filters['causer_id'] !== '', fn ($query) => $query->where('causer_id', $filters['causer_id']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Activity $activity): array => [
                'id' => $activity->id,
                'log_name' => $activity->log_name,
                'event' => $activity->event,
                'description' => $activity->description,
                'causer' => $activity->causer instanceof User ? $activity->causer->name : null,
                'subject_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'properties' => $activity->properties->isNotEmpty() ? $activity->properties->toArray() : null,
                'created_at' => $activity->created_at?->diffForHumans(),
                'created_at_iso' => $activity->created_at?->toIso8601String(),
            ]);

        return Inertia::render('activity-log/Index', [
            'activities' => $activities,
            'filters' => $filters,
            'filterOptions' => [
                'logNames' => Activity::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name'),
                'events' => Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
                'causers' => User::query()
                    ->whereIn('id', Activity::query()->whereNotNull('causer_id')->distinct()->pluck('causer_id'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            ],
            'retention' => [
                'days' => $pruner->retentionDays(),
                'total' => Activity::query()->count(),
            ],
        ]);
    }

    public function updateRetention(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $validated = $request->validate([
            // 0 = keep forever; the daily prune no-ops. Cap at ten years.
            'retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        Setting::set(ActivityLogPruner::RETENTION_SETTING, $validated['retention_days']);

        activity('settings')
            ->causedBy($request->user())
            ->withProperties(['activity_log_retention_days' => $validated['retention_days']])
            ->event('activity_log_retention_changed')
            ->log('Activity-log retention updated');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('activity_log.retention_saved'),
        ]);

        return back();
    }

    public function prune(Request $request, ActivityLogPruner $pruner): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        $deleted = $pruner->prune();

        activity('settings')
            ->causedBy($request->user())
            ->withProperties(['deleted' => $deleted, 'retention_days' => $pruner->retentionDays()])
            ->event('activity_log_pruned')
            ->log('Activity log pruned manually');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $deleted > 0
                ? __('activity_log.pruned', ['count' => $deleted])
                : __('activity_log.nothing_pruned'),
        ]);

        return back();
    }
}
