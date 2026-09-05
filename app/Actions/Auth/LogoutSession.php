<?php

namespace App\Actions\Auth;

use App\Actions\Concerns\RecordsActions;
use App\Enums\ActionType;
use App\Enums\RefreshRevokeReason;
use App\Models\RefreshToken;
use App\Support\RefreshTokenIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Ends the session the presented token belongs to, leaving other devices alone.
 *
 * Silent about an unknown or already-revoked token. Logging out twice is not an
 * error, and reporting which tokens exist would turn this into an oracle.
 */
final class LogoutSession
{
    use RecordsActions;

    public function __construct(private readonly RefreshTokenIssuer $refreshTokens) {}

    public function handle(?string $rawToken): void
    {
        if ($rawToken === null || $rawToken === '') {
            return;
        }

        $token = $this->refreshTokens->find($rawToken);

        if ($token === null) {
            return;
        }

        DB::transaction(function () use ($token): void {
            // The whole family, not just this token: a family is one login, and
            // its earlier rotations would otherwise remain usable.
            RefreshToken::where('family_id', $token->family_id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => RefreshRevokeReason::Logout->value,
                ]);

            $this->record(ActionType::LoggedOut, $token->user, $token->user, [
                'family_id' => $token->family_id,
            ]);
        });
    }
}
