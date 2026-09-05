<?php

namespace App\Exceptions;

use RuntimeException;

/** The provider could not be reached or refused the request twice running. */
final class AssistantUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The assistant is not available right now. Try again in a moment.');
    }
}
