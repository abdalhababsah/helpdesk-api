<?php

namespace App\Support;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Authorization\AuthorizationDenied;
use App\Enums\ActionType;

/**
 * Records refused authorization attempts.
 *
 * Recorded at the exception boundary rather than inside the check, because that
 * is the one place every denial passes through regardless of which action
 * raised it. For a system whose permissions will be probed directly, these are
 * the most useful rows in the log.
 */
final class DenialRecorder
{
    use RecordsActions;

    public function capture(AuthorizationDenied $denial, ?Actor $actor): void
    {
        $this->record(ActionType::AuthorizationDenied, $actor?->user, null, [
            'permission' => $denial->permission->value,
            'subject_type' => $denial->subjectType,
            'subject_id' => $denial->subjectId,
        ]);
    }
}
