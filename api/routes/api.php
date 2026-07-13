<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Connections\ConnectionController;
use App\Http\Controllers\Library\LibraryController;
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

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::post('/connections', [ConnectionController::class, 'store']);
    Route::post('/connections/{connection}/sync', [ConnectionController::class, 'sync']);
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy']);

    Route::get('/library', [LibraryController::class, 'index']);
});
