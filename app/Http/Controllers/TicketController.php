<?php

namespace App\Http\Controllers;

use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Http\Requests\TicketIndexRequest;
use App\Http\Resources\TicketDetailResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Queries\TicketQuery;
use Illuminate\Http\JsonResponse;

final class TicketController extends Controller
{
    public function index(TicketIndexRequest $request, Actor $actor, TicketQuery $tickets): JsonResponse
    {
        // No permission check here. Visibility is a predicate inside the query,
        // so a role with no read grant gets an empty page rather than a refusal,
        // and no filter combination can widen what it returns.
        $page = $tickets->paginate($actor, $request->toFilters());

        return response()->json([
            'data' => TicketResource::collection($page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'limit' => $page->perPage(),
                'totalItems' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Actor $actor, Ticket $ticket): JsonResponse
    {
        $actor->authorize(PermissionSlug::TicketRead, $ticket);

        $ticket->load([
            'category:id,slug,name',
            'requester:id,name',
            'assignee:id,name',
            'comments' => fn ($query) => $query->with('author:id,name,role_id')->oldest(),
            'comments.author.role:id,slug',
        ])->loadCount('comments');

        return response()->json(['data' => new TicketDetailResource($ticket)]);
    }
}
