<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\DB;

/** Retired articles stop answering questions but keep their history. */
final class SetArticleActive
{
    use RecordsActions;

    public function handle(Actor $actor, KnowledgeArticle $article, bool $active): KnowledgeArticle
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        if ($article->is_active === $active) {
            return $article;
        }

        return DB::transaction(function () use ($actor, $article, $active): KnowledgeArticle {
            $article->update(['is_active' => $active]);

            $this->record(
                $active ? ActionType::ArticleRestored : ActionType::ArticleRetired,
                $actor->user,
                $article,
                ['title' => $article->title],
            );

            return $article;
        });
    }
}
