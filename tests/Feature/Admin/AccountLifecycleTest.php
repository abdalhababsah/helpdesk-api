<?php

namespace Tests\Feature\Admin;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        Notification::fake();

        $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
        $this->adminToken = $this->login('admin@example.test')['token'];
    }

    public function test_an_account_page_carries_the_ticket_counts(): void
    {
        $agent = User::factory()->moderator()->create();
        Ticket::factory()->count(2)->requestedBy($agent)->create();
        Ticket::factory()->assignedTo($agent)->create(['status' => TicketStatus::InProgress]);
        Ticket::factory()->assignedTo($agent)->create(['status' => TicketStatus::Resolved]);

        $this->asUser($this->adminToken)->getJson("/api/users/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.ticketsRaised', 2)
            ->assertJsonPath('data.ticketsAssigned', 2)
            ->assertJsonPath('data.openTicketsAssigned', 1)
            ->assertJsonPath('data.role', 'moderator');
    }

    public function test_deleting_hides_the_account_and_hands_open_work_back_to_the_queue(): void
    {
        $agent = User::factory()->moderator()->create(['email' => 'leaver@example.test']);
        $agentToken = $this->login('leaver@example.test')['token'];
        $open = Ticket::factory()->assignedTo($agent)->create(['status' => TicketStatus::InProgress]);
        $done = Ticket::factory()->assignedTo($agent)->create(['status' => TicketStatus::Resolved]);

        $this->asUser($this->adminToken)->deleteJson("/api/users/{$agent->id}")->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $agent->id]);
        $this->assertNull($open->fresh()->assignee_id);
        // Finished work keeps its history.
        $this->assertSame($agent->id, $done->fresh()->assignee_id);
        $this->assertSame($agent->name, $done->fresh()->assignee->name);

        // Gone from every list and every login, immediately.
        $this->asUser($this->adminToken)->getJson('/api/users')->assertOk()
            ->assertJsonMissing(['email' => 'leaver@example.test']);
        $this->asUser($this->adminToken)->getJson("/api/users/{$agent->id}")->assertNotFound();
        $this->asUser($agentToken)->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'leaver@example.test', 'password' => 'Passw0rd!'])->assertUnauthorized();

        $this->assertDatabaseHas('action_logs', ['action' => 'account.deleted', 'subject_id' => $agent->id]);
    }

    public function test_an_admin_cannot_delete_themselves_or_the_last_admin(): void
    {
        $this->asUser($this->adminToken)->deleteJson("/api/users/{$this->admin->id}")
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $second = User::factory()->admin()->create(['email' => 'second@example.test']);
        $secondToken = $this->login('second@example.test')['token'];

        // Two admins: deleting one is fine. Then the survivor is protected.
        $this->asUser($secondToken)->deleteJson("/api/users/{$this->admin->id}")->assertNoContent();
        $third = User::factory()->admin()->create(['email' => 'third@example.test']);
        $thirdToken = $this->login('third@example.test')['token'];
        $this->asUser($thirdToken)->deleteJson("/api/users/{$second->id}")->assertNoContent();
        $this->asUser($thirdToken)->deleteJson("/api/users/{$third->id}")->assertStatus(409);
    }

    public function test_an_admin_can_send_a_reset_link_to_an_active_account_only(): void
    {
        $person = User::factory()->create();
        $former = User::factory()->create(['is_active' => false]);

        $this->asUser($this->adminToken)->postJson("/api/users/{$person->id}/password-reset")->assertStatus(202);
        Notification::assertSentTo($person, PasswordResetLink::class);

        $this->asUser($this->adminToken)->postJson("/api/users/{$former->id}/password-reset")->assertStatus(409);
        Notification::assertNotSentTo($former, PasswordResetLink::class);

        $this->assertDatabaseHas('password_reset_tokens', ['user_id' => $person->id, 'requested_by_id' => $this->admin->id]);
        $this->assertDatabaseHas('action_logs', ['action' => 'password.reset_sent', 'subject_id' => $person->id]);
    }

    public function test_deleted_people_still_appear_on_their_tickets(): void
    {
        $requester = User::factory()->create(['name' => 'Dana Former']);
        $ticket = Ticket::factory()->requestedBy($requester)->create();

        $this->asUser($this->adminToken)->deleteJson("/api/users/{$requester->id}")->assertNoContent();

        $this->asUser($this->adminToken)->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()->assertJsonPath('data.requester.name', 'Dana Former');
    }
}
