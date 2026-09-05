<?php

namespace App\Actions\Accounts;

use App\Enums\RefreshRevokeReason;
use App\Models\RefreshToken;
use App\Models\User;

/**
 * Ends every live session for a user.
 *
 * Two mechanisms, because there are two kinds of token. Refresh tokens are
 * rows, so they are revoked directly. Access tokens are signed and not stored,
 * so they are invalidated by moving token_version: a token is accepted only
 * while its version claim still matches.
 *
 * Not an action in its own right. It has no permission of its own and is never
 * a use case a caller asks for, only a consequence of one.
 */
final class RevokeSessions
{
    public function handle(User $user, RefreshRevokeReason $reason): void
    {
        $user->increment('token_version');

        RefreshToken::where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason->value,
            ]);
    }
}
