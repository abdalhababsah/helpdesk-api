<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An administrator changing their own role or deactivating themselves can lock
 * themselves out in a single click, with no path back through the interface.
 */
final class CannotModifyOwnAccount extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You cannot change your own role or deactivate your own account.');
    }
}
