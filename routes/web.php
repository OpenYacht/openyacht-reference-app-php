<?php

use App\Enums\Role;
use App\Http\Controllers\App\ActivityLogController;
use App\Http\Controllers\App\ApiKeyController;
use App\Http\Controllers\App\CharterYachtController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\ImportedYachtController;
use App\Http\Controllers\App\NodeDirectoryController;
use App\Http\Controllers\App\PartnerController;
use App\Http\Controllers\App\PartnerGroupController;
use App\Http\Controllers\App\RoleController;
use App\Http\Controllers\App\SyncedListingController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\App\WebhookEndpointController;
use App\Http\Controllers\App\YachtController;
use App\Support\ApiDocs\ApiDocGenerator;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Agent-facing data API guide, generated from code (config/api-docs.php)
// so the endpoint, parameter and field tables never drift from the API.
// The machine-readable OpenAPI spec lives alongside at /docs/api.json.
Route::get('docs/api/{guide}.md', function (string $guide) {
    return response(app(ApiDocGenerator::class)->generate($guide), 200, [
        'Content-Type' => 'text/markdown; charset=UTF-8',
        'Cache-Control' => 'public, max-age=300',
    ]);
})->whereIn('guide', ['yachts'])->name('docs.api.markdown');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}/role', [UserController::class, 'update'])->name('users.role.update');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::put('roles/{role}/permissions', [RoleController::class, 'update'])
        ->whereIn('role', array_column(Role::cases(), 'value'))
        ->name('roles.permissions.update');

    Route::get('federation/partners', [PartnerController::class, 'index'])->name('partners.index');
    Route::post('federation/partners', [PartnerController::class, 'store'])->name('partners.store');
    Route::get('federation/partners/{partner}', [PartnerController::class, 'show'])->name('partners.show');
    Route::post('federation/partners/{partner}/approve', [PartnerController::class, 'approve'])->name('partners.approve');
    Route::post('federation/partners/{partner}/block', [PartnerController::class, 'block'])->name('partners.block');
    Route::post('federation/partners/{partner}/introduce', [PartnerController::class, 'introduce'])->name('partners.introduce');
    Route::delete('federation/partners/{partner}', [PartnerController::class, 'destroy'])->name('partners.destroy');
    Route::post('federation/partners/{partner}/refresh-keys', [PartnerController::class, 'refreshKeys'])->name('partners.refresh-keys');
    Route::post('federation/partners/{partner}/sync', [PartnerController::class, 'sync'])->name('partners.sync');
    Route::post('federation/partners/{partner}/subscribe', [PartnerController::class, 'subscribe'])->name('partners.subscribe');
    Route::delete('federation/partners/{partner}/subscribe', [PartnerController::class, 'unsubscribe'])->name('partners.unsubscribe');
    Route::put('federation/partners/{partner}/field-groups', [PartnerController::class, 'updateFieldGroups'])->name('partners.field-groups.update');
    Route::put('federation/partners/{partner}/acceptance-policy', [PartnerController::class, 'updateAcceptancePolicy'])->name('partners.acceptance-policy.update');
    Route::put('federation/partners/{partner}/sharing-scope', [PartnerController::class, 'updateSharingScope'])->name('partners.sharing-scope.update');
    Route::put('federation/partners/{partner}/shared-listings', [PartnerController::class, 'updateSharedListings'])->name('partners.shared-listings.update');
    Route::put('federation/partners/{partner}/import-types', [PartnerController::class, 'updateImportTypes'])->name('partners.import-types.update');

    Route::post('federation/partner-groups', [PartnerGroupController::class, 'store'])->name('partner-groups.store');
    Route::put('federation/partner-groups/{partnerGroup}', [PartnerGroupController::class, 'update'])->name('partner-groups.update');
    Route::delete('federation/partner-groups/{partnerGroup}', [PartnerGroupController::class, 'destroy'])->name('partner-groups.destroy');

    Route::get('federation/directory', [NodeDirectoryController::class, 'index'])->name('node-directory.index');
    Route::post('federation/directory/refresh', [NodeDirectoryController::class, 'refresh'])->name('node-directory.refresh');
    Route::post('federation/directory/add-partner', [NodeDirectoryController::class, 'addPartner'])->name('node-directory.add-partner');

    // Synced copies: one list per wire type, never mixed. The detail
    // page is shared — a single copy renders the same either way.
    Route::get('federation/listings', [SyncedListingController::class, 'index'])->name('synced-listings.index');
    Route::get('federation/charter-listings', [SyncedListingController::class, 'charterIndex'])->name('synced-charter-listings.index');
    Route::get('federation/listings/{copy}', [SyncedListingController::class, 'show'])->name('synced-listings.show');
    Route::post('federation/listings/{copy}/dismiss-conflict', [SyncedListingController::class, 'dismissConflict'])->name('synced-listings.dismiss-conflict');

    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::put('api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
    Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    // Outbound change-notification webhooks (ChangeNotifier consumers).
    Route::get('webhooks', [WebhookEndpointController::class, 'index'])->name('webhooks.index');
    Route::post('webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
    Route::put('webhooks/{webhook}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
    Route::delete('webhooks/{webhook}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('webhooks/{webhook}/test', [WebhookEndpointController::class, 'test'])->name('webhooks.test');

    Route::get('activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
    Route::put('activity-log/retention', [ActivityLogController::class, 'updateRetention'])->name('activity-log.retention.update');
    Route::post('activity-log/prune', [ActivityLogController::class, 'prune'])->name('activity-log.prune');

    Route::get('yachts', [YachtController::class, 'index'])->name('yachts.index');
    Route::get('yachts/create', [YachtController::class, 'create'])->name('yachts.create');
    Route::post('yachts', [YachtController::class, 'store'])->name('yachts.store');
    Route::get('yachts/{yacht}/edit', [YachtController::class, 'edit'])->name('yachts.edit');
    Route::put('yachts/{yacht}', [YachtController::class, 'update'])->name('yachts.update');
    Route::post('yachts/{yacht}/transition', [YachtController::class, 'transition'])->name('yachts.transition');
    Route::post('yachts/{yacht}/media', [YachtController::class, 'storeMedia'])->name('yachts.media.store');
    Route::patch('yachts/{yacht}/media/{media}', [YachtController::class, 'updateMedia'])->name('yachts.media.update');
    Route::delete('yachts/{yacht}/media/{media}', [YachtController::class, 'destroyMedia'])->name('yachts.media.destroy');
    Route::put('yachts/{yacht}/audience', [YachtController::class, 'updateAudience'])->name('yachts.audience.update');

    // Sale and charter listings are separate screens, never one filtered
    // list — the type is chosen by which page creates the listing.
    Route::get('charter-yachts', [CharterYachtController::class, 'index'])->name('charter-yachts.index');
    Route::get('charter-yachts/create', [CharterYachtController::class, 'create'])->name('charter-yachts.create');
    Route::post('charter-yachts', [CharterYachtController::class, 'store'])->name('charter-yachts.store');
    Route::get('charter-yachts/{charterYacht}/edit', [CharterYachtController::class, 'edit'])->name('charter-yachts.edit');
    Route::put('charter-yachts/{charterYacht}', [CharterYachtController::class, 'update'])->name('charter-yachts.update');
    Route::post('charter-yachts/{charterYacht}/transition', [CharterYachtController::class, 'transition'])->name('charter-yachts.transition');
    Route::post('charter-yachts/{charterYacht}/media', [CharterYachtController::class, 'storeMedia'])->name('charter-yachts.media.store');
    Route::patch('charter-yachts/{charterYacht}/media/{media}', [CharterYachtController::class, 'updateMedia'])->name('charter-yachts.media.update');
    Route::delete('charter-yachts/{charterYacht}/media/{media}', [CharterYachtController::class, 'destroyMedia'])->name('charter-yachts.media.destroy');
    Route::put('charter-yachts/{charterYacht}/audience', [CharterYachtController::class, 'updateAudience'])->name('charter-yachts.audience.update');

    Route::get('imported-yachts', [ImportedYachtController::class, 'index'])->name('imported-yachts.index');
    Route::get('imported-charter-yachts', [ImportedYachtController::class, 'charterIndex'])->name('imported-charter-yachts.index');
    Route::get('imported-yachts/{importedYacht}', [ImportedYachtController::class, 'show'])->name('imported-yachts.show');
    Route::post('federation/listings/{copy}/import', [ImportedYachtController::class, 'store'])->name('imported-yachts.store');
    Route::delete('imported-yachts/{importedYacht}', [ImportedYachtController::class, 'destroy'])->name('imported-yachts.destroy');
});

require __DIR__.'/settings.php';
