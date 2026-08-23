<?php

use App\Http\Controllers\Federation\CapabilitiesController;
use App\Http\Controllers\Federation\HealthController;
use App\Http\Controllers\Federation\ListingsController;
use App\Http\Controllers\Federation\PartnersController;
use App\Http\Controllers\Federation\WellKnownController;
use App\Http\Middleware\EnsureIdentityDomain;
use App\Http\Middleware\VerifyFederationSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Federation Routes
|--------------------------------------------------------------------------
|
| The protocol surface: stateless JSON, served only on the identity domain
| (federation-protocol.md). No session or cookie middleware — federation
| requests authenticate by signature, not by session. Everything under
| /openyacht/v1/ except health and capabilities requires a valid
| federation signature (FP-6).
|
*/

Route::middleware(EnsureIdentityDomain::class)->group(function (): void {
    Route::get('/.well-known/openyacht', WellKnownController::class)
        ->name('federation.well-known');

    Route::prefix('openyacht/v1')->group(function (): void {
        Route::get('health', HealthController::class)->name('federation.health');
        Route::get('capabilities', CapabilitiesController::class)->name('federation.capabilities');

        Route::middleware(VerifyFederationSignature::class)->group(function (): void {
            Route::get('listings', [ListingsController::class, 'index'])
                ->name('federation.listings.index');
            Route::get('listings/{uuid}', [ListingsController::class, 'show'])
                ->name('federation.listings.show');
        });

        // Partnership requests come, by definition, from partners not yet
        // approved: authenticated but provisional senders are allowed.
        Route::middleware(VerifyFederationSignature::class.':allow-provisional')->group(function (): void {
            Route::post('partners/request', [PartnersController::class, 'request'])
                ->name('federation.partners.request');
        });
    });
});
