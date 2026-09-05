<?php

namespace App\Actions\Concerns;

use App\Enums\ActionType;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes an entry to the action log.
 *
 * Call this inside the same transaction as the change it describes. Recording
 * outside the transaction logs work that may then roll back, which is worse
 * than not logging at all: the log stops being evidence.
 */
trait RecordsActions
{
    /**
     * @param  array<string, mixed>  $properties  What changed. Never credentials or token values.
     */
    protected function record(
        ActionType $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $properties = [],
    ): ActionLog {
        return ActionLog::create([
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            // Resolved through the morph map, so the stored type survives a
            // class being renamed or moved.
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            ...$this->requestContext(),
        ]);
    }

    /**
     * Console runs have no meaningful client address, and recording the
     * framework's placeholder would make seeded or scheduled work look like
     * it came from a browser.
     *
     * @return array{ip_address: string|null, user_agent: string|null}
     */
    private function requestContext(): array
    {
        if (app()->runningInConsole()) {
            return ['ip_address' => null, 'user_agent' => null];
        }

        $request = request();

        return [
            'ip_address' => $request->ip(),
            // The column is 255; a crafted header would otherwise fail the insert.
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ];
    }
}
