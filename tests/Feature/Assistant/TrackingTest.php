<?php

namespace Tests\Feature\Assistant;

use App\Actions\Assistant\SettleSessions;
use App\Enums\AssistantOutcome;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    /** @param array<string, mixed> $overrides */
    private function openSession(array $overrides = []): AssistantSession
    {
        return AssistantSession::create(array_merge([
            'conversation_id' => (string) Str::uuid7(),
            'participant_type' => 'assistant_guest',
            'participant_id' => AssistantGuest::create(['last_seen_at' => now()])->id,
            'last_activity_at' => now(),
        ], $overrides));
    }

    public function test_a_question_that_was_answered_and_left_alone_settles_as_answered(): void
    {
        $answered = $this->openSession(['last_agent' => 'knowledge_base', 'turns' => 2, 'last_activity_at' => now()->subHour()]);
        $stillTalking = $this->openSession(['last_agent' => 'knowledge_base', 'turns' => 1, 'last_activity_at' => now()->subMinutes(5)]);

        $this->assertSame(['answered' => 1, 'abandoned' => 0], (new SettleSessions)->handle());

        $this->assertSame(AssistantOutcome::Answered, $answered->fresh()->outcome);
        $this->assertSame(AssistantOutcome::Open, $stillTalking->fresh()->outcome);
    }

    public function test_a_conversation_left_for_a_day_settles_as_abandoned(): void
    {
        $abandoned = $this->openSession(['last_agent' => 'triage', 'turns' => 3, 'last_activity_at' => now()->subDays(2)]);
        $raised = $this->openSession(['outcome' => 'ticket_raised', 'last_activity_at' => now()->subDays(3)]);

        (new SettleSessions)->handle();

        $this->assertSame(AssistantOutcome::Abandoned, $abandoned->fresh()->outcome);
        // An outcome that has been decided is never revisited.
        $this->assertSame(AssistantOutcome::TicketRaised, $raised->fresh()->outcome);
    }

    public function test_the_command_settles_and_reports(): void
    {
        $this->openSession(['last_agent' => 'knowledge_base', 'turns' => 1, 'last_activity_at' => now()->subHour()]);

        $this->artisan('assistant:settle-sessions')->assertSuccessful();

        $this->assertSame(0, AssistantSession::where('outcome', 'open')->count());
    }

    public function test_admins_list_conversations_and_filter_them_by_outcome(): void
    {
        $this->openSession(['outcome' => 'answered']);
        $this->openSession(['outcome' => 'answered']);
        $this->openSession(['outcome' => 'ticket_raised']);
        $this->openSession();

        User::factory()->admin()->create(['email' => 'admin@example.test']);
        User::factory()->moderator()->create(['email' => 'mod@example.test']);
        $admin = $this->login('admin@example.test')['token'];

        $this->asUser($admin)->getJson('/api/assistant/conversations')->assertOk()
            ->assertJsonCount(4, 'data')->assertJsonPath('pagination.totalItems', 4);
        $this->asUser($admin)->getJson('/api/assistant/conversations?outcome=answered')->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($this->login('mod@example.test')['token'])->getJson('/api/assistant/conversations')->assertForbidden();
    }

    public function test_the_metrics_carry_an_assistant_block(): void
    {
        $this->openSession(['outcome' => 'answered']);
        $this->openSession(['outcome' => 'answered']);
        $this->openSession(['outcome' => 'ticket_raised']);
        $this->openSession();

        User::factory()->admin()->create(['email' => 'admin@example.test']);

        $this->asUser($this->login('admin@example.test')['token'])->getJson('/api/metrics')->assertOk()
            ->assertJsonPath('data.assistant.conversations', 4)
            ->assertJsonPath('data.assistant.answered', 2)
            ->assertJsonPath('data.assistant.ticketsRaised', 1)
            // Two of the three settled conversations needed no ticket.
            ->assertJsonPath('data.assistant.deflectionRate', 0.667);
    }

    public function test_the_deflection_rate_is_null_before_anything_has_settled(): void
    {
        $this->openSession();
        User::factory()->admin()->create(['email' => 'admin@example.test']);

        $this->asUser($this->login('admin@example.test')['token'])->getJson('/api/metrics')->assertOk()
            ->assertJsonPath('data.assistant.deflectionRate', null);
    }
}
