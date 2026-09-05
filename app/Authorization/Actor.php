<?php

namespace App\Authorization;

use App\Enums\PermissionScope;
use App\Enums\PermissionSlug;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An authenticated user together with the grants their role carries.
 *
 * Nothing downstream compares role names. Role string comparisons scattered
 * through the code are how a permission matrix drifts from the table that is
 * supposed to define it.
 */
final class Actor
{
    /**
     * @param  array<string, PermissionScope>  $grants  Permission slug to scope.
     */
    public function __construct(
        public readonly User $user,
        private readonly array $grants,
    ) {}

    public static function for(User $user, PermissionRegistry $registry): self
    {
        return new self($user, $registry->grantsFor($user->role->slug->value));
    }

    public function id(): string
    {
        return $this->user->getKey();
    }

    /**
     * Every grant this actor holds, for a client that needs to know what to
     * render. Read from the cached matrix, not the pivot, so it costs nothing.
     *
     * @return array<string, PermissionScope>
     */
    public function grants(): array
    {
        return $this->grants;
    }

    public function scopeFor(PermissionSlug $permission): ?PermissionScope
    {
        return $this->grants[$permission->value] ?? null;
    }

    public function can(PermissionSlug $permission, ?Ownable $subject = null): bool
    {
        $scope = $this->scopeFor($permission);

        if ($scope === null) {
            return false;
        }

        if ($scope === PermissionScope::All) {
            return true;
        }

        // Scope own means nothing without something to own. A caller that
        // forgot to pass the subject is denied rather than guessed at, so the
        // omission surfaces as a refusal instead of granting everything.
        if ($subject === null) {
            return false;
        }

        return $subject->ownerId() === $this->id();
    }

    /**
     * Throws rather than returning a boolean, so an unchecked return value
     * cannot become an accidental bypass.
     */
    public function authorize(PermissionSlug $permission, ?Ownable $subject = null): void
    {
        if ($this->can($permission, $subject)) {
            return;
        }

        throw new AuthorizationDenied(
            $permission,
            $subject instanceof Model ? $subject->getMorphClass() : null,
            $subject instanceof Model ? (string) $subject->getKey() : null,
        );
    }
}
