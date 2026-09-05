<?php

namespace Tests\Feature\Assistant;

use App\Ai\AssistantContext;
use App\Ai\Tools\FindOpenTickets;
use App\Ai\Tools\ListCategories;
use App\Ai\Tools\SearchKnowledge;
use App\Enums\TicketStatus;
use App\Models\AssistantGuest;
use App\Models\Category;
use App\Models\KnowledgeArticle;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Truncation rather than a wrapping transaction: both search tools use the
 * fulltext index, which InnoDB does not populate until the writing
 * transaction commits.
 */
final class ToolsTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_search_knowledge_returns_matching_active_articles(): void
    {
        KnowledgeArticle::factory()->create(['title' => 'VPN will not connect', 'body' => 'Restart the client.', 'keywords' => 'vpn']);
        KnowledgeArticle::factory()->retired()->create(['title' => 'Old VPN notes', 'body' => 'Retired text about the tunnel.', 'keywords' => 'vpn']);

        $text = (string) (new SearchKnowledge)->handle(new Request(['query' => 'vpn connect']));

        $this->assertStringContainsString('## VPN will not connect', $text);
        $this->assertStringContainsString('Restart the client.', $text);
        $this->assertStringNotContainsString('Old VPN notes', $text);
    }

    public function test_search_knowledge_says_so_when_nothing_matches(): void
    {
        KnowledgeArticle::factory()->create(['title' => 'Payslip missing', 'body' => 'Payslips arrive on the 25th.', 'keywords' => 'payroll']);

        $this->assertSame('No articles match.', (string) (new SearchKnowledge)->handle(new Request(['query' => 'sailing regatta'])));
    }

    public function test_find_open_tickets_only_sees_the_persons_own_unfinished_tickets(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Ticket::factory()->requestedBy($me)->create(['subject' => 'VPN drops every hour', 'description' => 'The tunnel drops hourly.']);
        Ticket::factory()->requestedBy($me)->status(TicketStatus::Resolved)->create(['subject' => 'VPN was slow last week', 'description' => 'The tunnel was slow.']);
        Ticket::factory()->requestedBy($other)->create(['subject' => 'VPN broken for me too', 'description' => 'The tunnel is broken.']);

        $text = (string) (new FindOpenTickets($me))->handle(new Request(['query' => 'vpn drops']));

        $this->assertStringContainsString('VPN drops every hour', $text);
        $this->assertStringNotContainsString('VPN was slow last week', $text);
        $this->assertStringNotContainsString('VPN broken for me too', $text);
    }

    public function test_find_open_tickets_says_so_when_nothing_matches(): void
    {
        $me = User::factory()->create();
        Ticket::factory()->requestedBy($me)->create(['subject' => 'Payslip missing', 'description' => 'No payslip this month.']);

        $this->assertSame('No open tickets match.', (string) (new FindOpenTickets($me))->handle(new Request(['query' => 'sailing regatta'])));
    }

    public function test_list_categories_names_the_active_ones_with_their_ids(): void
    {
        $live = Category::factory()->create(['name' => 'IT - Hardware']);
        Category::factory()->retired()->create(['name' => 'Retired area']);

        $text = (string) (new ListCategories)->handle(new Request([]));

        $this->assertStringContainsString("id={$live->id} | name=IT - Hardware", $text);
        $this->assertStringNotContainsString('Retired area', $text);
    }

    public function test_the_context_describes_a_signed_in_person(): void
    {
        $category = Category::factory()->create(['name' => 'IT - Hardware']);
        $me = User::factory()->create();
        Ticket::factory()->requestedBy($me)->count(2)->create(['category_id' => $category->id]);
        Ticket::factory()->requestedBy($me)->status(TicketStatus::Resolved)->create(['category_id' => $category->id]);

        $context = AssistantContext::for($me);

        $this->assertFalse($context->isGuest());
        $this->assertSame($me->id, $context->user()?->id);
        $this->assertSame(2, $context->openTicketCount);
        $this->assertSame([['id' => $category->id, 'name' => 'IT - Hardware']], $context->categories);
    }

    public function test_the_context_describes_a_guest(): void
    {
        $context = AssistantContext::for(AssistantGuest::create(['last_seen_at' => now()]));

        $this->assertTrue($context->isGuest());
        $this->assertNull($context->user());
        $this->assertSame(0, $context->openTicketCount);
    }
}
