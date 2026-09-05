<?php

namespace App\Enums;

/** How a ticket reached the desk. */
enum TicketSource: string
{
    case Direct = 'direct';
    case Assistant = 'assistant';
}
