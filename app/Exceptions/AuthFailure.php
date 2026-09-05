<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every way authentication can fail, each carrying the response code the client
 * needs to tell them apart.
 *
 * The distinction that matters to the client is stale versus required: a stale
 * token should trigger a silent refresh, a required one a redirect to login.
 * Collapsing both into a generic 401 makes the client either refresh loop or
 * log the user out on every expiry.
 */
final class AuthFailure extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Missing, malformed, unsigned or expired access token. */
    public static function required(): self
    {
        return new self('AUTH_REQUIRED', 401, 'Authentication is required.');
    }

    /** Signature is valid but the session was invalidated behind it. */
    public static function tokenStale(): self
    {
        return new self('AUTH_TOKEN_STALE', 401, 'This session has been invalidated.');
    }

    /**
     * Deliberately ambiguous between an unknown address and a wrong password,
     * so login cannot be used to discover who has an account.
     */
    public static function invalidCredentials(): self
    {
        return new self('AUTH_INVALID_CREDENTIALS', 401, 'Those credentials do not match our records.');
    }

    public static function refreshInvalid(): self
    {
        return new self('AUTH_REFRESH_INVALID', 401, 'This session could not be renewed.');
    }

    /**
     * Only ever returned after the password verified. Saying it earlier would
     * let anyone enumerate staff; saying it after costs an attacker nothing
     * they did not already have.
     */
    public static function accountDisabled(): self
    {
        return new self('AUTH_ACCOUNT_DISABLED', 403, 'This account has been deactivated.');
    }
}
