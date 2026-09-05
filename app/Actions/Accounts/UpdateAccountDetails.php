<?php

namespace App\Actions\Accounts;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a name or an email address.
 *
 * Deliberately does not end the person's sessions, unlike a role change or a
 * deactivation. Neither field grants anything, so signing someone out because
 * a typo in their surname was fixed would be punishing them for an
 * administrator's correction.
 */
final class UpdateAccountDetails
{
    use RecordsActions;

    public function handle(Actor $actor, User $user, ?string $name = null, ?string $email = null): User
    {
        $actor->authorize(PermissionSlug::AccountManage);

        $changes = [];

        if ($name !== null && $name !== $user->name) {
            $changes['name'] = ['from' => $user->name, 'to' => $name];
        }

        // Lower-cased on the way in, so the unique index and every lookup agree
        // however it was typed.
        $email = $email === null ? null : mb_strtolower($email);

        if ($email !== null && $email !== $user->email) {
            $changes['email'] = ['from' => $user->email, 'to' => $email];
        }

        if ($changes === []) {
            return $user;
        }

        return DB::transaction(function () use ($actor, $user, $changes): User {
            $user->update(array_map(fn (array $change): string => $change['to'], $changes));

            $this->record(ActionType::AccountDetailsChanged, $actor->user, $user, $changes);

            return $user;
        });
    }
}
