<?php

namespace App\Actions\Accounts;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Enums\RefreshRevokeReason;
use App\Enums\RoleSlug;
use App\Exceptions\CannotModifyOwnAccount;
use App\Exceptions\LastAdminProtected;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivation is the delete. Accounts are never removed: tickets and comments
 * reference them with RESTRICT so attribution survives.
 */
final class SetAccountActive
{
    use RecordsActions;

    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    public function handle(Actor $actor, User $user, bool $active): User
    {
        $actor->authorize(PermissionSlug::AccountManage);

        if ($user->getKey() === $actor->id()) {
            throw new CannotModifyOwnAccount;
        }

        if ($user->is_active === $active) {
            return $user;
        }

        if (! $active && $user->role->slug === RoleSlug::Admin && $this->activeAdminCount() <= 1) {
            throw new LastAdminProtected;
        }

        return DB::transaction(function () use ($actor, $user, $active): User {
            $user->update(['is_active' => $active]);

            // Deactivation must bite immediately, not when the access token
            // happens to expire.
            if (! $active) {
                $this->revokeSessions->handle($user, RefreshRevokeReason::UserDeactivated);
            }

            $this->record(
                $active ? ActionType::AccountReactivated : ActionType::AccountDeactivated,
                $actor->user,
                $user,
                ['email' => $user->email],
            );

            return $user->refresh();
        });
    }

    private function activeAdminCount(): int
    {
        return User::where('is_active', true)
            ->whereRelation('role', 'slug', RoleSlug::Admin->value)
            ->count();
    }
}
