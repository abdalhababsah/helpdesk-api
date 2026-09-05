<?php

namespace Tests\Unit;

use App\Enums\TicketPriority;
use PHPUnit\Framework\TestCase;

final class TicketPriorityTest extends TestCase
{
    public function test_the_declared_order_runs_from_least_to_most_urgent(): void
    {
        // Sorting by priority relies on this order being the MySQL ENUM order.
        $this->assertSame(
            ['low', 'medium', 'high', 'urgent'],
            array_map(fn (TicketPriority $p): string => $p->value, TicketPriority::cases()),
        );
    }

    public function test_the_deadlines(): void
    {
        $this->assertSame(4, TicketPriority::Urgent->slaHours());
        $this->assertSame(24, TicketPriority::High->slaHours());
        $this->assertSame(72, TicketPriority::Medium->slaHours());
        $this->assertSame(168, TicketPriority::Low->slaHours());
    }

    public function test_a_more_urgent_priority_always_has_a_shorter_deadline(): void
    {
        $hours = array_map(fn (TicketPriority $p): int => $p->slaHours(), TicketPriority::cases());

        $sorted = $hours;
        rsort($sorted);
        $this->assertSame($sorted, $hours, 'deadlines should shorten as priority rises');
    }
}
