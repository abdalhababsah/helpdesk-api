<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\DeleteTicket;
use App\Actions\Tickets\TriageTicket;
use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Requests\TicketIndexRequest;
use App\Http\Requests\TicketStoreRequest;
use App\Http\Requests\TicketUpdateRequest;
use App\Http\Resources\TicketDetailResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Queries\TicketQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

    public function summary(Actor $actor, TicketQuery $tickets): JsonResponse
    {
        $actor->authorize(PermissionSlug::TicketListQueue);

        return response()->json(['data' => $tickets->summaryFor($actor)]);
    }

    public function show(Actor $actor, Ticket $ticket): JsonResponse
    {
        // Loaded before the check, not through the visibility scope: the brief
        // asks for 403 when a ticket exists but is not yours, and scoping the
        // lookup would return 404 instead. Identifiers are ULIDs, so confirming
        // existence discloses nothing that could be enumerated.
        $actor->authorize(PermissionSlug::TicketRead, $ticket);

        return response()->json(['data' => new TicketDetailResource($this->loadDetail($ticket))]);
    }

    public function store(TicketStoreRequest $request, Actor $actor, CreateTicket $create): JsonResponse
    {
        $ticket = $create->handle(
            actor: $actor,
            subject: $request->string('subject')->toString(),
            description: $request->string('description')->toString(),
            categoryId: $request->string('categoryId')->toString(),
            priority: TicketPriority::tryFrom((string) $request->input('priority')) ?? TicketPriority::Medium,
        );

        return response()->json(['data' => new TicketDetailResource($this->loadDetail($ticket))], 201);
    }

    /**
     * One request, two permissions. Assignment and triage are separate grants,
     * so they are separate actions, composed here inside a single transaction
     * so a partly applied change cannot be committed.
     */
    public function update(
        TicketUpdateRequest $request,
        Actor $actor,
        Ticket $ticket,
        AssignTicket $assign,
        TriageTicket $triage,
    ): JsonResponse {
        DB::transaction(function () use ($request, $actor, $ticket, $assign, $triage): void {
            // has() rather than filled(): null is a real instruction here, it
            // means return the ticket to the queue.
            if ($request->has('assigneeId')) {
                $assign->handle($actor, $ticket, $request->input('assigneeId'));
            }

            if ($request->hasAny(['status', 'priority', 'categoryId'])) {
                $triage->handle(
                    actor: $actor,
                    ticket: $ticket,
                    status: TicketStatus::tryFrom((string) $request->input('status')),
                    priority: TicketPriority::tryFrom((string) $request->input('priority')),
                    categoryId: $request->input('categoryId'),
                );
            }
        });

        return response()->json(['data' => new TicketDetailResource($this->loadDetail($ticket->fresh()))]);
    }

    public function destroy(Actor $actor, Ticket $ticket, DeleteTicket $delete): JsonResponse
    {
        $delete->handle($actor, $ticket);

        return response()->json(null, 204);
    }

    private function loadDetail(Ticket $ticket): Ticket
    {
        return $ticket->load([
            'category:id,slug,name',
            'requester:id,name',
            'assignee:id,name',
            'comments' => fn ($query) => $query->with('author:id,name,role_id')->oldest(),
            'comments.author.role:id,slug',
        ])->loadCount('comments');
    }
}
