<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
final class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subject' => Str::ucfirst(fake()->words(5, true)),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
            'category_id' => Category::factory(),
            'requester_id' => User::factory(),
            'assignee_id' => null,
            // Derived from whichever priority ends up applied, so a state that
            // changes priority does not leave an inconsistent deadline.
            'due_at' => fn (array $attributes): Carbon => now()->addHours(
                $this->priorityOf($attributes)->slaHours(),
            ),
        ];
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            // resolved_at drives the resolution metric, so a finished ticket
            // without it would quietly skew the average.
            'resolved_at' => $status->isFinished() ? now() : null,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ]);
    }

    public function requestedBy(User $user): static
    {
        return $this->state(fn (): array => ['requester_id' => $user->getKey()]);
    }

    public function assignedTo(?User $user): static
    {
        return $this->state(fn (): array => ['assignee_id' => $user?->getKey()]);
    }

    /** Past its deadline while still unresolved, which is what overdue means. */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketStatus::Open,
            'due_at' => now()->subDay(),
            'resolved_at' => null,
        ]);
    }

    /**
     * created_at is not mass assignable, so it is applied to the built model
     * rather than passed through the definition, where fill() would drop it.
     * Setting it makes the attribute dirty, which stops Eloquent overwriting it.
     */
    public function createdAt(Carbon $moment): static
    {
        return $this->afterMaking(function (Ticket $ticket) use ($moment): void {
            $ticket->forceFill([
                // Keep the identifier consistent with the timestamp: the list
                // query uses id as its ordering tiebreaker.
                'id' => strtolower((string) Str::ulid($moment)),
                'created_at' => $moment,
                'updated_at' => $moment,
            ]);
        });
    }

    public function trashed(): static
    {
        return $this->afterCreating(fn (Ticket $ticket) => $ticket->delete());
    }

    /** @param  array<string, mixed>  $attributes */
    private function priorityOf(array $attributes): TicketPriority
    {
        $priority = $attributes['priority'] ?? TicketPriority::Medium;

        return $priority instanceof TicketPriority ? $priority : TicketPriority::from($priority);
    }
}
