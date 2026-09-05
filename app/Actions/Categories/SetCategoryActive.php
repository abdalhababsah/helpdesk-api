<?php

namespace App\Actions\Categories;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Retiring hides a category from new tickets while leaving existing ones
 * intact and still filterable. Categories are never deleted: tickets reference
 * them with RESTRICT, so history stays readable.
 */
final class SetCategoryActive
{
    use RecordsActions;

    public function handle(Actor $actor, Category $category, bool $active): Category
    {
        $actor->authorize(PermissionSlug::CategoryManage);

        if ($category->is_active === $active) {
            return $category;
        }

        return DB::transaction(function () use ($actor, $category, $active): Category {
            $category->update(['is_active' => $active]);

            $this->record(
                $active ? ActionType::CategoryRestored : ActionType::CategoryRetired,
                $actor->user,
                $category,
                ['ticket_count' => $category->tickets()->count()],
            );

            return $category;
        });
    }
}
