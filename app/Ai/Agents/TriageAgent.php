<?php

namespace App\Ai\Agents;

use App\Ai\Tools\FindOpenTickets;
use App\Ai\Tools\ListCategories;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns a described problem into a ticket draft, or points at the ticket that
 * already covers it.
 *
 * Its answer is structured, so the concierge reads fields rather than parsing
 * prose. It is bound to one requester at construction, so it can only ever
 * see that person's tickets.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[MaxSteps(6)]
#[Timeout(45)]
#[CacheInstructions]
final class TriageAgent implements Agent, CanActAsTool, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'triage';
    }

    public function description(): Stringable|string
    {
        return 'Given everything the person has said about a problem they want help with, check for an existing ticket and then draft one with a priority. Pass their whole description, not a summary.';
    }

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You triage support requests for an internal IT and HR helpdesk. You receive what the person has said about their problem.

        Always call find_open_tickets first with the gist of the issue. If it returns a ticket that is clearly the same problem, answer with decision "existing" and that ticket id.
        If you still need one specific detail before a ticket would make sense, such as which device, which error, or since when, answer with decision "ask" and one question. Ask one thing at a time, and never ask twice for something already said.
        Otherwise call list_categories, pick the id that fits best, and answer with decision "draft".

        Drafting rules.
        Subject: 5 to 120 characters, specific, no trailing full stop.
        Description: 2 to 5 sentences in the person's own words, written as them, keeping the detail that matters.
        Priority: urgent when they cannot work at all or there is a security risk. high when they are blocked on a deadline or a whole team is affected. medium when something is degraded but they can still work. low for cosmetic issues and questions.
        Reason: one sentence saying why that priority, written for the person to read.
        TEXT;
    }

    /** @return iterable<int, object> */
    public function tools(): iterable
    {
        return [new FindOpenTickets($this->user), new ListCategories];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'decision' => $schema->string()->enum(['ask', 'existing', 'draft'])->required(),
            'question' => $schema->string()->description('Only for decision ask: the single question to put to the person.'),
            'ticketId' => $schema->string()->description('Only for decision existing: the id of the ticket that already covers this.'),
            'subject' => $schema->string()->description('Only for decision draft.'),
            'description' => $schema->string()->description('Only for decision draft.'),
            'categoryId' => $schema->string()->description('Only for decision draft: an id from list_categories.'),
            'priority' => $schema->string()->enum(['low', 'medium', 'high', 'urgent'])->description('Only for decision draft.'),
            'reason' => $schema->string()->description('Only for decision draft: why that priority.'),
        ];
    }
}
