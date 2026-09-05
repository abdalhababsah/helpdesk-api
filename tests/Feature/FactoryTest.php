<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class FactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_user_factory_fails_loudly_without_seeded_roles(): void
    {
        // A role invented on demand would carry no grants, so a test using it
        // would pass against a configuration that never ships.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Seed roles and permissions');

        User::factory()->create();
    }

    public function test_it_builds_users_with_a_real_role_and_a_hashed_password(): void
    {
        $this->seedAuthorization();

        $user = User::factory()->create();

        $this->assertSame(26, strlen($user->id));
        $this->assertSame(RoleSlug::User, $user->role->slug);
        $this->assertSame(60, strlen($user->password));
        $this->assertTrue(password_verify('Passw0rd!', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertSame(1, $user->token_version);
    }

    public function test_user_states_select_the_seeded_role(): void
    {
        $this->seedAuthorization();

        $this->assertSame(RoleSlug::Admin, User::factory()->admin()->create()->role->slug);
        $this->assertSame(RoleSlug::Moderator, User::factory()->moderator()->create()->role->slug);
        $this->assertFalse(User::factory()->inactive()->create()->is_active);
    }

    public function test_the_factory_produced_user_can_actually_log_in(): void
    {
        $this->seedAuthorization();
        $user = User::factory()->moderator()->create(['email' => 'sam@example.test']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'moderator');
    }

    public function test_the_category_factory_produces_a_usable_slug(): void
    {
        $category = Category::factory()->create();

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $category->slug);
        $this->assertTrue($category->is_active);
        $this->assertFalse(Category::factory()->retired()->create()->is_active);
    }

    public function test_the_ticket_factory_derives_the_deadline_from_the_priority(): void
    {
        $this->seedAuthorization();

        $urgent = Ticket::factory()->priority(TicketPriority::Urgent)->create();
        $low = Ticket::factory()->priority(TicketPriority::Low)->create();

        $this->assertSame(4, (int) round(now()->diffInHours($urgent->due_at)));
        $this->assertSame(168, (int) round(now()->diffInHours($low->due_at)));
    }

    public function test_finished_ticket_states_stamp_the_resolution_clock(): void
    {
        $this->seedAuthorization();

        $this->assertNotNull(Ticket::factory()->status(TicketStatus::Resolved)->create()->resolved_at);
        $closed = Ticket::factory()->status(TicketStatus::Closed)->create();
        $this->assertNotNull($closed->resolved_at);
        $this->assertNotNull($closed->closed_at);
        $this->assertNull(Ticket::factory()->status(TicketStatus::Open)->create()->resolved_at);
    }

    public function test_the_overdue_state_is_actually_overdue(): void
    {
        $this->seedAuthorization();

        $this->assertTrue(Ticket::factory()->overdue()->create()->is_overdue);
        $this->assertFalse(Ticket::factory()->create()->is_overdue);
    }

    public function test_backdating_keeps_the_identifier_in_creation_order(): void
    {
        $this->seedAuthorization();

        $older = Ticket::factory()->createdAt(Carbon::parse('2026-01-01 09:00:00'))->create();
        $newer = Ticket::factory()->createdAt(Carbon::parse('2026-06-01 09:00:00'))->create();

        $this->assertTrue($older->created_at->lt($newer->created_at));
        // The list query uses id as its ordering tiebreaker, so it has to sort
        // the same way created_at does.
        $this->assertLessThan(0, strcmp($older->id, $newer->id));
    }

    public function test_the_trashed_state_soft_deletes(): void
    {
        $this->seedAuthorization();
        $ticket = Ticket::factory()->trashed()->create();

        $this->assertNull(Ticket::find($ticket->id));
        $this->assertNotNull(Ticket::withTrashed()->find($ticket->id));
    }

    public function test_relations_can_be_pinned_to_existing_records(): void
    {
        $this->seedAuthorization();

        $requester = User::factory()->create();
        $agent = User::factory()->moderator()->create();
        $ticket = Ticket::factory()->requestedBy($requester)->assignedTo($agent)->create();

        $this->assertSame($requester->id, $ticket->requester_id);
        $this->assertSame($agent->id, $ticket->assignee_id);
        $this->assertSame($requester->id, $ticket->ownerId());

        $comment = TicketComment::factory()->on($ticket)->by($agent)->create();
        $this->assertSame($ticket->id, $comment->ticket_id);
        $this->assertSame($agent->id, $comment->author_id);
        $this->assertSame(1, $ticket->comments()->count());
    }
}
