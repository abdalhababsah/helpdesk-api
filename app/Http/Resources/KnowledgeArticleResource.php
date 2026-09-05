<?php

namespace App\Http\Resources;

use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KnowledgeArticle */
final class KnowledgeArticleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'keywords' => $this->keywords,
            'category' => $this->category === null ? null : ['id' => $this->category->id, 'name' => $this->category->name],
            'isActive' => $this->is_active,
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
