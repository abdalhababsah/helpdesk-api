<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * One message for every way a reset link can be bad: unknown, expired, already
 * used, or presented with the wrong address. Telling them apart would let
 * someone probe which links were real.
 */
final class PasswordResetInvalid extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This reset link is not valid.');
    }
}
