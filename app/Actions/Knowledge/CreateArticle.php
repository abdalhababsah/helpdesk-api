<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\DB;

final class CreateArticle
{
    use RecordsActions;

    public function handle(Actor $actor, string $title, string $body, string $keywords, ?string $categoryId): KnowledgeArticle
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        return DB::transaction(function () use ($actor, $title, $body, $keywords, $categoryId): KnowledgeArticle {
            $article = KnowledgeArticle::create([
                'title' => $title,
                'body' => $body,
                'keywords' => $keywords,
                'category_id' => $categoryId,
            ]);

            $this->record(ActionType::ArticleCreated, $actor->user, $article, ['title' => $title]);

            return $article;
        });
    }
}
