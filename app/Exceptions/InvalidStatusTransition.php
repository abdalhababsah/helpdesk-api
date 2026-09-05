<?php

namespace App\Exceptions;

use App\Enums\TicketStatus;
use RuntimeException;

/**
 * The request was well formed and the actor was permitted; the ticket was in
 * the wrong state. That is a conflict, not a validation failure.
 */
final class InvalidStatusTransition extends RuntimeException
{
    public function __construct(public readonly TicketStatus $from, public readonly TicketStatus $to)
    {
        parent::__construct("A {$from->value} ticket cannot move to {$to->value}.");
    }
}
