<?php

namespace App\Ai\Cards;

use App\Enums\CardType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The model proposes a card; this decides whether it may be shown.
 *
 * Anything it cannot prove against the database is downgraded to no card and
 * logged, so a wrong identifier or an invented category never reaches the
 * browser and never becomes a ticket.
 */
final class CardValidator
{
    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public function validate(array $card, ?User $user): array
    {
        $type = CardType::tryFrom(is_string($card['type'] ?? null) ? $card['type'] : '');

        $validated = match ($type) {
            CardType::None => ['type' => CardType::None->value],
            CardType::SignInRequired => $user === null ? ['type' => CardType::SignInRequired->value] : null,
            CardType::ExistingTicket => $this->existingTicket($card, $user),
            CardType::TicketDraft => $this->ticketDraft($card, $user),
            null => null,
        };

        if ($validated === null) {
            Log::info('assistant.card_rejected', ['card' => $card]);

            return ['type' => CardType::None->value];
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>|null
     */
    private function existingTicket(array $card, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $ticket = Ticket::query()
            ->where('requester_id', $user->getKey())
            ->whereIn('status', array_map(fn (TicketStatus $status): string => $status->value, TicketStatus::open()))
            ->find(is_string($card['ticketId'] ?? null) ? $card['ticketId'] : '');

        if ($ticket === null) {
            return null;
        }

        return [
            'type' => CardType::ExistingTicket->value,
            'ticketId' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'createdAt' => $ticket->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>|null
     */
    private function ticketDraft(array $card, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $subject = trim(is_string($card['subject'] ?? null) ? $card['subject'] : '');
        $description = trim(is_string($card['description'] ?? null) ? $card['description'] : '');
        $priority = TicketPriority::tryFrom(is_string($card['priority'] ?? null) ? $card['priority'] : '');

        $category = Category::query()
            ->where('is_active', true)
            ->find(is_string($card['categoryId'] ?? null) ? $card['categoryId'] : '');

        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 120 || mb_strlen($description) < 10) {
            return null;
        }

        if ($priority === null || $category === null) {
            return null;
        }

        return [
            'type' => CardType::TicketDraft->value,
            'subject' => $subject,
            'description' => mb_substr($description, 0, 4000),
            'categoryId' => $category->id,
            'categoryName' => $category->name,
            'priority' => $priority->value,
            'reason' => trim(is_string($card['reason'] ?? null) ? $card['reason'] : ''),
        ];
    }
}
