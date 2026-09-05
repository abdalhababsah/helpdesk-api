<?php

namespace App\Actions\Assistant;

use App\Actions\Tickets\CreateTicket;
use App\Enums\AssistantOutcome;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Exceptions\ConversationClosed;
use App\Models\AssistantSession;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;

/**
 * The person confirmed the draft, so the ticket is created.
 *
 * Owning the conversation is the authority here, not a grant: a user holds no
 * ticket:create and needs none. The priority is the one the assistant drafted
 * and the server kept, so a client cannot promote its own ticket by editing
 * the request body.
 */
final class RaiseTicketFromConversation
{
    public const CONFIRMATION = 'Your ticket has been raised. A moderator will be in touch. You can follow it under Your tickets.';

    public function __construct(private readonly CreateTicket $create) {}

    public function handle(AssistantSession $session, User $requester, string $subject, string $description, string $categoryId): Ticket
    {
        if (! $session->isWith($requester)) {
            throw new AuthorizationException('This conversation is not yours.');
        }

        if (! $session->isOpen()) {
            throw new ConversationClosed;
        }

        $priority = TicketPriority::tryFrom((string) ($session->last_draft['priority'] ?? '')) ?? TicketPriority::Medium;

        return DB::transaction(function () use ($session, $requester, $subject, $description, $categoryId, $priority): Ticket {
            $ticket = $this->create->createFor(
                $requester,
                $subject,
                $description,
                $categoryId,
                $priority,
                TicketSource::Assistant,
                $session->conversation_id,
            );

            $this->appendConfirmation($session);

            $session->forceFill([
                'outcome' => AssistantOutcome::TicketRaised,
                'ticket_id' => $ticket->id,
                'last_activity_at' => now(),
            ])->save();

            return $ticket;
        });
    }

    /**
     * Written straight into the transcript rather than asked of the model. It
     * is a fact about what just happened, so it costs nothing and cannot come
     * back worded as a maybe.
     */
    private function appendConfirmation(AssistantSession $session): void
    {
        ConversationMessage::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $session->conversation_id,
            'participant_type' => $session->participant_type,
            'participant_id' => $session->participant_id,
            'agent' => 'system',
            'role' => 'assistant',
            'content' => json_encode(['reply' => self::CONFIRMATION, 'card' => ['type' => 'none']]),
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);
    }
}
