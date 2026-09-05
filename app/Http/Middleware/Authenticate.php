<?php

namespace App\Http\Middleware;

use App\Authorization\Actor;
use App\Authorization\PermissionRegistry;
use App\Exceptions\AuthFailure;
use App\Models\User;
use App\Support\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a bearer token into an Actor.
 *
 * Signature and expiry are checked before the database is touched, so a forged
 * or stale token is rejected without costing a query. The lookup that follows
 * is the price of revocation: a purely stateless check could not tell that an
 * account was deactivated a minute ago.
 */
final class Authenticate
{
    public function __construct(
        private readonly AccessToken $accessToken,
        private readonly PermissionRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            throw AuthFailure::required();
        }

        $claims = $this->accessToken->parse($bearer);

        $user = User::with('role')->find($claims->userId);

        if ($user === null) {
            throw AuthFailure::required();
        }

        if (! $user->is_active) {
            throw AuthFailure::accountDisabled();
        }

        if ($user->token_version !== $claims->version) {
            throw AuthFailure::tokenStale();
        }

        $request->setUserResolver(fn (): User => $user);
        app()->instance(Actor::class, Actor::for($user, $this->registry));

        return $next($request);
    }
}
