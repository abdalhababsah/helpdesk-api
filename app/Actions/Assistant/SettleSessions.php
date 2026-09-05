<?php

namespace App\Actions\Assistant;

use App\Enums\AssistantOutcome;
use App\Models\AssistantSession;

/**
 * Decides the outcomes that time decides.
 *
 * A conversation whose last answer came from the knowledge base and has been
 * quiet since counts as answered. One left alone for a day without a ticket
 * was given up on. An outcome already decided is never revisited, so a raised
 * ticket cannot later be counted as abandoned.
 */
final class SettleSessions
{
    /** @return array{answered: int, abandoned: int} */
    public function handle(): array
    {
        $quietSince = now()->subMinutes((int) config('assistant.answered_after_minutes'));
        $goneSince = now()->subHours((int) config('assistant.guest_ttl_hours'));

        $answered = AssistantSession::query()
            ->where('outcome', AssistantOutcome::Open->value)
            ->where('last_agent', 'knowledge_base')
            ->where('turns', '>', 0)
            ->where('last_activity_at', '<', $quietSince)
            ->update(['outcome' => AssistantOutcome::Answered->value]);

        $abandoned = AssistantSession::query()
            ->where('outcome', AssistantOutcome::Open->value)
            ->where('last_activity_at', '<', $goneSince)
            ->update(['outcome' => AssistantOutcome::Abandoned->value]);

        return ['answered' => $answered, 'abandoned' => $abandoned];
    }
}
