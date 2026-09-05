<?php

namespace Tests\Feature\Assistant;

use App\Models\Category;
use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Calls Anthropic for real, so it is off unless ASSISTANT_LIVE_TESTS=1 and a
 * key is configured. Everything else about the assistant is proved with fakes;
 * this exists to catch the things a fake cannot, such as a schema the provider
 * rejects or instructions that no longer produce the shape we expect.
 */
final class LiveAssistantTest extends TestCase
{
    use DatabaseTruncation;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('ASSISTANT_LIVE_TESTS') !== '1' || blank(config('ai.providers.anthropic.key'))) {
            $this->markTestSkipped('Live assistant tests are off. Set ASSISTANT_LIVE_TESTS=1 and ANTHROPIC_API_KEY to run them.');
        }

        $this->seedAuthorization();
        Category::factory()->create(['name' => 'IT - Access & VPN']);
        KnowledgeArticle::factory()->create([
            'title' => 'Support hours',
            'body' => 'The desk is staffed Sunday to Thursday, 8:00 to 17:00.',
            'keywords' => 'hours, when, open',
        ]);

        User::factory()->create(['email' => 'live@example.test']);
        $this->token = $this->login('live@example.test')['token'];
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_a_real_turn_answers_from_the_knowledge_base(): void
    {
        $session = $this->asUser($this->token)->postJson('/api/assistant/conversations')->json('data.session');

        $body = $this->asUser($this->token)
            ->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'What are the support hours?'])
            ->assertOk()->json('data');

        $this->assertMatchesRegularExpression('/Sunday|Thursday|8|17/', $body['reply']);
        $this->assertSame('none', $body['card']['type']);
    }

    public function test_a_real_problem_produces_a_draft_card(): void
    {
        $session = $this->asUser($this->token)->postJson('/api/assistant/conversations')->json('data.session');

        $reply = $this->asUser($this->token)->postJson("/api/assistant/conversations/{$session['id']}/messages", [
            'message' => 'My VPN has dropped every hour since Monday and I cannot join any calls. I need someone to look at it.',
        ])->assertOk()->json('data');

        $this->assertContains($reply['card']['type'], ['ticket_draft', 'none'], 'Expected a draft or a follow-up question.');

        if ($reply['card']['type'] === 'ticket_draft') {
            $this->assertNotSame('', $reply['card']['subject']);
            $this->assertContains($reply['card']['priority'], ['low', 'medium', 'high', 'urgent']);
        }
    }
}
