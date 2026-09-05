<?php

namespace App\Exceptions;

use RuntimeException;

final class TicketIsClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A closed ticket accepts no further replies.');
    }
}
