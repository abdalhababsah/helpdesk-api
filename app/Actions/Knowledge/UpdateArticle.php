<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\DB;

final class UpdateArticle
{
    use RecordsActions;

    /** @param  array<string, string|null>  $changes */
    public function handle(Actor $actor, KnowledgeArticle $article, array $changes): KnowledgeArticle
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        $changes = array_filter($changes, fn ($value, $key): bool => $article->{$key} !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return $article;
        }

        return DB::transaction(function () use ($actor, $article, $changes): KnowledgeArticle {
            $article->update($changes);

            $this->record(ActionType::ArticleUpdated, $actor->user, $article, ['fields' => array_keys($changes)]);

            return $article;
        });
    }
}
