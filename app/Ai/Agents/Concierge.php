<?php

namespace App\Ai\Agents;

use App\Ai\AssistantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The one agent the person talks to.
 *
 * It decides what they need and hands the work to a specialist; it never
 * searches or triages itself. Its answer is always a reply plus a card, so
 * the client has no prose to parse and no decision left to make.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[MaxSteps(8)]
#[Timeout(60)]
#[CacheInstructions]
#[CacheToolDefinitions]
final class Concierge implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly AssistantContext $context) {}

    public function instructions(): Stringable|string
    {
        $who = $this->context->isGuest()
            ? 'The person is not signed in. You can answer questions about the helpdesk and about IT and HR topics. You cannot raise tickets and you cannot see anyone\'s tickets. If they want a ticket or a person, set the card type to sign_in_required and say they need to sign in first.'
            : sprintf(
                'The person is signed in as %s and has %d open ticket(s). You can raise tickets for them through the triage tool.',
                $this->context->user()?->name,
                $this->context->openTicketCount,
            );

        return <<<TEXT
        You are the support assistant for an internal IT and HR helpdesk. Be plain, warm and brief. Sentence case. No exclamation marks.
        {$who}

        How to work.
        For a question about how something works or how to fix something, call knowledge_base with the question and give its answer in your own words. If it does not have the answer, say so, then offer to raise a ticket if they are signed in, or to sign in if they are not.
        For a problem they want fixed, or when they ask for a person or a ticket, call triage with everything they have said about it. If triage answers ask, put its question in your reply and use the card type none. If it answers existing, use the card type existing_ticket with that ticket id and tell them it is already open. If it answers draft, copy its fields into a ticket_draft card and tell them to check the details and confirm.
        If they say the existing ticket is a different issue, call triage again and say so in what you send it.
        Never say a ticket has been created. You only draft it. The person confirms and the system creates it.
        Never state a policy, date, price or contact detail that did not come from knowledge_base.

        Always answer with the structured output. The reply is what they read. The card is none unless one of the cases above applies.
        TEXT;
    }

    /** @return iterable<int, object> */
    public function tools(): iterable
    {
        $tools = [new KnowledgeAgent];

        $user = $this->context->user();

        // Triage can raise tickets and read tickets, so a guest must not be
        // able to reach it at all, not merely be told no.
        if ($user !== null) {
            $tools[] = new TriageAgent($user);
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->min(1)->max(2000)->description('What the person reads.')->required(),
            'card' => $schema->anyOf([
                $schema->object(fn (JsonSchema $card): array => [
                    'type' => $card->string()->enum(['none'])->required(),
                ]),
                $schema->object(fn (JsonSchema $card): array => [
                    'type' => $card->string()->enum(['sign_in_required'])->required(),
                ]),
                $schema->object(fn (JsonSchema $card): array => [
                    'type' => $card->string()->enum(['existing_ticket'])->required(),
                    'ticketId' => $card->string()->description('An id returned by triage, never invented.')->required(),
                ]),
                $schema->object(fn (JsonSchema $card): array => [
                    'type' => $card->string()->enum(['ticket_draft'])->required(),
                    'subject' => $card->string()->min(5)->max(120)->required(),
                    'description' => $card->string()->min(10)->max(4000)->required(),
                    'categoryId' => $card->string()->required(),
                    'priority' => $card->string()->enum(['low', 'medium', 'high', 'urgent'])->required(),
                    'reason' => $card->string()->max(300)->required(),
                ]),
            ])->required(),
        ];
    }

    /**
     * Long enough to hold a triage conversation, short enough that an old one
     * does not carry an entire day of unrelated questions into every prompt.
     */
    protected function maxConversationMessages(): int
    {
        return 40;
    }
}
