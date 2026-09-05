<?php

namespace App\Enums;

/**
 * Sortable columns, as a closed set.
 *
 * The client names a case, never a column, so a sort parameter can never reach
 * SQL as text. The mapping also keeps the public camelCase contract separate
 * from the snake_case schema.
 */
enum TicketSort: string
{
    case CreatedAt = 'createdAt';
    case UpdatedAt = 'updatedAt';
    case Priority = 'priority';
    case Status = 'status';
    case DueAt = 'dueAt';
    case Subject = 'subject';

    public function column(): string
    {
        return match ($this) {
            self::CreatedAt => 'created_at',
            self::UpdatedAt => 'updated_at',
            self::Priority => 'priority',
            self::Status => 'status',
            self::DueAt => 'due_at',
            self::Subject => 'subject',
        };
    }
}
