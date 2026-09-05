<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\Concierge;
use App\Ai\Agents\KnowledgeAgent;
use App\Ai\Agents\TriageAgent;
use App\Ai\AssistantContext;
use App\Ai\Cards\CardValidator;
use App\Enums\TicketStatus;
use App\Models\AssistantGuest;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\AgentTool;
use Tests\TestCase;

final class AgentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_a_guest_concierge_cannot_reach_triage(): void
    {
        $guest = AssistantGuest::create(['last_seen_at' => now()]);

        $tools = iterator_to_array((new Concierge(AssistantContext::for($guest)))->tools());

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(KnowledgeAgent::class, $tools[0]);
    }

    public function test_a_signed_in_concierge_has_knowledge_and_triage(): void
    {
        $tools = iterator_to_array((new Concierge(AssistantContext::for(User::factory()->create())))->tools());

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(KnowledgeAgent::class, $tools[0]);
        $this->assertInstanceOf(TriageAgent::class, $tools[1]);
        $this->assertSame('knowledge_base', (new AgentTool($tools[0]))->name());
        $this->assertSame('triage', (new AgentTool($tools[1]))->name());
    }

    public function test_the_concierge_returns_a_reply_and_a_card(): void
    {
        Concierge::fake([['reply' => 'Hello there.', 'card' => ['type' => 'none']]]);

        $response = (new Concierge(AssistantContext::for(User::factory()->create())))->prompt('Hi');

        $this->assertSame('Hello there.', $response['reply']);
        $this->assertSame('none', $response['card']['type']);
    }

    public function test_the_instructions_tell_a_guest_they_cannot_raise_a_ticket(): void
    {
        $guest = AssistantGuest::create(['last_seen_at' => now()]);
        $person = User::factory()->create(['name' => 'Jordan Employee']);

        $guestInstructions = (string) (new Concierge(AssistantContext::for($guest)))->instructions();
        $userInstructions = (string) (new Concierge(AssistantContext::for($person)))->instructions();

        $this->assertStringContainsString('sign_in_required', $guestInstructions);
        $this->assertStringNotContainsString('Jordan Employee', $guestInstructions);
        $this->assertStringContainsString('Jordan Employee', $userInstructions);
    }

    public function test_the_card_schema_serialises_with_all_four_card_types(): void
    {
        // A schema that cannot be serialised fails only against the real
        // provider, where the failure looks like an outage rather than a bug.
        $schema = (new Concierge(AssistantContext::for(User::factory()->create())))->schema(new JsonSchemaTypeFactory);

        $encoded = json_encode(['reply' => $schema['reply']->toArray(), 'card' => $schema['card']->toArray()]);

        $this->assertIsString($encoded);
        foreach (['none', 'sign_in_required', 'existing_ticket', 'ticket_draft'] as $type) {
            $this->assertStringContainsString($type, $encoded);
        }
    }

    public function test_the_validator_accepts_a_draft_and_names_its_category(): void
    {
        $me = User::factory()->create();
        $category = Category::factory()->create();
        $draft = [
            'type' => 'ticket_draft',
            'subject' => 'VPN drops hourly',
            'description' => 'My VPN drops every hour since Monday.',
            'categoryId' => $category->id,
            'priority' => 'high',
            'reason' => 'You are blocked on a deadline.',
        ];

        $card = (new CardValidator)->validate($draft, $me);

        $this->assertSame('ticket_draft', $card['type']);
        $this->assertSame($category->name, $card['categoryName']);
        $this->assertSame('high', $card['priority']);
    }

    public function test_the_validator_rejects_a_draft_it_cannot_prove(): void
    {
        $me = User::factory()->create();
        $category = Category::factory()->create();
        $retired = Category::factory()->retired()->create();
        $draft = [
            'type' => 'ticket_draft',
            'subject' => 'VPN drops hourly',
            'description' => 'My VPN drops every hour since Monday.',
            'categoryId' => $category->id,
            'priority' => 'high',
            'reason' => 'Blocked.',
        ];
        $validator = new CardValidator;

        $this->assertSame('none', $validator->validate([...$draft, 'priority' => 'sky-high'], $me)['type']);
        $this->assertSame('none', $validator->validate([...$draft, 'categoryId' => $retired->id], $me)['type']);
        $this->assertSame('none', $validator->validate([...$draft, 'subject' => 'VPN'], $me)['type']);
        $this->assertSame('none', $validator->validate($draft, null)['type']);
    }

    public function test_the_validator_only_points_at_the_persons_own_unfinished_ticket(): void
    {
        $me = User::factory()->create();
        $mine = Ticket::factory()->requestedBy($me)->create();
        $resolved = Ticket::factory()->requestedBy($me)->status(TicketStatus::Resolved)->create();
        $theirs = Ticket::factory()->create();
        $validator = new CardValidator;

        $accepted = $validator->validate(['type' => 'existing_ticket', 'ticketId' => $mine->id], $me);
        $this->assertSame('existing_ticket', $accepted['type']);
        $this->assertSame($mine->subject, $accepted['subject']);
        $this->assertSame('open', $accepted['status']);

        $this->assertSame('none', $validator->validate(['type' => 'existing_ticket', 'ticketId' => $resolved->id], $me)['type']);
        $this->assertSame('none', $validator->validate(['type' => 'existing_ticket', 'ticketId' => $theirs->id], $me)['type']);
        $this->assertSame('none', $validator->validate(['type' => 'existing_ticket', 'ticketId' => 'nonsense'], $me)['type']);
    }

    public function test_the_validator_offers_sign_in_only_to_a_guest(): void
    {
        $validator = new CardValidator;

        $this->assertSame('sign_in_required', $validator->validate(['type' => 'sign_in_required'], null)['type']);
        $this->assertSame('none', $validator->validate(['type' => 'sign_in_required'], User::factory()->create())['type']);
        $this->assertSame('none', $validator->validate(['type' => 'banana'], null)['type']);
        $this->assertSame('none', $validator->validate([], null)['type']);
    }
}
