<?php

namespace App\Http\Controllers;

use App\Actions\Assistant\ClaimConversation;
use App\Actions\Assistant\RaiseTicketFromConversation;
use App\Actions\Assistant\SendMessage;
use App\Actions\Assistant\StartConversation;
use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Http\Requests\AssistantConversationIndexRequest;
use App\Http\Requests\AssistantMessageRequest;
use App\Http\Requests\AssistantTicketRequest;
use App\Http\Resources\AssistantSessionResource;
use App\Http\Resources\TicketResource;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use App\Support\AssistantParticipant;
use App\Support\AssistantTranscript;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssistantConversationController extends Controller
{
    public function __construct(
        private readonly AssistantParticipant $participants,
        private readonly AssistantTranscript $transcripts,
    ) {}

    /** The oversight view. Reading every conversation, never speaking in one. */
    public function index(AssistantConversationIndexRequest $request, Actor $actor): JsonResponse
    {
        $actor->authorize(PermissionSlug::MetricsRead);

        $page = AssistantSession::query()
            ->with('participant')
            ->when($request->filled('outcome'), fn ($query) => $query->where('outcome', $request->query('outcome')))
            ->when($request->filled('participant'), fn ($query) => $query->where('participant_type', $request->query('participant')))
            ->orderByDesc('last_activity_at')
            ->paginate(perPage: $request->integer('limit', 20), page: $request->integer('page', 1));

        return response()->json([
            'data' => AssistantSessionResource::collection($page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'limit' => $page->perPage(),
                'totalItems' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, StartConversation $start): JsonResponse
    {
        $participant = $this->participants->resolve($request);
        $session = $start->handle($participant);

        return response()->json(['data' => [
            'session' => new AssistantSessionResource($session),
            // Returned once so the browser can keep talking as the same guest.
            'guestId' => $participant instanceof AssistantGuest ? $participant->getKey() : null,
            'greeting' => config('assistant.greeting'),
        ]], 201);
    }

    public function show(Request $request, AssistantSession $session): JsonResponse
    {
        $this->participantFor($request, $session, allowOversight: true);

        return response()->json(['data' => [
            ...(new AssistantSessionResource($session))->toArray($request),
            'messages' => $this->transcripts->for($session->conversation_id)->all(),
        ]]);
    }

    public function message(AssistantMessageRequest $request, AssistantSession $session, SendMessage $send): JsonResponse
    {
        $participant = $this->participantFor($request, $session);

        $result = $send->handle($session, $participant, $request->string('message')->toString());

        return response()->json(['data' => [
            'reply' => $result['reply'],
            'card' => $result['card'],
            'session' => new AssistantSessionResource($result['session']),
        ]]);
    }

    public function ticket(AssistantTicketRequest $request, AssistantSession $session, RaiseTicketFromConversation $raise): JsonResponse
    {
        $ticket = $raise->handle(
            $session,
            $this->signedInCaller('Sign in to raise a ticket.'),
            $request->string('subject')->toString(),
            $request->string('description')->toString(),
            $request->string('categoryId')->toString(),
        );

        return response()->json(['data' => [
            'ticket' => new TicketResource($ticket->load(['category:id,slug,name', 'requester:id,name', 'assignee:id,name'])),
            'message' => RaiseTicketFromConversation::CONFIRMATION,
            'session' => new AssistantSessionResource($session->refresh()),
        ]], 201);
    }

    public function claim(Request $request, AssistantSession $session, ClaimConversation $claim): JsonResponse
    {
        $user = $this->signedInCaller('Sign in to keep this conversation.');

        $guest = AssistantGuest::find((string) $request->header(AssistantParticipant::HEADER, ''));

        if ($guest === null) {
            throw new AuthorizationException('This conversation is not yours.');
        }

        return response()->json(['data' => new AssistantSessionResource($claim->handle($session, $guest, $user))]);
    }

    /** These two routes accept guests, so being signed in is checked here rather than by middleware. */
    private function signedInCaller(string $message): User
    {
        if (! app()->bound(Actor::class)) {
            throw new AuthorizationException($message);
        }

        return app(Actor::class)->user;
    }

    /**
     * The conversation must be the caller's own.
     *
     * Reading for oversight is a separate thing: an administrator may read any
     * conversation for the report, but never speaks in one, so that permission
     * only opens the reading routes.
     */
    private function participantFor(Request $request, AssistantSession $session, bool $allowOversight = false): User|AssistantGuest
    {
        $participant = $this->participants->resolve($request);

        if ($session->isWith($participant)) {
            return $participant;
        }

        if ($allowOversight && app()->bound(Actor::class) && app(Actor::class)->can(PermissionSlug::MetricsRead)) {
            return $participant;
        }

        throw new AuthorizationException('This conversation is not yours.');
    }
}
