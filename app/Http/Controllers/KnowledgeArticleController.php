<?php

namespace App\Http\Controllers;

use App\Actions\Knowledge\CreateArticle;
use App\Actions\Knowledge\SetArticleActive;
use App\Actions\Knowledge\UpdateArticle;
use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Http\Requests\KnowledgeArticleIndexRequest;
use App\Http\Requests\KnowledgeArticleStoreRequest;
use App\Http\Requests\KnowledgeArticleUpdateRequest;
use App\Http\Resources\KnowledgeArticleResource;
use App\Models\KnowledgeArticle;
use Illuminate\Http\JsonResponse;

final class KnowledgeArticleController extends Controller
{
    public function index(KnowledgeArticleIndexRequest $request, Actor $actor): JsonResponse
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        $page = KnowledgeArticle::query()
            ->with('category:id,name')
            ->when(! $request->boolean('includeRetired'), fn ($query) => $query->active())
            // A search orders by relevance, so the alphabetical order only
            // applies when there is nothing to rank against.
            ->when(
                $request->filled('search'),
                fn ($query) => $query->search((string) $request->query('search')),
                fn ($query) => $query->orderBy('title'),
            )
            ->paginate(perPage: $request->integer('limit', 20), page: $request->integer('page', 1));

        return response()->json([
            'data' => KnowledgeArticleResource::collection($page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'limit' => $page->perPage(),
                'totalItems' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    public function store(KnowledgeArticleStoreRequest $request, Actor $actor, CreateArticle $create): JsonResponse
    {
        $article = $create->handle(
            $actor,
            $request->string('title')->toString(),
            $request->string('body')->toString(),
            $request->string('keywords')->toString(),
            $request->filled('categoryId') ? $request->string('categoryId')->toString() : null,
        );

        return response()->json(['data' => new KnowledgeArticleResource($article->load('category:id,name'))], 201);
    }

    public function update(
        KnowledgeArticleUpdateRequest $request,
        Actor $actor,
        KnowledgeArticle $article,
        UpdateArticle $update,
        SetArticleActive $setActive,
    ): JsonResponse {
        $changes = [];

        foreach (['title', 'body', 'keywords'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $request->string($field)->toString();
            }
        }

        if ($request->has('categoryId')) {
            $changes['category_id'] = $request->filled('categoryId') ? $request->string('categoryId')->toString() : null;
        }

        if ($changes !== []) {
            $update->handle($actor, $article, $changes);
        }

        if ($request->has('isActive')) {
            $setActive->handle($actor, $article, $request->boolean('isActive'));
        }

        return response()->json(['data' => new KnowledgeArticleResource($article->fresh()->load('category:id,name'))]);
    }
}
