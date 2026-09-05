<?php

namespace App\Actions\Categories;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class RenameCategory
{
    use RecordsActions;

    public function handle(Actor $actor, Category $category, string $name): Category
    {
        $actor->authorize(PermissionSlug::CategoryManage);

        if ($category->name === $name) {
            return $category;
        }

        return DB::transaction(function () use ($actor, $category, $name): Category {
            $from = $category->name;
            // The slug is deliberately untouched: it is a public filter key and
            // existing links must keep resolving.
            $category->update(['name' => $name]);

            $this->record(ActionType::CategoryRenamed, $actor->user, $category, [
                'from' => $from,
                'to' => $name,
            ]);

            return $category;
        });
    }
}
