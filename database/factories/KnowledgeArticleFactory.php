<?php

namespace Database\Factories;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeArticle> */
class KnowledgeArticleFactory extends Factory
{
    protected $model = KnowledgeArticle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'keywords' => implode(', ', fake()->words(3)),
            'category_id' => null,
            'is_active' => true,
        ];
    }

    public function retired(): static
    {
        return $this->state(['is_active' => false]);
    }
}
