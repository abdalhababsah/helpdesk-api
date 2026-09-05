<?php

namespace App\Actions\Auth;

use App\Actions\Concerns\RecordsActions;
use App\Enums\ActionType;
use App\Exceptions\AuthFailure;
use App\Models\User;
use App\Support\AccessToken;
use App\Support\IssuedSession;
use App\Support\RefreshTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class LoginUser
{
    use RecordsActions;

    /**
     * A real bcrypt digest of a value nothing uses. Verifying against it when
     * the address is unknown keeps the work identical either way, so response
     * time cannot be used to discover which addresses have accounts.
     */
    private const DUMMY_DIGEST = '$2y$12$d1BQJShc0apDBAnc4G3N2eqIUIU.eSVP5tDyVoYetTVk8ikcIgQXC';

    public function __construct(
        private readonly AccessToken $accessToken,
        private readonly RefreshTokenIssuer $refreshTokens,
    ) {}

    public function handle(string $email, string $password): IssuedSession
    {
        $email = mb_strtolower($email);
        $user = User::with('role')->where('email', $email)->first();

        $digest = $user !== null ? $user->password : self::DUMMY_DIGEST;
        $verified = Hash::check($password, $digest);

        // Recorded outside a transaction on purpose: a failed attempt is a fact
        // even though nothing else about it succeeded.
        if ($user === null || ! $verified) {
            $this->record(ActionType::LoginFailed, null, $user, ['email' => $email]);

            throw AuthFailure::invalidCredentials();
        }

        // Only after the password verified, so this reveals nothing to someone
        // who does not already hold the credentials.
        if (! $user->is_active) {
            $this->record(ActionType::LoginBlocked, null, $user, ['email' => $email]);

            throw AuthFailure::accountDisabled();
        }

        return DB::transaction(function () use ($user): IssuedSession {
            [$raw] = $this->refreshTokens->issue($user);

            $user->forceFill(['last_login_at' => now()])->save();

            $this->record(ActionType::LoginSucceeded, $user, $user);

            return new IssuedSession($user, $this->accessToken->issue($user), $raw);
        });
    }
}
