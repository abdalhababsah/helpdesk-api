<?php

use App\Http\Controllers\AssistantConversationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\KnowledgeArticleController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\PasswordResetController;
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
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

    Route::middleware('auth.jwt')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout-all', [AuthController::class, 'logoutEverywhere']);
    });
});

// Guests may talk to the assistant, so these authenticate only when a token is
// actually sent. Every route still proves the conversation is the caller's.
Route::prefix('assistant')->middleware('auth.optional')->group(function (): void {
    Route::post('/conversations', [AssistantConversationController::class, 'store'])->middleware('throttle:assistant-start');
    Route::get('/conversations/{session}', [AssistantConversationController::class, 'show']);
    Route::post('/conversations/{session}/messages', [AssistantConversationController::class, 'message'])->middleware('throttle:assistant-message');
    Route::post('/conversations/{session}/tickets', [AssistantConversationController::class, 'ticket']);
    Route::post('/conversations/{session}/claim', [AssistantConversationController::class, 'claim']);
});

Route::middleware('auth.jwt')->group(function (): void {
    Route::get('/assistant/conversations', [AssistantConversationController::class, 'index']);

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
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
    Route::post('/users/{user}/password-reset', [UserController::class, 'sendPasswordReset']);

    Route::get('/knowledge', [KnowledgeArticleController::class, 'index']);
    Route::post('/knowledge', [KnowledgeArticleController::class, 'store']);
    Route::patch('/knowledge/{article}', [KnowledgeArticleController::class, 'update']);
    Route::get('/metrics', [MetricsController::class, 'index']);
});
