<?php

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TicketValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $this->token = $this->login('sam@example.test')['token'];
    }

    private function index(string $query = '')
    {
        return $this->asUser($this->token)->getJson('/api/tickets?'.$query);
    }

    public function test_the_page_size_ceiling_is_enforced(): void
    {
        // Without a ceiling a client can request the whole table and turn a
        // paginated endpoint into an unbounded one.
        $this->index('limit=1000')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'limit');

        $this->index('limit=100')->assertOk();
    }

    public function test_an_unknown_query_parameter_is_rejected(): void
    {
        // Ignoring it would return an unfiltered page that looks correct, so a
        // misspelled filter would silently show more than intended.
        $this->index('statuss=open')
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.field', 'statuss');
    }

    public function test_an_unknown_category_slug_is_rejected_rather_than_returning_nothing(): void
    {
        Category::factory()->create(['slug' => 'it-hardware']);

        $this->index('category=nope')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->index('category=it-hardware')->assertOk();
    }

    public function test_invalid_enum_values_are_rejected(): void
    {
        $this->index('status=banana')->assertStatus(400);
        $this->index('priority=critical')->assertStatus(400);
        $this->index('sortBy=password')->assertStatus(400);
        $this->index('sortDir=sideways')->assertStatus(400);
    }

    public function test_a_malformed_assignee_is_rejected_without_disclosing_who_exists(): void
    {
        $this->index('assignee=not-an-id')->assertStatus(400);

        // A well-formed identifier for nobody returns an empty page rather than
        // an error, so the filter cannot be used to test for an account.
        $this->index('assignee=01aaaaaaaaaaaaaaaaaaaaaaaa')
            ->assertOk()
            ->assertJsonPath('pagination.totalItems', 0);
    }

    public function test_page_and_limit_must_be_positive(): void
    {
        $this->index('page=0')->assertStatus(400);
        $this->index('limit=0')->assertStatus(400);
        $this->index('page=-3')->assertStatus(400);
    }

    public function test_search_is_length_capped(): void
    {
        $this->index('search='.str_repeat('a', 101))->assertStatus(400);
    }

    public function test_defaults_apply_when_nothing_is_supplied(): void
    {
        Ticket::factory()->count(3)->create();

        $this->index()
            ->assertOk()
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.limit', config('tickets.pagination.default_limit'));
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/tickets')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }
}
