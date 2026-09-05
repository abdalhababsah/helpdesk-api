<?php

namespace App\Ai\Tools;

use App\Models\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** The categories a draft may be filed under, with the ids to use. */
final class ListCategories implements Tool
{
    public function description(): Stringable|string
    {
        return 'List the ticket categories a ticket can be filed under, with their ids.';
    }

    public function handle(Request $request): Stringable|string
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category): string => "id={$category->id} | name={$category->name}")
            ->implode("\n");
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
