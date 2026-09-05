<?php

namespace App\Ai\Tools;

use App\Models\KnowledgeArticle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** The only way the assistant learns anything about this organisation. */
final class SearchKnowledge implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search the helpdesk knowledge base for articles about a topic. Returns up to five articles with their full text.';
    }

    public function handle(Request $request): Stringable|string
    {
        $validated = $request->validate(['query' => 'required|string|max:200']);

        $articles = KnowledgeArticle::active()->search($validated['query'])->limit(5)->get(['id', 'title', 'body']);

        if ($articles->isEmpty()) {
            return 'No articles match.';
        }

        return $articles->map(fn (KnowledgeArticle $article): string => "## {$article->title}\n{$article->body}")->implode("\n\n");
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words describing the topic, for example "vpn home".')->required(),
        ];
    }
}
