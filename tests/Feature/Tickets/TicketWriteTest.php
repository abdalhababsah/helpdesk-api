<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TicketWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $agent;

    private Category $category;

    private string $userToken;

    private string $agentToken;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        $this->requester = User::factory()->create(['email' => 'jordan@example.test']);
        $this->agent = User::factory()->moderator()->create(['email' => 'sam@example.test']);
        User::factory()->admin()->create(['email' => 'admin@example.test']);
        $this->category = Category::factory()->create();

        $this->userToken = $this->login('jordan@example.test')['token'];
        $this->agentToken = $this->login('sam@example.test')['token'];
        $this->adminToken = $this->login('admin@example.test')['token'];
    }

    public function test_creating_a_ticket_records_the_caller_as_the_requester(): void
    {
        $response = $this->asUser($this->agentToken)->postJson('/api/tickets', [
            'subject' => 'VPN will not connect',
            'description' => 'It fails during the handshake step.',
            'categoryId' => $this->category->id,
            'priority' => 'high',
        ])->assertStatus(201);

        // Raising a ticket for someone else would make the "own" scope
        // meaningless, so the requester is never taken from the request body.
        $this->assertSame($this->agent->id, $response->json('data.requester.id'));
        $this->assertSame('open', $response->json('data.status'));
        $this->assertNull($response->json('data.assignee'));

        $ticket = Ticket::findOrFail($response->json('data.id'));
        $this->assertSame(24, (int) round($ticket->created_at->diffInHours($ticket->due_at)));
    }

    public function test_a_ticket_cannot_be_raised_against_a_retired_category(): void
    {
        $retired = Category::factory()->retired()->create();

        $this->asUser($this->agentToken)->postJson('/api/tickets', [
            'subject' => 'Something is broken',
            'description' => 'A description that is long enough.',
            'categoryId' => $retired->id,
        ])->assertStatus(400)->assertJsonPath('error.details.0.field', 'categoryId');
    }

    public function test_creation_reports_which_field_failed(): void
    {
        $this->asUser($this->agentToken)->postJson('/api/tickets', [
            'subject' => 'Hi',
            'description' => 'short',
            'categoryId' => $this->category->id,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonFragment(['field' => 'subject'])
            ->assertJsonFragment(['field' => 'description']);
    }

    public function test_assigning_and_unassigning(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['assigneeId' => $this->agent->id])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $this->agent->id);

        // Null is an instruction, not an omission: it returns it to the queue.
        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['assigneeId' => null])
            ->assertOk()
            ->assertJsonPath('data.assignee', null);
    }

    public function test_a_ticket_cannot_be_assigned_to_someone_who_is_not_an_agent(): void
    {
        $ticket = Ticket::factory()->create();

        // A foreign key can only require that the assignee is a user.
        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['assigneeId' => $this->requester->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_ASSIGNEE');
    }

    public function test_changing_priority_moves_the_deadline(): void
    {
        $ticket = Ticket::factory()->priority(TicketPriority::Low)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'urgent'])
            ->assertOk();

        $ticket->refresh();
        // Otherwise raising a ticket to urgent would leave it due a week out
        // and it would never show as overdue.
        $this->assertSame(4, (int) round($ticket->created_at->diffInHours($ticket->due_at)));
    }

    public function test_resolving_then_reopening_clears_the_resolution_clock(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asUser($this->agentToken)->patchJson("/api/tickets/{$ticket->id}", ['status' => 'resolved'])->assertOk();
        $this->assertNotNull($ticket->fresh()->resolved_at);

        $this->asUser($this->agentToken)->patchJson("/api/tickets/{$ticket->id}", ['status' => 'in_progress'])->assertOk();
        // Leaving it set would count a ticket being worked again as finished.
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_an_illegal_status_move_is_a_conflict_not_a_validation_error(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::Closed)->create();

        // Well formed and permitted; the ticket was simply in the wrong state.
        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => 'open'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_status_and_assignee_can_change_in_one_request(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asUser($this->agentToken)->patchJson("/api/tickets/{$ticket->id}", [
            'status' => 'in_progress',
            'assigneeId' => $this->agent->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.assignee.id', $this->agent->id);
    }

    public function test_an_empty_update_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asUser($this->agentToken)->patchJson("/api/tickets/{$ticket->id}", [])->assertStatus(400);
    }

    public function test_replying_appends_to_the_thread(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();

        $this->asUser($this->userToken)
            ->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Any update on this?'])
            ->assertStatus(201)
            ->assertJsonPath('data.author.id', $this->requester->id)
            ->assertJsonPath('data.author.role', 'user');

        $this->asUser($this->userToken)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()->assertJsonCount(1, 'data.comments');
    }

    public function test_a_closed_ticket_accepts_no_further_replies(): void
    {
        $ticket = Ticket::factory()->status(TicketStatus::Closed)->requestedBy($this->requester)->create();

        $this->asUser($this->userToken)
            ->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'One more thing'])
            ->assertStatus(409);
    }

    public function test_deleting_removes_it_from_the_list_without_losing_the_row(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asUser($this->adminToken)->deleteJson("/api/tickets/{$ticket->id}")->assertStatus(204);

        $this->asUser($this->agentToken)->getJson('/api/tickets')
            ->assertOk()->assertJsonPath('pagination.totalItems', 0);
        $this->asUser($this->agentToken)->getJson("/api/tickets/{$ticket->id}")->assertStatus(404);

        // Kept so the audit entry still resolves to something.
        $this->assertNotNull(Ticket::withTrashed()->find($ticket->id));
    }

    public function test_every_change_is_recorded(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asUser($this->agentToken)->patchJson("/api/tickets/{$ticket->id}", [
            'status' => 'in_progress',
            'priority' => 'urgent',
            'assigneeId' => $this->agent->id,
        ])->assertOk();

        foreach (['ticket.status_changed', 'ticket.priority_changed', 'ticket.assigned'] as $action) {
            $this->assertDatabaseHas('action_logs', [
                'action' => $action,
                'subject_id' => $ticket->id,
                'actor_id' => $this->agent->id,
            ]);
        }
    }
}
