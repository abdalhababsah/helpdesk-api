<?php

namespace App\Authorization;

/**
 * Something a grant at scope "own" can be tested against. Ownership is the
 * requester of the ticket, including for a comment, which belongs to whoever
 * raised the ticket rather than whoever wrote it.
 */
interface Ownable
{
    public function ownerId(): string;
}
