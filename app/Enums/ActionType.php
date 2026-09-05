<?php

namespace App\Enums;

/**
 * The audit vocabulary. Every recorded action is one of these, so the log can
 * be filtered and reported on without parsing free text.
 *
 * Names are past tense: the log records what happened, not what was asked for.
 * Failures are their own cases rather than a status column, because a failed
 * login and a successful one are different events, not one event with a flag.
 */
enum ActionType: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case LoginBlocked = 'auth.login_blocked';
    case LoggedOut = 'auth.logged_out';
    case LoggedOutEverywhere = 'auth.logged_out_everywhere';
    case TokenRefreshed = 'auth.token_refreshed';
    case TokenReuseDetected = 'auth.token_reuse_detected';

    case AuthorizationDenied = 'authz.denied';

    case TicketCreated = 'ticket.created';
    case TicketAssigned = 'ticket.assigned';
    case TicketUnassigned = 'ticket.unassigned';
    case TicketStatusChanged = 'ticket.status_changed';
    case TicketPriorityChanged = 'ticket.priority_changed';
    case TicketCategoryChanged = 'ticket.category_changed';
    case TicketDeleted = 'ticket.deleted';
    case TicketCommented = 'ticket.commented';

    case CategoryCreated = 'category.created';
    case CategoryRenamed = 'category.renamed';
    case CategoryRetired = 'category.retired';
    case CategoryRestored = 'category.restored';

    case AccountCreated = 'account.created';
    case AccountRoleChanged = 'account.role_changed';
    case AccountDeactivated = 'account.deactivated';
    case AccountReactivated = 'account.reactivated';

    public function group(): string
    {
        return explode('.', $this->value)[0];
    }

    /** Events worth surfacing on a security review rather than an activity feed. */
    public function isSecurityEvent(): bool
    {
        return in_array($this, [
            self::LoginFailed,
            self::LoginBlocked,
            self::TokenReuseDetected,
            self::AuthorizationDenied,
        ], true);
    }
}
