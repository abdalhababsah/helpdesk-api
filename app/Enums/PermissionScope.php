<?php

namespace App\Enums;

/**
 * How far a grant reaches. The same permission is held at different scopes by
 * different roles: a user reads only tickets they requested, a moderator reads
 * every ticket.
 */
enum PermissionScope: string
{
    case Own = 'own';
    case All = 'all';
}
