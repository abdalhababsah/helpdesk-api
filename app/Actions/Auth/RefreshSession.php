<?php

namespace App\Actions\Auth;

use App\Actions\Concerns\RecordsActions;
use App\Enums\ActionType;
use App\Enums\RefreshRevokeReason;
use App\Exceptions\AuthFailure;
use App\Models\RefreshToken;
use App\Support\AccessToken;
use App\Support\IssuedSession;
use App\Support\RefreshTokenIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Exchanges a refresh token for a new pair, rotating it in the process.
 *
 * Every refresh mints a fresh access token from the user's current role and
 * token_version, so a role change becomes visible within one refresh rather
 * than persisting until the old token expires.
 */
final class RefreshSession
{
    use RecordsActions;

    public function __construct(
        private readonly AccessToken $accessToken,
        private readonly RefreshTokenIssuer $refreshTokens,
    ) {}

    public function handle(string $rawToken): IssuedSession
    {
        // The closure returns the failure instead of throwing it. Throwing from
        // inside would roll back the transaction, and on the replay path that
        // would undo the family revocation this method exists to perform,
        // leaving a known-leaked token live.
        $result = DB::transaction(function () use ($rawToken): IssuedSession|AuthFailure {
            $token = $this->refreshTokens->findForUpdate($rawToken);

            if ($token === null) {
                return AuthFailure::refreshInvalid();
            }

            if ($token->revoked_at !== null) {
                return $this->handleReplay($token);
            }

            if ($token->expires_at->isPast()) {
                $token->update([
                    'revoked_at' => now(),
                    'revoked_reason' => RefreshRevokeReason::Expired,
                ]);

                return AuthFailure::refreshInvalid();
            }

            $user = $token->user()->with('role')->first();

            if ($user === null || ! $user->is_active) {
                return AuthFailure::accountDisabled();
            }

            [$raw, $child] = $this->refreshTokens->issue($user, $token->family_id);

            $token->update([
                'revoked_at' => now(),
                'revoked_reason' => RefreshRevokeReason::Rotated,
                'replaced_by_id' => $child->getKey(),
            ]);

            $this->record(ActionType::TokenRefreshed, $user, $user, [
                'family_id' => $token->family_id,
            ]);

            return new IssuedSession($user, $this->accessToken->issue($user), $raw);
        });

        if ($result instanceof AuthFailure) {
            throw $result;
        }

        return $result;
    }

    /**
     * A token that was already spent is being presented again. Either it leaked
     * or a client is buggy, and neither can be told apart from here, so the
     * whole family goes. That signs out every device on this login, which is
     * the intended blast radius.
     */
    private function handleReplay(RefreshToken $token): AuthFailure
    {
        RefreshToken::where('family_id', $token->family_id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => RefreshRevokeReason::ReuseDetected->value,
            ]);

        // Refresh rows alone are not the whole session. An access token already
        // minted from this family stays valid until it expires unless the
        // version moves, so a replayed token would keep working for minutes
        // after it was detected.
        $token->user->increment('token_version');

        $this->record(ActionType::TokenReuseDetected, null, $token->user, [
            'family_id' => $token->family_id,
            'presented_token_id' => $token->getKey(),
        ]);

        return AuthFailure::refreshInvalid();
    }
}
