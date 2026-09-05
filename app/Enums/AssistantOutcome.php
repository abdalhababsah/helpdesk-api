<?php

namespace App\Enums;

/** How a conversation with the assistant ended. */
enum AssistantOutcome: string
{
    case Open = 'open';
    case Answered = 'answered';
    case TicketRaised = 'ticket_raised';
    case Abandoned = 'abandoned';
}
