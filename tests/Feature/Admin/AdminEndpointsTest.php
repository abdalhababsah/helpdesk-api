<?php

namespace Tests\Feature\Admin;

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    private string $agentToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
        User::factory()->moderator()->create(['email' => 'sam@example.test']);

        $this->adminToken = $this->login('admin@example.test')['token'];
        $this->agentToken = $this->login('sam@example.test')['token'];
    }

    public function test_the_category_list_hides_retired_ones_by_default(): void
    {
        Category::factory()->create(['name' => 'Live one']);
        Category::factory()->retired()->create(['name' => 'Retired one']);

        // Retired categories are of no use to someone raising a ticket.
        $this->asUser($this->agentToken)->getJson('/api/categories')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->asUser($this->adminToken)->getJson('/api/categories?includeRetired=1')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'slug', 'name', 'isActive', 'ticketCount']]]);
    }

    public function test_creating_a_category_derives_a_slug_and_renaming_leaves_it_alone(): void
    {
        $created = $this->asUser($this->adminToken)
            ->postJson('/api/categories', ['name' => 'IT - Mobile Devices'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'it-mobile-devices');

        // The slug is a public filter key, so a rename must not break links
        // that already point at it.
        $this->asUser($this->adminToken)
            ->patchJson('/api/categories/'.$created->json('data.id'), ['name' => 'IT - Phones'])
            ->assertOk()
            ->assertJsonPath('data.name', 'IT - Phones')
            ->assertJsonPath('data.slug', 'it-mobile-devices');
    }

    public function test_retiring_a_category_keeps_its_tickets(): void
    {
        $category = Category::factory()->create();
        Ticket::factory()->count(3)->create(['category_id' => $category->id]);

        $this->asUser($this->adminToken)
            ->patchJson("/api/categories/{$category->id}", ['isActive' => false])
            ->assertOk()->assertJsonPath('data.isActive', false);

        $this->assertSame(3, Ticket::where('category_id', $category->id)->count());
    }

    public function test_a_duplicate_slug_is_rejected(): void
    {
        Category::factory()->create(['slug' => 'it-hardware']);

        $this->asUser($this->adminToken)
            ->postJson('/api/categories', ['name' => 'Hardware', 'slug' => 'it-hardware'])
            ->assertStatus(400);
    }

    public function test_the_account_list_paginates_filters_and_searches(): void
    {
        User::factory()->count(4)->create();
        User::factory()->inactive()->create(['name' => 'Dana Former']);

        $this->asUser($this->adminToken)->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'isActive']], 'pagination'])
            ->assertJsonPath('pagination.totalItems', 7);

        $this->asUser($this->adminToken)->getJson('/api/users?role=admin')
            ->assertOk()->assertJsonPath('pagination.totalItems', 1);

        $this->asUser($this->adminToken)->getJson('/api/users?isActive=false')
            ->assertOk()->assertJsonPath('pagination.totalItems', 1);

        $this->asUser($this->adminToken)->getJson('/api/users?search=Dana')
            ->assertOk()->assertJsonPath('pagination.totalItems', 1);
    }

    public function test_the_account_list_never_returns_a_password(): void
    {
        $body = $this->asUser($this->adminToken)->getJson('/api/users')->getContent();

        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    public function test_the_assignee_picker_returns_only_active_agents_and_no_addresses(): void
    {
        User::factory()->count(2)->create();
        User::factory()->moderator()->inactive()->create();

        $response = $this->asUser($this->agentToken)->getJson('/api/users/assignable')->assertOk();

        // Populating this from the account list would hand every moderator the
        // full directory to answer a question that only needs names.
        $this->assertCount(2, $response->json('data'));
        $this->assertStringNotContainsString('@', $response->getContent());
    }

    public function test_creating_an_account(): void
    {
        $roleId = Role::where('slug', 'moderator')->value('id');

        $this->asUser($this->adminToken)->postJson('/api/users', [
            'name' => 'New Agent',
            'email' => 'NEW.Agent@Example.Test',
            'password' => 'Sufficient1Password',
            'roleId' => $roleId,
        ])->assertStatus(201)->assertJsonPath('data.role', 'moderator');

        // Lower-cased on the way in so the unique index and every lookup agree.
        $this->assertDatabaseHas('users', ['email' => 'new.agent@example.test']);
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->asUser($this->adminToken)->postJson('/api/users', [
            'name' => 'New Agent',
            'email' => 'agent@example.test',
            'password' => 'short',
            'roleId' => Role::where('slug', 'user')->value('id'),
        ])->assertStatus(400)->assertJsonFragment(['field' => 'password']);
    }

    public function test_deactivating_an_account_ends_its_session_immediately(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.test']);
        $victimToken = $this->login('victim@example.test')['token'];

        $this->asUser($victimToken)->getJson('/api/auth/me')->assertOk();

        $this->asUser($this->adminToken)
            ->patchJson("/api/users/{$victim->id}", ['isActive' => false])
            ->assertOk()->assertJsonPath('data.isActive', false);

        $this->asUser($victimToken)->getJson('/api/auth/me')
            ->assertStatus(403)->assertJsonPath('error.code', 'AUTH_ACCOUNT_DISABLED');
    }

    public function test_an_admin_cannot_lock_themselves_out(): void
    {
        $this->asUser($this->adminToken)
            ->patchJson("/api/users/{$this->admin->id}", ['isActive' => false])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $this->asUser($this->adminToken)
            ->patchJson("/api/users/{$this->admin->id}", ['roleId' => Role::where('slug', 'user')->value('id')])
            ->assertStatus(409);
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $second = User::factory()->admin()->create(['email' => 'second@example.test']);
        $secondToken = $this->login('second@example.test')['token'];

        // Two admins, so one can go.
        $this->asUser($secondToken)->patchJson("/api/users/{$this->admin->id}", ['isActive' => false])->assertOk();

        // One left, and nothing in the application could undo removing them.
        $this->asUser($secondToken)
            ->patchJson("/api/users/{$second->id}", ['isActive' => false])
            ->assertStatus(409);
    }

    public function test_metrics_report_every_bucket_including_the_empty_ones(): void
    {
        $category = Category::factory()->create();
        Category::factory()->create(['name' => 'Untouched']);

        Ticket::factory()->count(2)->status(TicketStatus::Open)->create(['category_id' => $category->id]);
        Ticket::factory()->overdue()->create(['category_id' => $category->id]);
        // Raised ten hours before they were resolved, so the average is a real
        // duration rather than the zero an instantly resolved fixture gives.
        Ticket::factory()->count(3)
            ->createdAt(now()->subHours(10))
            ->create([
                'category_id' => $category->id,
                'status' => TicketStatus::Resolved,
                'resolved_at' => now(),
            ]);

        $response = $this->asUser($this->adminToken)->getJson('/api/metrics')->assertOk();

        // A chart that silently drops empty buckets reads as missing data.
        $this->assertSame(['open', 'in_progress', 'resolved', 'closed'], array_keys($response->json('data.byStatus')));
        $this->assertSame(0, $response->json('data.byStatus.closed'));
        $this->assertCount(2, $response->json('data.byCategory'));
        $this->assertSame(6, $response->json('data.totals.tickets'));
        $this->assertSame(1, $response->json('data.totals.overdue'));
        // JSON has a single number type, so 10.0 arrives as 10. Compare the
        // value, not the PHP type it happens to decode to.
        $this->assertEqualsWithDelta(10, $response->json('data.averageResolutionHours'), 0.2);
    }

    public function test_average_resolution_is_absent_rather_than_zero_when_nothing_is_resolved(): void
    {
        Ticket::factory()->count(2)->create();

        // No data is not an average of none.
        $this->asUser($this->adminToken)->getJson('/api/metrics')
            ->assertOk()->assertJsonPath('data.averageResolutionHours', null);
    }
}
