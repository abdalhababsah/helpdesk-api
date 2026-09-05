<?php

namespace Tests\Feature\Assistant;

use App\Actions\Assistant\RaiseTicketFromConversation;
use App\Ai\Agents\Concierge;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RaiseTicketTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Category $category;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        User::factory()->create(['email' => 'me@example.test']);
        $this->token = $this->login('me@example.test')['token'];
        $this->category = Category::factory()->create();

        Concierge::fake([['reply' => 'Draft ready.', 'card' => [
            'type' => 'ticket_draft', 'subject' => 'VPN drops hourly', 'description' => 'My VPN drops every hour since Monday.',
            'categoryId' => $this->category->id, 'priority' => 'urgent', 'reason' => 'You cannot work.',
        ]]]);

        $this->sessionId = $this->asUser($this->token)->postJson('/api/assistant/conversations')->json('data.session.id');
        $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/messages", ['message' => 'VPN drops'])->assertOk();
    }

    public function test_confirming_the_draft_creates_the_ticket_with_the_drafted_priority(): void
    {
        $data = $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", [
            'subject' => 'VPN drops every hour',
            'description' => 'Edited by me: it drops hourly since Monday.',
            'categoryId' => $this->category->id,
            // The client cannot choose the priority; the drafted one stands.
            'priority' => 'low',
        ])->assertCreated()->json('data');

        $this->assertSame('urgent', $data['ticket']['priority']);
        $this->assertSame('assistant', $data['ticket']['source']);
        $this->assertSame('VPN drops every hour', $data['ticket']['subject']);
        $this->assertSame('ticket_raised', $data['session']['outcome']);
        $this->assertSame(RaiseTicketFromConversation::CONFIRMATION, $data['message']);
        $this->assertDatabaseHas('action_logs', ['action' => 'ticket.created', 'subject_id' => $data['ticket']['id']]);
    }

    public function test_the_confirmation_is_appended_and_the_conversation_closes(): void
    {
        $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", [
            'subject' => 'VPN drops every hour', 'description' => 'It drops hourly since Monday.', 'categoryId' => $this->category->id,
        ])->assertCreated();

        $messages = $this->asUser($this->token)->getJson("/api/assistant/conversations/{$this->sessionId}")->json('data.messages');
        $this->assertSame(RaiseTicketFromConversation::CONFIRMATION, end($messages)['text']);

        $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/messages", ['message' => 'thanks'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_a_moderator_sees_the_transcript_and_the_requester_does_not(): void
    {
        $ticketId = $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", [
            'subject' => 'VPN drops every hour', 'description' => 'It drops hourly since Monday.', 'categoryId' => $this->category->id,
        ])->json('data.ticket.id');

        User::factory()->moderator()->create(['email' => 'mod@example.test']);
        $moderator = $this->login('mod@example.test')['token'];

        $this->asUser($moderator)->getJson("/api/tickets/{$ticketId}")->assertOk()
            ->assertJsonPath('data.source', 'assistant')
            ->assertJsonPath('data.transcript.0.role', 'user')
            ->assertJsonPath('data.transcript.0.text', 'VPN drops');

        // The requester was there for the conversation and does not need it back.
        $this->asUser($this->token)->getJson("/api/tickets/{$ticketId}")->assertOk()
            ->assertJsonPath('data.source', 'assistant')
            ->assertJsonMissingPath('data.transcript');
    }

    public function test_a_directly_raised_ticket_carries_no_transcript(): void
    {
        User::factory()->moderator()->create(['email' => 'mod@example.test']);
        $moderator = $this->login('mod@example.test')['token'];

        $ticketId = $this->asUser($moderator)->postJson('/api/tickets', [
            'subject' => 'Raised at the desk', 'description' => 'Someone walked up and asked.', 'categoryId' => $this->category->id,
        ])->json('data.id');

        $this->asUser($moderator)->getJson("/api/tickets/{$ticketId}")->assertOk()
            ->assertJsonPath('data.source', 'direct')
            ->assertJsonMissingPath('data.transcript');
    }

    public function test_nobody_else_can_raise_from_my_conversation(): void
    {
        User::factory()->create(['email' => 'other@example.test']);
        $other = $this->login('other@example.test')['token'];
        $body = ['subject' => 'Anything here', 'description' => 'A description long enough.', 'categoryId' => $this->category->id];

        $this->asUser($other)->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", $body)->assertForbidden();
        $this->asGuest()->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", $body)->assertForbidden();
        $this->assertDatabaseCount('tickets', 0);
    }
}
