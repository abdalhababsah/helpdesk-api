<?php

namespace App\Authorization;

use App\Enums\PermissionSlug;
use RuntimeException;

/**
 * Carries the permission that was refused so the denial can be logged and
 * reported without the caller having to reconstruct it.
 */
final class AuthorizationDenied extends RuntimeException
{
    public function __construct(
        public readonly PermissionSlug $permission,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
    ) {
        parent::__construct("Not permitted to {$permission->value}.");
    }
}
