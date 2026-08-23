<?php

use App\Http\Controllers\Api\V1\YachtsController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal Data API
|--------------------------------------------------------------------------
|
| Key-authenticated API for websites and feeds consuming this node's
| yacht data. Distinct from the federation API (routes/federation.php):
| federation is node-to-node with signatures; this is application-to-
| consumer with API keys, scopes, and per-key rate limits.
|
| GET /api/v1/yachts           every displayable yacht, wire schema shape
| GET /api/v1/yachts?source=own       just this node's inventory
| GET /api/v1/yachts?source=imported  just the partner listings
| GET /api/v1/yachts/{key}     one yacht (uuid, or imported-{id})
|
*/

Route::prefix('v1')->group(function (): void {
    Route::middleware(AuthenticateApiKey::class.':yachts:read')->group(function (): void {
        Route::get('yachts', [YachtsController::class, 'index'])->name('api.yachts.index');
        Route::get('yachts/{key}', [YachtsController::class, 'show'])->name('api.yachts.show');
    });
});
