<?php

namespace App\Actions\Accounts;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateAccount
{
    use RecordsActions;

    public function handle(Actor $actor, string $name, string $email, string $password, string $roleId): User
    {
        $actor->authorize(PermissionSlug::AccountManage);

        return DB::transaction(function () use ($actor, $name, $email, $password, $roleId): User {
            $user = User::create([
                'name' => $name,
                // Lower-cased on the way in so the unique index and every
                // lookup agree regardless of how it was typed.
                'email' => mb_strtolower($email),
                'password' => $password,
                'role_id' => $roleId,
                'is_active' => true,
            ]);

            $this->record(ActionType::AccountCreated, $actor->user, $user, [
                'email' => $user->email,
                'role' => Role::whereKey($roleId)->value('slug'),
            ]);

            return $user;
        });
    }
}
