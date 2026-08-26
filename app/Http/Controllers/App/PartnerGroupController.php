<?php

namespace App\Http\Controllers\App;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\PartnerGroup;
use App\Services\Federation\SharingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Partner groups — the audience shorthand managed on the partners
 * screen. Membership changes replay through the visibility-event log
 * (SharingService), so a partner added to a group immediately receives
 * every listing selecting it and a removed partner gets tombstones,
 * without touching any listing.
 */
class PartnerGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('partner_groups', 'name')],
        ]);

        PartnerGroup::create($validated);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.group_created', ['name' => $validated['name']]),
        ]);

        return back();
    }

    public function update(Request $request, PartnerGroup $partnerGroup, SharingService $sharing): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('partner_groups', 'name')->ignore($partnerGroup->id)],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', Rule::exists('federation_partners', 'id')],
        ]);

        $partnerGroup->update(['name' => $validated['name']]);
        $result = $sharing->replaceGroupMembers($partnerGroup, $validated['member_ids'] ?? []);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.group_updated', [
                'name' => $partnerGroup->name,
                'hidden' => $result['hidden'],
                'revealed' => $result['revealed'],
            ]),
        ]);

        return back();
    }

    public function destroy(PartnerGroup $partnerGroup, SharingService $sharing): RedirectResponse
    {
        Gate::authorize(Permission::ManageFederation->value);

        $sharing->deleteGroup($partnerGroup);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('federation.group_deleted', ['name' => $partnerGroup->name]),
        ]);

        return back();
    }
}
