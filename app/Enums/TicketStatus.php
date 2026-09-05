<?php

namespace App\Enums;

/**
 * Case order is significant: written to a MySQL ENUM, which sorts by
 * declaration ordinal, so the order here is the lifecycle order. Do not reorder.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Resolved, self::Closed],
            self::InProgress => [self::Resolved, self::Closed],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Closed accepts no further changes or comments. */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /** States that stamp resolved_at and stop the resolution clock. */
    public function isFinished(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    /**
     * States an overdue ticket can still be in.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Open, self::InProgress];
    }
}
