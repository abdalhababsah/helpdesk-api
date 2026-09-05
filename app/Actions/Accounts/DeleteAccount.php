<?php

namespace App\Actions\Accounts;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Enums\RefreshRevokeReason;
use App\Enums\RoleSlug;
use App\Enums\TicketStatus;
use App\Exceptions\CannotModifyOwnAccount;
use App\Exceptions\LastAdminProtected;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes an account from every list and every login, and keeps the row.
 *
 * Tickets and comments still point at the person, so the history reads the
 * same after they leave. Their open work goes back to the queue rather than
 * sitting with someone who can no longer see it.
 */
final class DeleteAccount
{
    use RecordsActions;

    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    public function handle(Actor $actor, User $user): void
    {
        $actor->authorize(PermissionSlug::AccountDelete);

        if ($user->getKey() === $actor->id()) {
            throw new CannotModifyOwnAccount;
        }

        if ($user->is_active && $user->role->slug === RoleSlug::Admin && $this->activeAdminCount() <= 1) {
            throw new LastAdminProtected;
        }

        DB::transaction(function () use ($actor, $user): void {
            $released = Ticket::where('assignee_id', $user->getKey())
                ->whereIn('status', array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::open()))
                ->update(['assignee_id' => null]);

            $this->revokeSessions->handle($user, RefreshRevokeReason::UserDeleted);

            $user->forceFill(['is_active' => false])->save();
            $user->delete();

            $this->record(ActionType::AccountDeleted, $actor->user, $user, [
                'email' => $user->email,
                'ticketsReleased' => $released,
            ]);
        });
    }

    private function activeAdminCount(): int
    {
        return User::where('is_active', true)
            ->whereRelation('role', 'slug', RoleSlug::Admin->value)
            ->count();
    }
}
