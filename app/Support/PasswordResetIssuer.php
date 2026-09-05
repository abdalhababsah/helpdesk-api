<?php

namespace App\Support;

use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Support\Str;

/**
 * Mints a reset token and emails the link that carries it.
 *
 * Only the digest is stored; the raw value lives in the email and nowhere
 * else. A new token retires any earlier unused one, so the most recent email
 * is the only one that works and an old message in an inbox cannot be replayed
 * after a fresh request.
 */
final class PasswordResetIssuer
{
    public function issue(User $user, ?User $requestedBy = null): PasswordResetToken
    {
        PasswordResetToken::where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $raw = Str::random(64);

        $token = PasswordResetToken::create([
            'user_id' => $user->getKey(),
            'token' => $this->digest($raw),
            'requested_by_id' => $requestedBy?->getKey(),
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);

        $user->notify(new PasswordResetLink($this->link($raw, $user->email), $this->ttlMinutes()));

        return $token;
    }

    public function find(string $raw): ?PasswordResetToken
    {
        return PasswordResetToken::with('user.role')->where('token', $this->digest($raw))->first();
    }

    public function digest(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public function ttlMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    private function link(string $raw, string $email): string
    {
        return config('app.frontend_url').'/reset-password?'.http_build_query(['token' => $raw, 'email' => $email]);
    }
}
