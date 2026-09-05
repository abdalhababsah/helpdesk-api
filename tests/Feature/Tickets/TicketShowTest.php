<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TicketShowTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        $this->requester = User::factory()->create(['email' => 'jordan@example.test']);
        User::factory()->moderator()->create(['email' => 'sam@example.test']);
    }

    public function test_a_requester_reads_their_own_ticket_with_the_description_and_thread(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();
        TicketComment::factory()->count(2)->on($ticket)->by($this->requester)->create();

        $token = $this->login('jordan@example.test')['token'];

        $this->asUser($token)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.description', $ticket->description)
            ->assertJsonCount(2, 'data.comments')
            ->assertJsonStructure(['data' => ['comments' => [['id', 'body', 'author' => ['id', 'name', 'role'], 'createdAt']]]]);
    }

    public function test_a_user_reading_someone_elses_ticket_is_refused(): void
    {
        $other = Ticket::factory()->create();
        $token = $this->login('jordan@example.test')['token'];

        // The brief asks for 403 here rather than 404. Identifiers are ULIDs,
        // so confirming existence gives away nothing enumerable.
        $this->asUser($token)->getJson("/api/tickets/{$other->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_a_moderator_reads_any_ticket(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();
        $token = $this->login('sam@example.test')['token'];

        $this->asUser($token)->getJson("/api/tickets/{$ticket->id}")->assertOk();
    }

    public function test_a_missing_ticket_is_not_found(): void
    {
        $token = $this->login('sam@example.test')['token'];

        $this->asUser($token)->getJson('/api/tickets/'.strtolower((string) Str::ulid()))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_a_soft_deleted_ticket_is_not_found(): void
    {
        $ticket = Ticket::factory()->trashed()->create();
        $token = $this->login('sam@example.test')['token'];

        $this->asUser($token)->getJson("/api/tickets/{$ticket->id}")->assertStatus(404);
    }

    public function test_a_refused_read_is_recorded(): void
    {
        $other = Ticket::factory()->create();
        $token = $this->login('jordan@example.test')['token'];

        $this->asUser($token)->getJson("/api/tickets/{$other->id}")->assertStatus(403);

        $this->assertDatabaseHas('action_logs', [
            'action' => 'authz.denied',
            'actor_id' => $this->requester->id,
        ]);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $ticket = Ticket::factory()->create();

        $this->getJson("/api/tickets/{$ticket->id}")->assertStatus(401);
    }
}
