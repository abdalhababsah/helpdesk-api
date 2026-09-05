<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\AddTicketComment;
use App\Authorization\Actor;
use App\Http\Requests\TicketCommentStoreRequest;
use App\Http\Resources\TicketCommentResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

final class TicketCommentController extends Controller
{
    public function store(
        TicketCommentStoreRequest $request,
        Actor $actor,
        Ticket $ticket,
        AddTicketComment $addComment,
    ): JsonResponse {
        $comment = $addComment->handle($actor, $ticket, $request->string('body')->toString());

        return response()->json([
            'data' => new TicketCommentResource($comment->load('author:id,name,role_id', 'author.role:id,slug')),
        ], 201);
    }
}
