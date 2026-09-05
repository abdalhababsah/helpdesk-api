<?php

use App\Authorization\Actor;
use App\Authorization\AuthorizationDenied;
use App\Exceptions\AuthFailure;
use App\Exceptions\CannotModifyOwnAccount;
use App\Exceptions\InvalidAssignee;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\LastAdminProtected;
use App\Exceptions\TicketIsClosed;
use App\Http\Middleware\Authenticate;
use App\Support\DenialRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.jwt' => Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One shape for every failure: { error: { code, message, details? } }.
        // A client that has to branch on response shape as well as status ends
        // up parsing prose to decide what went wrong.
        $error = fn (string $code, string $message, int $status, ?array $details = null) => response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ], fn ($value) => $value !== null),
        ], $status);

        $exceptions->render(fn (AuthFailure $e) => $error($e->errorCode, $e->getMessage(), $e->status));

        $exceptions->render(function (AuthorizationDenied $e) use ($error) {
            app(DenialRecorder::class)->capture(
                $e,
                app()->bound(Actor::class) ? app(Actor::class) : null,
            );

            return $error('FORBIDDEN', $e->getMessage(), 403);
        });

        $exceptions->render(fn (AuthorizationException $e) => $error('FORBIDDEN', $e->getMessage(), 403));

        // Well-formed request, permitted actor, wrong state. That is a conflict
        // rather than a validation failure.
        foreach ([InvalidStatusTransition::class, TicketIsClosed::class, CannotModifyOwnAccount::class, LastAdminProtected::class] as $conflict) {
            $exceptions->render(fn (Throwable $e) => $e instanceof $conflict ? $error('CONFLICT', $e->getMessage(), 409) : null);
        }

        $exceptions->render(fn (InvalidAssignee $e) => $error('INVALID_ASSIGNEE', $e->getMessage(), 422));

        // The brief specifies 400 for validation; Laravel defaults to 422.
        $exceptions->render(fn (ValidationException $e) => $error(
            'VALIDATION_FAILED',
            'The request failed validation.',
            400,
            collect($e->errors())
                ->flatMap(fn (array $messages, string $field) => array_map(
                    fn (string $message) => ['field' => $field, 'message' => $message],
                    $messages,
                ))->values()->all(),
        ));

        $exceptions->render(fn (ModelNotFoundException $e) => $error('NOT_FOUND', 'Resource not found.', 404));
        $exceptions->render(fn (NotFoundHttpException $e) => $error('NOT_FOUND', 'Resource not found.', 404));
        $exceptions->render(fn (ThrottleRequestsException $e) => $error('RATE_LIMITED', 'Too many attempts.', 429));
    })->create();
