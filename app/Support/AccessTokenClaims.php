<?php

namespace App\Support;

/**
 * The claims carried by an access token, already validated.
 *
 * Version is the whole point of the type: it is compared against the user's
 * current token_version on every request, which is what makes a stateless
 * token revocable.
 */
final readonly class AccessTokenClaims
{
    public function __construct(
        public string $userId,
        public string $roleSlug,
        public int $version,
    ) {}
}
