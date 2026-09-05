<?php

namespace App\Http\Middleware;

use App\Authorization\Actor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes a guest may call. A bearer token, when sent, must be valid: a
 * signed-in person with a stale token should be told so, not treated as a
 * guest and quietly given a fresh anonymous conversation.
 */
final class AuthenticateIfPresent
{
    public function __construct(private readonly Authenticate $authenticate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            // The actor is bound as a container instance and outlives the request
            // that bound it. Without this, the first anonymous request after a
            // signed-in one on the same process would still be that person.
            app()->forgetInstance(Actor::class);

            return $next($request);
        }

        return $this->authenticate->handle($request, $next);
    }
}
