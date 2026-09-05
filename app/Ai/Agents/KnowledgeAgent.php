<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SearchKnowledge;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Answers questions from the knowledge base and nothing else.
 *
 * It holds no memory of the conversation on purpose: it is asked one question
 * at a time by the concierge, which keeps its context small and stops it from
 * drifting into triage.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[MaxSteps(4)]
#[Timeout(45)]
#[CacheInstructions]
final class KnowledgeAgent implements Agent, CanActAsTool, HasTools
{
    use Promptable;

    public function name(): string
    {
        return 'knowledge_base';
    }

    public function description(): Stringable|string
    {
        return 'Answer a question about IT, HR or how the helpdesk works, using the knowledge base. Pass the question in plain words.';
    }

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You answer questions for an internal IT and HR helpdesk.

        Always call search_knowledge first, with a few words from the question. Answer only from the articles it returns.
        Keep the answer short, in plain sentences, and give any steps in order. Name the article you used.
        If nothing relevant comes back, reply exactly: I do not have that in the knowledge base. Say nothing else.
        Never invent policies, dates, names, prices or contact details.
        TEXT;
    }

    /** @return iterable<int, object> */
    public function tools(): iterable
    {
        return [new SearchKnowledge];
    }
}
