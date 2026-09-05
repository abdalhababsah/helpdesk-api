<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware(['throttle:refresh', 'origin.refresh']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('auth.jwt')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout-all', [AuthController::class, 'logoutEverywhere']);
    });
});

Route::middleware('auth.jwt')->group(function (): void {
    // Fixed segments are declared before the parameterised ones, or the router
    // would try to resolve "summary" and "assignable" as record identifiers.
    Route::get('/tickets/summary', [TicketController::class, 'summary']);
    Route::get('/users/assignable', [UserController::class, 'assignable']);

    Route::get('/tickets', [TicketController::class, 'index']);
    Route::post('/tickets', [TicketController::class, 'store']);
    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update']);
    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy']);
    Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::patch('/categories/{category}', [CategoryController::class, 'update']);

    Route::get('/roles', [RoleController::class, 'index']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);

    Route::get('/metrics', [MetricsController::class, 'index']);
});
