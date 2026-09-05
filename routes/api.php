<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('auth.jwt')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout-all', [AuthController::class, 'logoutEverywhere']);
    });
});

Route::middleware('auth.jwt')->group(function (): void {
    Route::get('/tickets', [TicketController::class, 'index']);
    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
});
