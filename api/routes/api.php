<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Discover\DiscoverController;
use App\Http\Controllers\Library\CollectionController;
use App\Http\Controllers\Library\GameMetaController;
use App\Http\Controllers\Library\LibraryController;
use App\Http\Controllers\Library\ManualEntryController;
use App\Http\Controllers\Stats\StatsController;
use App\Http\Controllers\Wishlist\WishlistController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    // V17: credential changes re-verify the password; throttled like auth.
    Route::patch('/user', [AccountController::class, 'update'])->middleware('throttle:auth');

    Route::get('/tokens', [TokenController::class, 'index']);
    Route::post('/tokens', [TokenController::class, 'store']);
    Route::delete('/tokens/{tokenId}', [TokenController::class, 'destroy']);

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::get('/connections/steam/resolve', [ConnectionController::class, 'resolveSteam']);
    Route::post('/connections', [ConnectionController::class, 'store']);
    Route::post('/connections/{connection}/sync', [ConnectionController::class, 'sync']);
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy']);

    Route::get('/library', [LibraryController::class, 'index']);
    // T28: must precede /library/{game} — otherwise "facets" binds as {game}.
    Route::get('/library/facets', [LibraryController::class, 'facets']);
    Route::get('/library/{game}', [LibraryController::class, 'show']);
    Route::post('/library', [ManualEntryController::class, 'store']);
    Route::delete('/library/{game}/manual', [ManualEntryController::class, 'destroy']);
    Route::put('/library/{game}/meta', [GameMetaController::class, 'update']);

    Route::get('/collections', [CollectionController::class, 'index']);
    Route::post('/collections', [CollectionController::class, 'store']);
    Route::post('/collections/{collection}/games', [CollectionController::class, 'addGame']);
    Route::delete('/collections/{collection}/games/{game}', [CollectionController::class, 'removeGame']);

    Route::get('/stats/backlog', [StatsController::class, 'backlog']);

    Route::get('/discover/search', [DiscoverController::class, 'search']);
    Route::get('/discover/browse', [DiscoverController::class, 'browse']);
    Route::get('/discover/similar', [DiscoverController::class, 'similar']);
    Route::get('/discover/franchises', [DiscoverController::class, 'franchises']);
    Route::get('/discover/upcoming', [DiscoverController::class, 'upcoming']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::post('/wishlist/sync', [WishlistController::class, 'sync']);
    Route::delete('/wishlist/{game}', [WishlistController::class, 'destroy']);
});
