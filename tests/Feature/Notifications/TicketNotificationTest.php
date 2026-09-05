<?php

namespace Tests\Feature\Notifications;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $agent;

    private string $agentToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        Notification::fake();

        $this->requester = User::factory()->create(['email' => 'jordan@example.test']);
        $this->agent = User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $this->agentToken = $this->login('sam@example.test')['token'];
    }

    public function test_the_requester_hears_when_the_status_moves(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => 'in_progress'])
            ->assertOk();

        Notification::assertSentTo($this->requester, TicketStatusChanged::class);
    }

    public function test_the_requester_hears_when_someone_picks_it_up(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['assigneeId' => $this->agent->id])
            ->assertOk();

        Notification::assertSentTo($this->requester, TicketAssigned::class);
    }

    public function test_handing_a_ticket_back_to_the_queue_sends_nothing(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->assignedTo($this->agent)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['assigneeId' => null])
            ->assertOk();

        // Telling someone their ticket was put down again is worse than saying
        // nothing, and there is nothing they can do about it.
        Notification::assertNothingSent();
    }

    public function test_nobody_is_emailed_about_their_own_action(): void
    {
        // An agent updating a ticket they raised themselves.
        $own = Ticket::factory()->requestedBy($this->agent)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$own->id}", ['status' => 'in_progress'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_deactivated_requester_is_not_emailed(): void
    {
        $gone = User::factory()->inactive()->create();
        $ticket = Ticket::factory()->requestedBy($gone)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => 'resolved'])
            ->assertOk();

        // They cannot act on it, and cannot sign in to see it either.
        Notification::assertNothingSent();
    }

    public function test_a_change_that_does_nothing_sends_nothing(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'high'])
            ->assertOk();

        // Priority is not a status change and not an assignment.
        Notification::assertNothingSent();
    }

    public function test_a_rejected_change_sends_nothing(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create();

        // A user has no permission to triage, so nothing happens and nobody
        // should hear about a change that was refused.
        $userToken = $this->login('jordan@example.test')['token'];
        $this->asUser($userToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => 'resolved'])
            ->assertStatus(403);

        Notification::assertNothingSent();
    }

    public function test_the_message_names_the_ticket_and_where_it_moved_to(): void
    {
        $ticket = Ticket::factory()->requestedBy($this->requester)->create(['subject' => 'VPN will not connect']);

        $this->asUser($this->agentToken)
            ->patchJson("/api/tickets/{$ticket->id}", ['status' => 'resolved'])
            ->assertOk();

        Notification::assertSentTo($this->requester, TicketStatusChanged::class, function ($notification) use ($ticket) {
            $mail = $notification->toMail($this->requester);
            $body = implode(' ', array_merge($mail->introLines, $mail->outroLines));

            return str_contains($mail->subject, 'VPN will not connect')
                && str_contains($body, 'resolved')
                && str_contains($body, $ticket->id);
        });
    }

    public function test_notifications_are_queued_rather_than_sent_during_the_request(): void
    {
        // Sending inline would put the response time at the mercy of the mail
        // server, and a slow or unreachable one would fail the update itself.
        $this->assertInstanceOf(
            ShouldQueue::class,
            new TicketStatusChanged(Ticket::factory()->create(), TicketStatus::Open, TicketStatus::Resolved),
        );
    }
}
