<?php

namespace App\Support;

use App\Exceptions\AuthFailure;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mints and verifies the short-lived access token.
 *
 * Verification never touches the database, so a forged or expired token is
 * rejected before it can cost a query. Checking the token against the user is
 * a separate step and belongs to the middleware.
 */
final class AccessToken
{
    public function issue(User $user): string
    {
        $now = now()->timestamp;

        return JWT::encode([
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'sub' => $user->getKey(),
            'jti' => (string) Str::ulid(),
            'iat' => $now,
            'exp' => $now + (config('jwt.ttl_minutes') * 60),
            // Carried so authorization needs no lookup for the common case,
            // and invalidated by ver whenever the role changes.
            'role' => $user->role->slug->value,
            'ver' => $user->token_version,
        ], $this->secret(), config('jwt.algorithm'));
    }

    public function parse(string $token): AccessTokenClaims
    {
        // php-jwt validates expiry against PHP's clock by default, which can
        // drift from the application's. Pinning it to the same source keeps
        // issuing and verifying on one clock.
        JWT::$timestamp = now()->timestamp;

        try {
            $payload = JWT::decode($token, new Key($this->secret(), config('jwt.algorithm')));
        } catch (Throwable) {
            // Signature, expiry and malformed input all mean the same thing to
            // a caller: this token cannot be trusted.
            throw AuthFailure::required();
        }

        // php-jwt verifies neither issuer nor audience, so a token minted for
        // another service with the same secret would otherwise pass.
        if (($payload->iss ?? null) !== config('jwt.issuer') || ($payload->aud ?? null) !== config('jwt.audience')) {
            throw AuthFailure::required();
        }

        if (! isset($payload->sub, $payload->role, $payload->ver)) {
            throw AuthFailure::required();
        }

        return new AccessTokenClaims(
            userId: (string) $payload->sub,
            roleSlug: (string) $payload->role,
            version: (int) $payload->ver,
        );
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');

        // Failing at boot beats failing at the first login, and a short secret
        // is a weak secret rather than a missing one.
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters.');
        }

        return $secret;
    }
}
