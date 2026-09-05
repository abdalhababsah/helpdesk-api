<?php

namespace App\Enums;

/** What the assistant can show beside its reply. */
enum CardType: string
{
    case None = 'none';
    case SignInRequired = 'sign_in_required';
    case ExistingTicket = 'existing_ticket';
    case TicketDraft = 'ticket_draft';
}
