<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A foreign key can only require that the assignee is a user. That they are an
 * active agent is a business rule, so it is enforced here.
 */
final class InvalidAssignee extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A ticket can only be assigned to an active moderator or admin.');
    }
}
