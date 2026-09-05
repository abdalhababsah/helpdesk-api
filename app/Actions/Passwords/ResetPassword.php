<?php

namespace App\Actions\Passwords;

use App\Actions\Accounts\RevokeSessions;
use App\Actions\Concerns\RecordsActions;
use App\Enums\ActionType;
use App\Enums\RefreshRevokeReason;
use App\Exceptions\PasswordResetInvalid;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Support\PasswordResetIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Turns a link back into a new password.
 *
 * Every session ends when the password changes. Someone resetting because
 * they suspect their old password leaked needs the leak's sessions gone too.
 */
final class ResetPassword
{
    use RecordsActions;

    public function __construct(
        private readonly PasswordResetIssuer $issuer,
        private readonly RevokeSessions $revokeSessions,
    ) {}

    public function handle(string $rawToken, string $email, string $password): User
    {
        $token = $this->issuer->find($rawToken);
        $user = $token?->user;

        if ($token === null || $user === null || ! $token->isUsable() || $user->trashed()
            || ! $user->is_active || $user->email !== mb_strtolower($email)) {
            throw new PasswordResetInvalid;
        }

        return DB::transaction(function () use ($token, $user, $password): User {
            $token->update(['used_at' => now()]);
            $user->update(['password' => $password]);

            $this->revokeSessions->handle($user, RefreshRevokeReason::PasswordReset);
            $this->record(ActionType::PasswordChanged, $user, $user, ['via' => 'reset_link']);

            $user->notify(new PasswordChanged);

            return $user;
        });
    }
}
