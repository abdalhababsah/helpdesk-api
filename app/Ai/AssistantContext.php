<?php

namespace App\Ai;

use App\Enums\TicketStatus;
use App\Models\AssistantGuest;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;

/**
 * What the assistant knows about the person before the first word.
 *
 * Built once per turn. It is the only thing that makes guest and signed-in
 * behaviour differ, so that difference lives in one place instead of being
 * spread through the instructions and the tool list.
 */
final class AssistantContext
{
    /** @param  list<array{id: string, name: string}>  $categories */
    private function __construct(
        public readonly User|AssistantGuest $participant,
        public readonly int $openTicketCount,
        public readonly array $categories,
    ) {}

    public static function for(User|AssistantGuest $participant): self
    {
        $open = $participant instanceof User
            ? Ticket::where('requester_id', $participant->getKey())
                ->whereIn('status', array_map(fn (TicketStatus $status): string => $status->value, TicketStatus::open()))
                ->count()
            : 0;

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
            ->all();

        return new self($participant, $open, $categories);
    }

    public function isGuest(): bool
    {
        return $this->participant instanceof AssistantGuest;
    }

    public function user(): ?User
    {
        return $this->participant instanceof User ? $this->participant : null;
    }
}
