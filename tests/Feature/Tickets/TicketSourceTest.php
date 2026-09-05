<?php

namespace Tests\Feature\Tickets;

use App\Actions\Tickets\CreateTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TicketSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_a_user_can_no_longer_raise_a_ticket_directly(): void
    {
        User::factory()->create(['email' => 'u@example.test']);
        $token = $this->login('u@example.test')['token'];

        $this->asUser($token)->postJson('/api/tickets', [
            'subject' => 'A subject long enough',
            'description' => 'A description that is long enough.',
            'categoryId' => Category::factory()->create()->id,
        ])->assertForbidden();
    }

    public function test_a_moderator_ticket_is_marked_direct(): void
    {
        User::factory()->moderator()->create(['email' => 'm@example.test']);
        $token = $this->login('m@example.test')['token'];

        $this->asUser($token)->postJson('/api/tickets', [
            'subject' => 'A subject long enough',
            'description' => 'A description that is long enough.',
            'categoryId' => Category::factory()->create()->id,
        ])->assertCreated()->assertJsonPath('data.source', 'direct');
    }

    public function test_creating_for_a_requester_stamps_the_source_and_the_conversation(): void
    {
        $requester = User::factory()->create();
        $conversationId = (string) Str::uuid7();

        $ticket = app(CreateTicket::class)->createFor(
            $requester,
            'Raised through the assistant',
            'A description that is long enough.',
            Category::factory()->create()->id,
            TicketPriority::Urgent,
            TicketSource::Assistant,
            $conversationId,
        );

        $this->assertSame('assistant', $ticket->source->value);
        $this->assertSame($conversationId, $ticket->conversation_id);
        $this->assertSame($requester->id, $ticket->requester_id);
        $this->assertDatabaseHas('action_logs', ['action' => 'ticket.created', 'subject_id' => $ticket->id, 'actor_id' => $requester->id]);
    }
}
