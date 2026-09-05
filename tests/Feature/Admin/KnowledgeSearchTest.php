<?php

namespace Tests\Feature\Admin;

use App\Models\KnowledgeArticle;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Truncation rather than a wrapping transaction. InnoDB does not add a row to
 * its fulltext index until the writing transaction commits, so a MATCH inside
 * an open transaction cannot see the rows the test just wrote.
 */
final class KnowledgeSearchTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        KnowledgeArticle::factory()->create(['title' => 'VPN will not connect from home', 'body' => 'Restart the VPN client.', 'keywords' => 'vpn, remote']);
        KnowledgeArticle::factory()->create(['title' => 'Payslip missing', 'body' => 'Payslips arrive on the 25th.', 'keywords' => 'payroll']);
        KnowledgeArticle::factory()->retired()->create(['title' => 'Old VPN guide', 'body' => 'Retired notes about the VPN.', 'keywords' => 'vpn']);
    }

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_search_finds_matching_active_articles_and_skips_retired_ones(): void
    {
        $titles = KnowledgeArticle::active()->search('vpn connect')->pluck('title')->all();

        $this->assertSame(['VPN will not connect from home'], $titles);
    }

    public function test_a_term_shorter_than_the_index_minimum_still_matches(): void
    {
        // Two characters are below innodb_ft_min_token_size, so this can only
        // pass through the LIKE fallback.
        $titles = KnowledgeArticle::active()->search('pn')->pluck('title')->all();

        $this->assertSame(['VPN will not connect from home'], $titles);
    }

    public function test_a_term_that_matches_nothing_returns_nothing(): void
    {
        $this->assertSame([], KnowledgeArticle::active()->search('sailing regatta')->pluck('title')->all());
    }
}
