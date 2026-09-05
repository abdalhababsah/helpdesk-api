<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use App\Models\User;
use App\Queries\TicketQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The person's own unfinished tickets that read like the issue.
 *
 * Bound to one user at construction rather than taking an identifier in the
 * call, so the model has no way to ask about anyone else's tickets.
 */
final class FindOpenTickets implements Tool
{
    public function __construct(
        private readonly User $user,
        private readonly TicketQuery $tickets = new TicketQuery,
    ) {}

    public function description(): Stringable|string
    {
        return "Find the person's own open or in-progress tickets that match a description, to avoid raising the same issue twice.";
    }

    public function handle(Request $request): Stringable|string
    {
        $validated = $request->validate(['query' => 'required|string|max:200']);

        $tickets = $this->tickets->ownOpenMatching($this->user, $validated['query']);

        if ($tickets->isEmpty()) {
            return 'No open tickets match.';
        }

        return $tickets->map(fn (Ticket $ticket): string => sprintf(
            'id=%s | status=%s | raised=%s | subject=%s',
            $ticket->id,
            $ticket->status->value,
            $ticket->created_at->toDateString(),
            $ticket->subject,
        ))->implode("\n");
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The gist of the issue in a few words.')->required(),
        ];
    }
}
