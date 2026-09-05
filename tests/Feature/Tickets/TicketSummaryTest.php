<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TicketSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private string $agentToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        $this->agent = User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $this->agentToken = $this->login('sam@example.test')['token'];
    }

    public function test_it_counts_the_queue(): void
    {
        $other = User::factory()->moderator()->create();

        Ticket::factory()->count(4)->assignedTo(null)->create();
        Ticket::factory()->count(2)->assignedTo($this->agent)->create();
        Ticket::factory()->count(3)->assignedTo($other)->create();
        Ticket::factory()->count(5)->overdue()->create();
        Ticket::factory()->count(2)->status(TicketStatus::Resolved)->create();

        $this->asUser($this->agentToken)->getJson('/api/tickets/summary')
            ->assertOk()
            ->assertJsonPath('data.unassigned', 9)
            ->assertJsonPath('data.assignedToMe', 2)
            ->assertJsonPath('data.overdue', 5)
            ->assertJsonPath('data.resolvedToday', 2);
    }

    public function test_finished_work_is_not_counted_as_waiting(): void
    {
        // A resolved ticket with nobody on it is history, not something sitting
        // in the queue needing an owner.
        Ticket::factory()->count(3)->status(TicketStatus::Resolved)->assignedTo(null)->create();
        Ticket::factory()->count(2)->status(TicketStatus::Closed)->assignedTo(null)->create();
        Ticket::factory()->assignedTo(null)->create();

        $this->asUser($this->agentToken)->getJson('/api/tickets/summary')
            ->assertOk()
            ->assertJsonPath('data.unassigned', 1);
    }

    public function test_a_late_but_resolved_ticket_is_not_overdue(): void
    {
        Ticket::factory()->count(2)->status(TicketStatus::Resolved)->create(['due_at' => now()->subWeek()]);
        Ticket::factory()->overdue()->create();

        // The clock stops when the work is done, however late it was.
        $this->asUser($this->agentToken)->getJson('/api/tickets/summary')
            ->assertOk()->assertJsonPath('data.overdue', 1);
    }

    public function test_soft_deleted_tickets_are_not_counted(): void
    {
        Ticket::factory()->count(3)->assignedTo(null)->create();
        Ticket::factory()->count(2)->trashed()->assignedTo(null)->create();

        $this->asUser($this->agentToken)->getJson('/api/tickets/summary')
            ->assertOk()->assertJsonPath('data.unassigned', 3);
    }

    public function test_an_empty_queue_reports_zeros_rather_than_nothing(): void
    {
        $this->asUser($this->agentToken)->getJson('/api/tickets/summary')
            ->assertOk()
            ->assertJsonPath('data.unassigned', 0)
            ->assertJsonPath('data.assignedToMe', 0)
            ->assertJsonPath('data.overdue', 0)
            ->assertJsonPath('data.resolvedToday', 0);
    }
}
