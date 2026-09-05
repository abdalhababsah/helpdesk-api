<?php

namespace App\Http\Middleware;

use App\Exceptions\AuthFailure;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a refresh attempt that did not come from the configured frontend.
 *
 * The refresh cookie is the one place this design is exposed to cross-site
 * request forgery: every other route is authenticated by a header, which a
 * foreign page cannot set. SameSite=Lax already blocks a cross-site POST, so
 * this is the second layer rather than the only one.
 *
 * A request with no Origin at all is allowed through: server-to-server callers
 * and some browsers omit it, and rejecting those would break legitimate use for
 * no gain, since a cross-site form post always carries one.
 */
final class VerifyRefreshOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $expected = config('cors.allowed_origins');

        if ($origin !== null && $expected !== [] && ! in_array($origin, $expected, true)) {
            throw AuthFailure::refreshInvalid();
        }

        return $next($request);
    }
}
