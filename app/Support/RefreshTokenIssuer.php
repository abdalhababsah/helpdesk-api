<?php

namespace App\Support;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Mints refresh tokens and their rotation chains.
 *
 * The value handed to the client is random and opaque; only its SHA-256 digest
 * is stored, so a database leak yields nothing replayable. A plain hash is
 * enough here, unlike a password: the value has full entropy, so there is
 * nothing to brute force and no need for a slow function.
 */
final class RefreshTokenIssuer
{
    /**
     * A family is one login. Every rotation stays in it, so detecting a replay
     * lets the whole chain be revoked at once rather than only the token seen.
     *
     * @return array{0: string, 1: RefreshToken} raw value, stored row
     */
    public function issue(User $user, ?string $familyId = null): array
    {
        $raw = Str::random(64);

        $token = RefreshToken::create([
            'user_id' => $user->getKey(),
            'family_id' => $familyId ?? (string) Str::ulid(),
            'token' => $this->digest($raw),
            'expires_at' => now()->addDays((int) config('jwt.refresh.ttl_days')),
            'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
            'ip_address' => request()->ip(),
        ]);

        return [$raw, $token];
    }

    public function find(string $raw): ?RefreshToken
    {
        return RefreshToken::where('token', $this->digest($raw))->first();
    }

    /** Locked for update so two concurrent refreshes cannot both rotate one row. */
    public function findForUpdate(string $raw): ?RefreshToken
    {
        return RefreshToken::where('token', $this->digest($raw))->lockForUpdate()->first();
    }

    public function digest(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
