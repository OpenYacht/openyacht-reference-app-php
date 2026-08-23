<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\SaleYacht;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'role' => $request->user()?->getRoleNames()->first(),
                'canManageUsers' => $request->user()?->can('viewAny', User::class) ?? false,
                'canManageFederation' => $request->user()?->can(Permission::ManageFederation->value) ?? false,
                'canManageListings' => $request->user()?->can(Permission::ManageListings->value) ?? false,
                'canManageOwnYachts' => $request->user()?->can('viewAny', SaleYacht::class) ?? false,
                'canManageSettings' => $request->user()?->can(Permission::ManageSettings->value) ?? false,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
