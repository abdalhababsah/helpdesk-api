<?php

namespace App\Console\Commands;

use App\Actions\Assistant\SettleSessions;
use Illuminate\Console\Command;

final class SettleAssistantSessions extends Command
{
    protected $signature = 'assistant:settle-sessions';

    protected $description = 'Close out assistant conversations that were answered or given up on';

    public function handle(SettleSessions $settle): int
    {
        $counts = $settle->handle();

        $this->info("Answered: {$counts['answered']}. Abandoned: {$counts['abandoned']}.");

        return self::SUCCESS;
    }
}
