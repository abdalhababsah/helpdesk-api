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
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChangeAccountRole
{
    use RecordsActions;

    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    public function handle(Actor $actor, User $user, string $roleId): User
    {
        $actor->authorize(PermissionSlug::AccountManage);

        if ($user->getKey() === $actor->id()) {
            throw new CannotModifyOwnAccount;
        }

        $target = Role::findOrFail($roleId);

        if ($user->role_id === $roleId) {
            return $user;
        }

        if ($this->wouldRemoveLastAdmin($user, $target->slug)) {
            throw new LastAdminProtected;
        }

        return DB::transaction(function () use ($actor, $user, $target): User {
            $from = $user->role->slug->value;
            $user->update(['role_id' => $target->getKey()]);

            // The role travels inside the access token, so a demotion that only
            // changed the row would leave the old permissions live until the
            // token expired.
            $this->revokeSessions->handle($user, RefreshRevokeReason::RoleChanged);

            $this->record(ActionType::AccountRoleChanged, $actor->user, $user, [
                'from' => $from,
                'to' => $target->slug->value,
            ]);

            return $user->refresh();
        });
    }

    private function wouldRemoveLastAdmin(User $user, RoleSlug $target): bool
    {
        // An inactive admin is not one of the administrators who can still act,
        // so demoting them removes nothing that needs protecting.
        if ($target === RoleSlug::Admin || $user->role->slug !== RoleSlug::Admin || ! $user->is_active) {
            return false;
        }

        return $this->activeAdminCount() <= 1;
    }

    private function activeAdminCount(): int
    {
        return User::where('is_active', true)
            ->whereRelation('role', 'slug', RoleSlug::Admin->value)
            ->count();
    }
}
