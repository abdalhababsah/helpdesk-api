<?php

namespace App\Actions\Passwords;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Exceptions\AccountInactive;
use App\Models\User;
use App\Support\PasswordResetIssuer;
use Illuminate\Support\Facades\DB;

/**
 * An administrator sending someone a reset link.
 *
 * Link only, never a typed password: a password chosen by an administrator
 * ends up in a chat message, and the person would have to be told to change
 * it anyway.
 */
final class SendPasswordReset
{
    use RecordsActions;

    public function __construct(private readonly PasswordResetIssuer $issuer) {}

    public function handle(Actor $actor, User $user): void
    {
        $actor->authorize(PermissionSlug::AccountManage);

        if (! $user->is_active) {
            throw new AccountInactive;
        }

        DB::transaction(function () use ($actor, $user): void {
            $this->issuer->issue($user, $actor->user);
            $this->record(ActionType::PasswordResetSent, $actor->user, $user, ['email' => $user->email]);
        });
    }
}
