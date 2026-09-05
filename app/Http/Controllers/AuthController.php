<?php

namespace App\Http\Controllers;

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\LogoutEverywhere;
use App\Actions\Auth\LogoutSession;
use App\Actions\Auth\RefreshSession;
use App\Authorization\Actor;
use App\Enums\PermissionScope;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Support\IssuedSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginUser $login): JsonResponse
    {
        return $this->sessionResponse($login->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        ));
    }

    public function refresh(Request $request, RefreshSession $refresh): JsonResponse
    {
        return $this->sessionResponse($refresh->handle((string) $request->cookie($this->cookieName())));
    }

    public function logout(Request $request, LogoutSession $logout): JsonResponse
    {
        $logout->handle($request->cookie($this->cookieName()));

        // Cleared whatever the outcome. A client that cannot get rid of its
        // cookie would retry a token that is already dead.
        return response()->json(null, 204)->withCookie($this->forgetCookie());
    }

    public function logoutEverywhere(Actor $actor, LogoutEverywhere $logout): JsonResponse
    {
        $logout->handle($actor);

        return response()->json(null, 204)->withCookie($this->forgetCookie());
    }

    /**
     * Returns the grants alongside the user so the client can render the right
     * dashboard without inferring permissions from the role name, which would
     * put a second copy of the matrix in the frontend.
     */
    public function me(Actor $actor): JsonResponse
    {
        $permissions = array_map(
            fn (PermissionScope $scope): string => $scope->value,
            $actor->grants(),
        );

        return response()->json([
            'data' => [
                'user' => new UserResource($actor->user),
                'permissions' => $permissions,
            ],
        ]);
    }

    private function sessionResponse(IssuedSession $session): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($session->user),
                'accessToken' => $session->accessToken,
                'expiresIn' => (int) config('jwt.ttl_minutes') * 60,
            ],
        ])->withCookie($this->refreshCookie($session->refreshToken));
    }

    private function refreshCookie(string $value): Cookie
    {
        return cookie()->make(
            name: $this->cookieName(),
            value: $value,
            minutes: (int) config('jwt.refresh.ttl_days') * 24 * 60,
            // Scoped to the auth routes, so it is never attached to a ticket or
            // user request and cannot leak through an unrelated handler.
            path: (string) config('jwt.refresh.path'),
            domain: config('session.domain'),
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            // Lax rather than Strict: Strict withholds the cookie on inbound
            // navigation from another site, so a shared filtered link would
            // land the user logged out.
            sameSite: 'lax',
        );
    }

    private function forgetCookie(): Cookie
    {
        return cookie()->forget($this->cookieName(), (string) config('jwt.refresh.path'));
    }

    private function cookieName(): string
    {
        return (string) config('jwt.refresh.cookie');
    }
}
