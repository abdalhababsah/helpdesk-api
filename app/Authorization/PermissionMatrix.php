<?php

namespace App\Authorization;

use App\Enums\PermissionScope;
use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;

/**
 * The single definition of who may do what. The seeder writes it to the
 * database and the reconciliation test asserts the database still matches it,
 * so a grant cannot drift from the code that enforces it.
 *
 * A permission absent from a role's grants is denied. There are no negative
 * grants, so there is no precedence rule to reason about.
 */
final class PermissionMatrix
{
    /**
     * The permission vocabulary, in the order it is presented to an operator.
     *
     * @return array<string, string> slug => description
     */
    public static function catalogue(): array
    {
        return [
            PermissionSlug::TicketCreate->value => 'Raise a ticket',
            PermissionSlug::TicketRead->value => 'View a ticket and its replies',
            PermissionSlug::TicketListQueue->value => 'Work the shared ticket queue',
            PermissionSlug::TicketComment->value => 'Reply on a ticket',
            PermissionSlug::TicketAssign->value => 'Assign or reassign a ticket',
            PermissionSlug::TicketTriage->value => 'Change status, priority or category',
            PermissionSlug::TicketDelete->value => 'Delete a ticket',
            PermissionSlug::CategoryManage->value => 'Create and retire categories',
            PermissionSlug::AccountManage->value => 'Create, deactivate and re-role accounts',
            PermissionSlug::MetricsRead->value => 'View metrics across every user',
        ];
    }

    /**
     * Grants per role. Scope own means the permission applies only to tickets
     * the actor requested, which is what separates a user reading their own
     * ticket from a moderator reading anyone's.
     *
     * @return array<string, array<string, PermissionScope>>
     */
    public static function grants(): array
    {
        return [
            RoleSlug::User->value => [
                PermissionSlug::TicketCreate->value => PermissionScope::All,
                PermissionSlug::TicketRead->value => PermissionScope::Own,
                PermissionSlug::TicketComment->value => PermissionScope::Own,
            ],
            RoleSlug::Moderator->value => [
                PermissionSlug::TicketCreate->value => PermissionScope::All,
                PermissionSlug::TicketRead->value => PermissionScope::All,
                PermissionSlug::TicketListQueue->value => PermissionScope::All,
                PermissionSlug::TicketComment->value => PermissionScope::All,
                PermissionSlug::TicketAssign->value => PermissionScope::All,
                PermissionSlug::TicketTriage->value => PermissionScope::All,
            ],
            RoleSlug::Admin->value => [
                PermissionSlug::TicketCreate->value => PermissionScope::All,
                PermissionSlug::TicketRead->value => PermissionScope::All,
                PermissionSlug::TicketListQueue->value => PermissionScope::All,
                PermissionSlug::TicketComment->value => PermissionScope::All,
                PermissionSlug::TicketAssign->value => PermissionScope::All,
                PermissionSlug::TicketTriage->value => PermissionScope::All,
                PermissionSlug::TicketDelete->value => PermissionScope::All,
                PermissionSlug::CategoryManage->value => PermissionScope::All,
                PermissionSlug::AccountManage->value => PermissionScope::All,
                PermissionSlug::MetricsRead->value => PermissionScope::All,
            ],
        ];
    }

    /**
     * The three seeded roles.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            RoleSlug::User->value => [
                'name' => 'Employee',
                'description' => 'Raises tickets and follows their own.',
            ],
            RoleSlug::Moderator->value => [
                'name' => 'Support Agent',
                'description' => 'Works the shared queue and resolves tickets.',
            ],
            RoleSlug::Admin->value => [
                'name' => 'Support Manager',
                'description' => 'Manages accounts, categories and metrics.',
            ],
        ];
    }
}
