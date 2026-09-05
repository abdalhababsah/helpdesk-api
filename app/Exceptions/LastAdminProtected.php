<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Removing the final administrator leaves nobody able to manage accounts,
 * categories or roles, and nothing in the application can undo it.
 */
final class LastAdminProtected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The last active administrator cannot be demoted or deactivated.');
    }
}
