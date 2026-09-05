<?php

namespace App\Enums;

/**
 * Stable identifiers for the three seeded system roles. Roles live in the
 * database so grants are data, but code still needs a fixed handle on them,
 * and a slug typo should fail at compile time rather than deny silently.
 */
enum RoleSlug: string
{
    case User = 'user';
    case Moderator = 'moderator';
    case Admin = 'admin';

    /**
     * Roles permitted to hold a ticket assignment.
     *
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Moderator, self::Admin];
    }
}
