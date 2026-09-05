<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class TicketIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    private User $requester;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        $this->moderator = User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $this->requester = User::factory()->create(['email' => 'jordan@example.test']);
        $this->token = $this->login('sam@example.test')['token'];
    }

    private function index(array $query = [])
    {
        return $this->asUser($this->token)->getJson('/api/tickets?'.http_build_query($query));
    }

    public function test_it_returns_the_documented_envelope(): void
    {
        Ticket::factory()->count(3)->requestedBy($this->requester)->create();

        $this->index()
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'subject', 'status', 'priority', 'category' => ['id', 'slug', 'name'],
                    'requester' => ['id', 'name'], 'assignee', 'dueAt', 'isOverdue', 'commentCount',
                    'createdAt', 'updatedAt']],
                'pagination' => ['page', 'limit', 'totalItems', 'totalPages'],
            ])
            ->assertJsonPath('pagination.totalItems', 3);
    }

    public function test_the_list_omits_the_description(): void
    {
        Ticket::factory()->requestedBy($this->requester)->create();

        $this->assertArrayNotHasKey('description', $this->index()->json('data.0'));
    }

    public function test_every_page_is_walked_without_a_duplicate_or_a_gap(): void
    {
        // All created at the same instant, so every row ties on the sort column.
        // Without a unique tiebreaker MySQL may order ties differently per
        // query, and rows then appear twice or not at all across pages.
        $moment = Carbon::parse('2026-04-01 12:00:00');
        Ticket::factory()->count(55)->createdAt($moment)->requestedBy($this->requester)->create();

        $seen = [];
        for ($page = 1; $page <= 6; $page++) {
            $response = $this->index(['page' => $page, 'limit' => 10])->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
        }

        $this->assertCount(55, $seen, 'a row was skipped or repeated across pages');
        $this->assertSame(55, count(array_unique($seen)));
        $this->assertEqualsCanonicalizing(Ticket::pluck('id')->all(), $seen);
    }

    public function test_the_total_reflects_the_same_predicate_as_the_page(): void
    {
        Ticket::factory()->count(30)->requestedBy($this->requester)->create();

        $response = $this->index(['limit' => 10])->assertOk();

        $this->assertCount(10, $response->json('data'));
        $this->assertSame(30, $response->json('pagination.totalItems'));
        $this->assertSame(3, $response->json('pagination.totalPages'));
    }

    public function test_a_page_past_the_end_is_empty_rather_than_missing(): void
    {
        Ticket::factory()->count(3)->requestedBy($this->requester)->create();

        // A valid view of a collection that happens to hold nothing.
        $this->index(['page' => 99])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_soft_deleted_tickets_are_excluded_from_both_rows_and_total(): void
    {
        Ticket::factory()->count(4)->requestedBy($this->requester)->create();
        Ticket::factory()->count(2)->trashed()->requestedBy($this->requester)->create();

        $this->index()->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('pagination.totalItems', 4);
    }

    public function test_a_user_sees_only_their_own_tickets_in_rows_and_total(): void
    {
        Ticket::factory()->count(3)->requestedBy($this->requester)->create();
        Ticket::factory()->count(7)->create();

        $token = $this->login('jordan@example.test')['token'];
        $response = $this->asUser($token)->getJson('/api/tickets')->assertOk();

        $this->assertCount(3, $response->json('data'));
        // Reporting 10 here would leak the size of the whole system.
        $this->assertSame(3, $response->json('pagination.totalItems'));
    }

    public function test_filters_cannot_widen_what_a_user_can_see(): void
    {
        $mine = Ticket::factory()->requestedBy($this->requester)->assignedTo(null)->create();
        Ticket::factory()->count(5)->assignedTo(null)->create();

        $token = $this->login('jordan@example.test')['token'];
        $response = $this->asUser($token)->getJson('/api/tickets?assignee=unassigned')->assertOk();

        // Scoping is composed into the query, so a filter narrows the visible
        // set and can never reach past it.
        $this->assertSame(1, $response->json('pagination.totalItems'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_it_filters_by_status_and_priority_as_multi_selects(): void
    {
        Ticket::factory()->count(2)->status(TicketStatus::Open)->priority(TicketPriority::Low)->create();
        Ticket::factory()->count(3)->status(TicketStatus::InProgress)->priority(TicketPriority::Urgent)->create();
        Ticket::factory()->count(4)->status(TicketStatus::Closed)->priority(TicketPriority::High)->create();

        $this->index(['status' => 'open,in_progress'])->assertJsonPath('pagination.totalItems', 5);
        $this->index(['priority' => 'urgent,high'])->assertJsonPath('pagination.totalItems', 7);
        $this->index(['status' => 'open,in_progress', 'priority' => 'urgent'])
            ->assertJsonPath('pagination.totalItems', 3);
    }

    public function test_it_filters_by_category_slug(): void
    {
        $hardware = Category::factory()->create(['slug' => 'it-hardware']);
        $payroll = Category::factory()->create(['slug' => 'hr-payroll']);

        Ticket::factory()->count(2)->create(['category_id' => $hardware->id]);
        Ticket::factory()->count(5)->create(['category_id' => $payroll->id]);

        $this->index(['category' => 'it-hardware'])->assertJsonPath('pagination.totalItems', 2);
        $this->index(['category' => 'it-hardware,hr-payroll'])->assertJsonPath('pagination.totalItems', 7);
    }

    public function test_it_filters_by_assignee_including_me_and_unassigned(): void
    {
        $other = User::factory()->moderator()->create();

        Ticket::factory()->count(2)->assignedTo($this->moderator)->create();
        Ticket::factory()->count(3)->assignedTo($other)->create();
        Ticket::factory()->count(4)->assignedTo(null)->create();

        $this->index(['assignee' => 'me'])->assertJsonPath('pagination.totalItems', 2);
        $this->index(['assignee' => 'unassigned'])->assertJsonPath('pagination.totalItems', 4);
        $this->index(['assignee' => $other->id])->assertJsonPath('pagination.totalItems', 3);
    }

    public function test_it_filters_overdue_both_ways(): void
    {
        Ticket::factory()->count(3)->overdue()->create();
        Ticket::factory()->count(5)->create();
        // Late but finished. Not overdue: the clock stopped when it resolved.
        Ticket::factory()->count(2)->status(TicketStatus::Resolved)->create(['due_at' => now()->subWeek()]);

        $this->index(['overdue' => 'true'])->assertJsonPath('pagination.totalItems', 3);
        $this->index(['overdue' => 'false'])->assertJsonPath('pagination.totalItems', 7);
    }

    public function test_it_sorts_by_priority_using_the_enum_order(): void
    {
        Ticket::factory()->priority(TicketPriority::Low)->create();
        Ticket::factory()->priority(TicketPriority::Urgent)->create();
        Ticket::factory()->priority(TicketPriority::Medium)->create();
        Ticket::factory()->priority(TicketPriority::High)->create();

        // MySQL sorts ENUM by declaration ordinal, which is why the enum is
        // declared in ascending severity order and must not be reordered.
        $descending = array_column($this->index(['sortBy' => 'priority', 'sortDir' => 'desc'])->json('data'), 'priority');
        $this->assertSame(['urgent', 'high', 'medium', 'low'], $descending);

        $ascending = array_column($this->index(['sortBy' => 'priority', 'sortDir' => 'asc'])->json('data'), 'priority');
        $this->assertSame(['low', 'medium', 'high', 'urgent'], $ascending);
    }

    public function test_it_sorts_by_creation_date_in_both_directions(): void
    {
        $old = Ticket::factory()->createdAt(Carbon::parse('2026-01-01 09:00:00'))->create();
        $new = Ticket::factory()->createdAt(Carbon::parse('2026-06-01 09:00:00'))->create();

        $this->assertSame($new->id, $this->index()->json('data.0.id'));
        $this->assertSame($old->id, $this->index(['sortDir' => 'asc'])->json('data.0.id'));
    }
}
