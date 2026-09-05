<?php

namespace App\Exceptions;

use RuntimeException;

/** A reset link for a deactivated account would lead to a login that refuses them. */
final class AccountInactive extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This account is deactivated. Reactivate it before sending a reset link.');
    }
}
