<?php

namespace Tests\Unit;

use App\Enums\TicketStatus;
use PHPUnit\Framework\TestCase;

final class TicketStatusTest extends TestCase
{
    public function test_the_declared_order_is_the_lifecycle_order(): void
    {
        // Written to a MySQL ENUM, which sorts by declaration ordinal. Reordering
        // these silently changes what "sort by status" returns.
        $this->assertSame(
            ['open', 'in_progress', 'resolved', 'closed'],
            array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::cases()),
        );
    }

    public function test_allowed_moves(): void
    {
        $this->assertTrue(TicketStatus::Open->canTransitionTo(TicketStatus::InProgress));
        $this->assertTrue(TicketStatus::Open->canTransitionTo(TicketStatus::Resolved));
        $this->assertTrue(TicketStatus::InProgress->canTransitionTo(TicketStatus::Closed));
        $this->assertTrue(TicketStatus::Resolved->canTransitionTo(TicketStatus::InProgress));
    }

    public function test_closed_is_final(): void
    {
        foreach (TicketStatus::cases() as $target) {
            $this->assertFalse(
                TicketStatus::Closed->canTransitionTo($target),
                "closed should not move to {$target->value}",
            );
        }

        $this->assertTrue(TicketStatus::Closed->isTerminal());
        $this->assertFalse(TicketStatus::Resolved->isTerminal());
    }

    public function test_a_status_cannot_move_to_itself(): void
    {
        foreach (TicketStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status), "{$status->value} to itself");
        }
    }

    public function test_which_states_stop_the_resolution_clock(): void
    {
        $this->assertTrue(TicketStatus::Resolved->isFinished());
        $this->assertTrue(TicketStatus::Closed->isFinished());
        $this->assertFalse(TicketStatus::Open->isFinished());
        $this->assertFalse(TicketStatus::InProgress->isFinished());
    }

    public function test_only_unfinished_states_can_be_overdue(): void
    {
        $this->assertSame([TicketStatus::Open, TicketStatus::InProgress], TicketStatus::open());
    }
}
