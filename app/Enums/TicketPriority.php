<?php

namespace App\Enums;

/**
 * Case order is significant. It is written to a MySQL ENUM, which sorts by
 * declaration ordinal, so ordering by priority descending returns urgent first
 * with no weight column and no FIELD() expression. Do not reorder.
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /** Hours from creation until the ticket is due. */
    public function slaHours(): int
    {
        return match ($this) {
            self::Low => 168,
            self::Medium => 72,
            self::High => 24,
            self::Urgent => 4,
        };
    }
}
