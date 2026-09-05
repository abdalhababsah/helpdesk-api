<?php

namespace App\Support;

use App\Models\User;

/**
 * What a successful login or refresh produces.
 *
 * The raw refresh token appears here and nowhere else: it is written to a
 * cookie by the controller and only its digest reaches the database.
 */
final readonly class IssuedSession
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public string $refreshToken,
    ) {}
}
