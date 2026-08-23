<?php

use App\Enums\Role;
use App\Http\Controllers\App\ApiKeyController;
use App\Http\Controllers\App\ImportedYachtController;
use App\Http\Controllers\App\NodeDirectoryController;
use App\Http\Controllers\App\PartnerController;
use App\Http\Controllers\App\RoleController;
use App\Http\Controllers\App\SyncedListingController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\App\YachtController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
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
    Route::post('federation/partners/{partner}/refresh-keys', [PartnerController::class, 'refreshKeys'])->name('partners.refresh-keys');
    Route::post('federation/partners/{partner}/sync', [PartnerController::class, 'sync'])->name('partners.sync');

    Route::get('federation/directory', [NodeDirectoryController::class, 'index'])->name('node-directory.index');
    Route::post('federation/directory/refresh', [NodeDirectoryController::class, 'refresh'])->name('node-directory.refresh');
    Route::post('federation/directory/add-partner', [NodeDirectoryController::class, 'addPartner'])->name('node-directory.add-partner');

    Route::get('federation/listings', [SyncedListingController::class, 'index'])->name('synced-listings.index');

    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::put('api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
    Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

    Route::get('yachts', [YachtController::class, 'index'])->name('yachts.index');
    Route::get('yachts/create', [YachtController::class, 'create'])->name('yachts.create');
    Route::post('yachts', [YachtController::class, 'store'])->name('yachts.store');
    Route::get('yachts/{yacht}/edit', [YachtController::class, 'edit'])->name('yachts.edit');
    Route::put('yachts/{yacht}', [YachtController::class, 'update'])->name('yachts.update');
    Route::post('yachts/{yacht}/transition', [YachtController::class, 'transition'])->name('yachts.transition');
    Route::post('yachts/{yacht}/media', [YachtController::class, 'storeMedia'])->name('yachts.media.store');
    Route::patch('yachts/{yacht}/media/{media}', [YachtController::class, 'updateMedia'])->name('yachts.media.update');
    Route::delete('yachts/{yacht}/media/{media}', [YachtController::class, 'destroyMedia'])->name('yachts.media.destroy');

    Route::get('imported-yachts', [ImportedYachtController::class, 'index'])->name('imported-yachts.index');
    Route::get('imported-yachts/{importedYacht}', [ImportedYachtController::class, 'show'])->name('imported-yachts.show');
    Route::post('federation/listings/{copy}/import', [ImportedYachtController::class, 'store'])->name('imported-yachts.store');
    Route::delete('imported-yachts/{importedYacht}', [ImportedYachtController::class, 'destroy'])->name('imported-yachts.destroy');
});

require __DIR__.'/settings.php';
