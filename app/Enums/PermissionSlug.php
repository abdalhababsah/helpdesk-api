<?php

namespace App\Enums;

/**
 * The permission vocabulary. Grants are rows, but the set of askable
 * permissions is fixed by the code that enforces them: a permission with no
 * enforcement point behind it would be a grant that does nothing.
 */
enum PermissionSlug: string
{
    case TicketCreate = 'ticket:create';
    case TicketRead = 'ticket:read';
    case TicketListQueue = 'ticket:list_queue';
    case TicketComment = 'ticket:comment';
    case TicketAssign = 'ticket:assign';
    case TicketTriage = 'ticket:triage';
    case TicketDelete = 'ticket:delete';
    case CategoryManage = 'category:manage';
    case AccountManage = 'account:manage';
    case MetricsRead = 'metrics:read';

    public function resource(): string
    {
        return explode(':', $this->value)[0];
    }

    public function action(): string
    {
        return explode(':', $this->value)[1];
    }
}
