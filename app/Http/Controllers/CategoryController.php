<?php

namespace App\Http\Controllers;

use App\Actions\Categories\CreateCategory;
use App\Actions\Categories\RenameCategory;
use App\Actions\Categories\SetCategoryActive;
use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Http\Requests\CategoryStoreRequest;
use App\Http\Requests\CategoryUpdateRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController extends Controller
{
    public function index(Request $request, Actor $actor): JsonResponse
    {
        $includeRetired = $request->boolean('includeRetired');

        // Retired categories are of no use to someone raising a ticket, and
        // listing them is only meaningful to whoever manages them.
        if ($includeRetired) {
            $actor->authorize(PermissionSlug::CategoryManage);
        }

        $categories = Category::query()
            ->when(! $includeRetired, fn ($query) => $query->where('is_active', true))
            ->when($includeRetired, fn ($query) => $query->withCount('tickets'))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => CategoryResource::collection($categories)]);
    }

    public function store(CategoryStoreRequest $request, Actor $actor, CreateCategory $create): JsonResponse
    {
        $category = $create->handle(
            $actor,
            $request->string('name')->toString(),
            $request->filled('slug') ? $request->string('slug')->toString() : null,
        );

        return response()->json(['data' => new CategoryResource($category)], 201);
    }

    public function update(
        CategoryUpdateRequest $request,
        Actor $actor,
        Category $category,
        RenameCategory $rename,
        SetCategoryActive $setActive,
    ): JsonResponse {
        if ($request->has('name')) {
            $rename->handle($actor, $category, $request->string('name')->toString());
        }

        if ($request->has('isActive')) {
            $setActive->handle($actor, $category, $request->boolean('isActive'));
        }

        return response()->json(['data' => new CategoryResource($category->fresh())]);
    }
}
