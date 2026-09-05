<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Truncation rather than a wrapping transaction.
 *
 * InnoDB does not add a row to its fulltext index until the transaction that
 * wrote it commits, so a MATCH inside an open transaction cannot see rows that
 * transaction just inserted. Under RefreshDatabase every fulltext assertion
 * would fail while the feature works in production, which is the worst kind of
 * red test.
 */
final class TicketSearchTest extends TestCase
{
    use DatabaseTruncation;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $this->token = $this->login('sam@example.test')['token'];

        Ticket::factory()->create([
            'subject' => 'VPN will not connect from home',
            'description' => 'The tunnel fails during the handshake step.',
        ]);
        Ticket::factory()->create([
            'subject' => 'Laptop battery not charging',
            'description' => 'Charger connected but the battery stays at zero.',
        ]);
        Ticket::factory()->create([
            'subject' => 'Payslip missing overtime',
            'description' => 'Twelve approved hours are absent from the payslip.',
        ]);
    }

    /**
     * Truncation commits and, unlike a wrapping transaction, does not undo
     * itself. Without this the rows survive into tests that expect an empty
     * database, and they fail on a unique constraint rather than on anything
     * they were written to check.
     */
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    private function search(string $term)
    {
        return $this->asUser($this->token)->getJson('/api/tickets?search='.urlencode($term));
    }

    public function test_it_matches_words_in_the_subject(): void
    {
        $this->search('battery')->assertOk()->assertJsonPath('pagination.totalItems', 1);
    }

    public function test_it_matches_words_only_present_in_the_description(): void
    {
        // The description is not returned in the list, but it is searched.
        $this->search('handshake')->assertOk()
            ->assertJsonPath('pagination.totalItems', 1)
            ->assertJsonPath('data.0.subject', 'VPN will not connect from home');
    }

    public function test_it_matches_a_prefix(): void
    {
        $this->search('charg')->assertOk()->assertJsonPath('pagination.totalItems', 1);
    }

    public function test_multiple_terms_all_have_to_match(): void
    {
        $this->search('battery charging')->assertOk()->assertJsonPath('pagination.totalItems', 1);
        $this->search('battery payslip')->assertOk()->assertJsonPath('pagination.totalItems', 0);
    }

    public function test_a_term_shorter_than_the_index_minimum_still_matches(): void
    {
        // Below innodb_ft_min_token_size the index holds nothing, so a fulltext
        // query would return an empty page that looks like a genuine miss.
        $this->search('at')->assertOk();
        $this->assertGreaterThan(0, $this->search('vp')->json('pagination.totalItems'));
    }

    public function test_boolean_operators_in_the_input_are_treated_as_text(): void
    {
        // A stray bracket or plus is what the user typed, not syntax. Passing it
        // through would either error or silently change the query.
        $this->search('battery)')->assertOk()->assertJsonPath('pagination.totalItems', 1);
        $this->search('+battery -charging')->assertOk();
        $this->search('***')->assertOk();
    }

    public function test_search_combines_with_other_filters(): void
    {
        $this->asUser($this->token)
            ->getJson('/api/tickets?search=battery&status=open')
            ->assertOk()->assertJsonPath('pagination.totalItems', 1);

        $this->asUser($this->token)
            ->getJson('/api/tickets?search=battery&status=closed')
            ->assertOk()->assertJsonPath('pagination.totalItems', 0);
    }

    public function test_a_short_term_padded_by_an_operator_still_falls_back(): void
    {
        // "vp*" is three characters, so a length check on the raw input lets it
        // through, but stripping the star leaves a two-letter term the index
        // does not hold. It has to take the LIKE path, not return nothing.
        $this->assertGreaterThan(0, $this->search('vp*')->json('pagination.totalItems'));
        $this->assertGreaterThan(0, $this->search('+vp')->json('pagination.totalItems'));
    }

    public function test_a_term_made_only_of_operators_does_not_error(): void
    {
        $this->search('***')->assertOk();
        $this->search('+++ ---')->assertOk();
    }

    public function test_a_term_matching_nothing_returns_an_empty_page(): void
    {
        $this->search('kubernetes')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.totalItems', 0);
    }
}
