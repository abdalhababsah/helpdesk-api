<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\Concierge;
use App\Models\AssistantSession;
use App\Models\User;
use App\Support\AssistantParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        Concierge::fake([['reply' => 'You need to sign in first.', 'card' => ['type' => 'sign_in_required']]]);
    }

    public function test_a_guest_conversation_follows_the_person_after_they_sign_in(): void
    {
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        $id = $guest['session']['id'];

        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$id}/messages", ['message' => 'I need a ticket'])
            ->assertOk()->assertJsonPath('data.card.type', 'sign_in_required');

        $user = User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        $this->asUser($token)->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$id}/claim")
            ->assertOk()
            ->assertJsonPath('data.participant.type', 'user')
            ->assertJsonPath('data.participant.id', $user->id);

        $conversationId = AssistantSession::find($id)->conversation_id;
        $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId, 'participant_id' => $user->id]);
        $this->assertDatabaseHas('action_logs', ['action' => 'assistant.conversation_claimed', 'actor_id' => $user->id]);

        // What was said before signing in is still there.
        $this->asUser($token)->getJson("/api/assistant/conversations/{$id}")->assertOk()->assertJsonCount(2, 'data.messages');
    }

    public function test_only_the_guest_who_was_talking_can_hand_the_conversation_over(): void
    {
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        $stranger = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        $id = $guest['session']['id'];

        User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        $this->asUser($token)->postJson("/api/assistant/conversations/{$id}/claim")->assertForbidden();
        $this->asUser($token)->withHeader(AssistantParticipant::HEADER, $stranger['guestId'])
            ->postJson("/api/assistant/conversations/{$id}/claim")->assertForbidden();
        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$id}/claim")->assertForbidden();
    }

    public function test_a_settled_conversation_cannot_be_claimed(): void
    {
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        AssistantSession::find($guest['session']['id'])->update(['outcome' => 'abandoned']);

        User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        $this->asUser($token)->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/claim")
            ->assertStatus(409);
    }
}
