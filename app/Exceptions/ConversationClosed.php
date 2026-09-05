<?php

namespace App\Exceptions;

use RuntimeException;

/** A conversation that has been settled takes no further messages. */
final class ConversationClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This conversation has ended. Start a new one.');
    }
}
