<?php

namespace App\Actions\Categories;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateCategory
{
    use RecordsActions;

    public function handle(Actor $actor, string $name, ?string $slug = null): Category
    {
        $actor->authorize(PermissionSlug::CategoryManage);

        return DB::transaction(function () use ($actor, $name, $slug): Category {
            $category = Category::create([
                // The slug is the public filter key, so it is derived once at
                // creation and never follows a later rename, which would break
                // saved links.
                'slug' => $slug ?? Str::slug($name),
                'name' => $name,
                'is_active' => true,
            ]);

            $this->record(ActionType::CategoryCreated, $actor->user, $category, [
                'slug' => $category->slug,
                'name' => $name,
            ]);

            return $category;
        });
    }
}
