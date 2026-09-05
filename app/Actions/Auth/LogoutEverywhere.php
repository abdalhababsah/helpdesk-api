<?php

namespace App\Actions\Auth;

use App\Actions\Accounts\RevokeSessions;
use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\RefreshRevokeReason;
use Illuminate\Support\Facades\DB;

/**
 * Signs the actor out of every device. Needs no permission: it acts only on the
 * caller's own sessions.
 */
final class LogoutEverywhere
{
    use RecordsActions;

    public function __construct(private readonly RevokeSessions $revokeSessions) {}

    public function handle(Actor $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $this->revokeSessions->handle($actor->user, RefreshRevokeReason::LogoutAll);

            $this->record(ActionType::LoggedOutEverywhere, $actor->user, $actor->user);
        });
    }
}
