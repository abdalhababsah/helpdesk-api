<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketComment>
 */
final class TicketCommentFactory extends Factory
{
    protected $model = TicketComment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'author_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    public function on(Ticket $ticket): static
    {
        return $this->state(fn (): array => ['ticket_id' => $ticket->getKey()]);
    }

    public function by(User $author): static
    {
        return $this->state(fn (): array => ['author_id' => $author->getKey()]);
    }
}
