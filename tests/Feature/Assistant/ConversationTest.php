<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\Concierge;
use App\Models\AssistantSession;
use App\Models\Category;
use App\Models\User;
use App\Support\AssistantParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_a_guest_starts_a_conversation_and_is_given_an_identity_to_keep(): void
    {
        $data = $this->asGuest()->postJson('/api/assistant/conversations')->assertCreated()->json('data');

        $this->assertNotNull($data['guestId']);
        $this->assertSame('open', $data['session']['outcome']);
        $this->assertNotSame('', $data['greeting']);
        $this->assertDatabaseHas('assistant_sessions', ['id' => $data['session']['id'], 'participant_type' => 'assistant_guest']);
        $this->assertDatabaseHas('agent_conversations', ['id' => AssistantSession::find($data['session']['id'])->conversation_id]);
    }

    public function test_a_signed_in_person_starts_their_own_conversation(): void
    {
        $user = User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        $data = $this->asUser($token)->postJson('/api/assistant/conversations')->assertCreated()->json('data');

        $this->assertNull($data['guestId']);
        $this->assertSame($user->id, $data['session']['participant']['id']);
    }

    public function test_a_turn_returns_the_validated_card_and_moves_the_counters(): void
    {
        User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];
        $category = Category::factory()->create();

        Concierge::fake([
            ['reply' => 'Tell me more.', 'card' => ['type' => 'none']],
            ['reply' => 'Here is a draft.', 'card' => [
                'type' => 'ticket_draft', 'subject' => 'VPN drops hourly', 'description' => 'My VPN drops every hour since Monday.',
                'categoryId' => $category->id, 'priority' => 'high', 'reason' => 'You are blocked.',
            ]],
            ['reply' => 'Bad card.', 'card' => ['type' => 'existing_ticket', 'ticketId' => 'not-a-ticket']],
        ]);

        $session = $this->asUser($token)->postJson('/api/assistant/conversations')->json('data.session');

        $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'My VPN keeps dropping'])
            ->assertOk()->assertJsonPath('data.reply', 'Tell me more.')->assertJsonPath('data.card.type', 'none');

        $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'Every hour since Monday'])
            ->assertOk()->assertJsonPath('data.card.type', 'ticket_draft')->assertJsonPath('data.card.categoryName', $category->name);

        // An identifier the person does not own is downgraded, not shown.
        $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'ok'])
            ->assertOk()->assertJsonPath('data.card.type', 'none');

        $row = AssistantSession::find($session['id']);
        $this->assertSame(3, $row->turns);
        $this->assertSame('ticket_draft', $row->last_draft['type']);
        $this->assertSame('high', $row->last_draft['priority']);
    }

    public function test_the_transcript_shows_what_the_person_saw(): void
    {
        User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        Concierge::fake([['reply' => 'I do not have that.', 'card' => ['type' => 'existing_ticket', 'ticketId' => 'not-a-ticket']]]);

        $session = $this->asUser($token)->postJson('/api/assistant/conversations')->json('data.session');
        $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'What are the hours?'])->assertOk();

        $messages = $this->asUser($token)->getJson("/api/assistant/conversations/{$session['id']}")->assertOk()->json('data.messages');

        $this->assertCount(2, $messages);
        $this->assertSame(['user', 'assistant'], array_column($messages, 'role'));
        $this->assertSame('What are the hours?', $messages[0]['text']);
        $this->assertSame('I do not have that.', $messages[1]['text']);
        // The rejected card must not reappear in the transcript.
        $this->assertSame('none', $messages[1]['card']['type']);
    }

    public function test_a_conversation_is_readable_only_by_its_participant_or_an_admin(): void
    {
        Concierge::fake([['reply' => 'Hello.', 'card' => ['type' => 'none']]]);
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        $id = $guest['session']['id'];

        $this->asGuest()->getJson("/api/assistant/conversations/{$id}")->assertForbidden();
        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])->getJson("/api/assistant/conversations/{$id}")->assertOk();

        User::factory()->admin()->create(['email' => 'admin@example.test']);
        User::factory()->moderator()->create(['email' => 'mod@example.test']);
        $admin = $this->login('admin@example.test')['token'];
        $moderator = $this->login('mod@example.test')['token'];

        $this->asUser($admin)->getJson("/api/assistant/conversations/{$id}")->assertOk();
        $this->asUser($moderator)->getJson("/api/assistant/conversations/{$id}")->assertForbidden();
        // Reading for oversight is not the same as speaking in someone's conversation.
        $this->asUser($admin)->postJson("/api/assistant/conversations/{$id}/messages", ['message' => 'hi'])->assertForbidden();
    }

    public function test_a_provider_failure_is_retried_once_and_then_reported(): void
    {
        $attempts = 0;
        Concierge::fake(function () use (&$attempts): string {
            $attempts++;

            throw new \RuntimeException('the provider is down');
        });

        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');

        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/messages", ['message' => 'hello'])
            ->assertStatus(502)->assertJsonPath('error.code', 'ASSISTANT_UNAVAILABLE');

        $this->assertSame(2, $attempts);
        // A failed turn is not a turn: nothing was said back.
        $this->assertSame(0, AssistantSession::find($guest['session']['id'])->turns);
    }

    public function test_an_empty_message_is_a_validation_error(): void
    {
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');

        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/messages", ['message' => ''])
            ->assertStatus(400)->assertJsonPath('error.details.0.field', 'message');
    }

    public function test_a_settled_conversation_takes_no_further_messages(): void
    {
        Concierge::fake([['reply' => 'Hello.', 'card' => ['type' => 'none']]]);
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        AssistantSession::find($guest['session']['id'])->update(['outcome' => 'abandoned']);

        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/messages", ['message' => 'hello'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    }
}
