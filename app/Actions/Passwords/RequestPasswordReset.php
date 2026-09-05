<?php

namespace App\Actions\Passwords;

use App\Actions\Concerns\RecordsActions;
use App\Enums\ActionType;
use App\Models\User;
use App\Support\PasswordResetIssuer;
use Illuminate\Support\Facades\DB;

/**
 * The self-service "forgotten your password" path.
 *
 * Returns nothing whatever the address, so the response cannot reveal who has
 * an account. Deactivated people get no email either: the link would lead to a
 * login that refuses them.
 */
final class RequestPasswordReset
{
    use RecordsActions;

    public function __construct(private readonly PasswordResetIssuer $issuer) {}

    public function handle(string $email): void
    {
        $email = mb_strtolower($email);
        $user = User::where('email', $email)->first();

        if ($user === null || ! $user->is_active) {
            $this->record(ActionType::PasswordResetRequested, null, $user, ['email' => $email, 'sent' => false]);

            return;
        }

        DB::transaction(function () use ($user, $email): void {
            $this->issuer->issue($user);
            $this->record(ActionType::PasswordResetRequested, null, $user, ['email' => $email, 'sent' => true]);
        });
    }
}
