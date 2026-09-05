<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\ChangeAccountRole;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SetAccountActive;
use App\Actions\Accounts\UpdateAccountDetails;
use App\Actions\Passwords\SendPasswordReset;
use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Enums\TicketStatus;
use App\Http\Requests\UserIndexRequest;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Resources\AssignableUserResource;
use App\Http\Resources\UserDetailResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class UserController extends Controller
{
    public function index(UserIndexRequest $request, Actor $actor): JsonResponse
    {
        $actor->authorize(PermissionSlug::AccountManage);

        $page = User::query()
            ->with('role:id,slug')
            ->when($request->filled('role'), fn ($query) => $query->whereRelation('role', 'slug', $request->query('role')))
            ->when($request->has('isActive'), fn ($query) => $query->where('is_active', $request->boolean('isActive')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $like = '%'.addcslashes((string) $request->query('search'), '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('name')
            // Names are not unique, so the identifier keeps the order stable
            // between pages.
            ->orderBy('id')
            ->paginate(
                perPage: $request->integer('limit', (int) config('tickets.pagination.default_limit')),
                page: $request->integer('page', 1),
            );

        return response()->json([
            'data' => UserResource::collection($page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'limit' => $page->perPage(),
                'totalItems' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    /** Active agents only, as names. Backs the assignee picker. */
    public function assignable(Actor $actor): JsonResponse
    {
        $actor->authorize(PermissionSlug::TicketAssign);

        $agents = User::query()
            ->with('role:id,slug')
            ->where('is_active', true)
            ->whereHas('role', fn ($role) => $role->whereIn(
                'slug',
                array_map(fn (RoleSlug $slug): string => $slug->value, RoleSlug::assignable()),
            ))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => AssignableUserResource::collection($agents)]);
    }

    public function show(Actor $actor, User $user): JsonResponse
    {
        $actor->authorize(PermissionSlug::AccountManage);

        return response()->json(['data' => new UserDetailResource($this->withDetail($user))]);
    }

    public function destroy(Actor $actor, User $user, DeleteAccount $delete): JsonResponse
    {
        $delete->handle($actor, $user);

        return response()->json(null, 204);
    }

    public function sendPasswordReset(Actor $actor, User $user, SendPasswordReset $send): JsonResponse
    {
        $send->handle($actor, $user);

        return response()->json(['data' => ['message' => "A reset link has been emailed to {$user->email}."]], 202);
    }

    private function withDetail(User $user): User
    {
        return $user->loadMissing('role:id,slug')->loadCount([
            'requestedTickets',
            'assignedTickets',
            'assignedTickets as open_assigned_tickets_count' => fn ($query) => $query->whereIn(
                'status',
                array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::open()),
            ),
        ]);
    }

    public function store(UserStoreRequest $request, Actor $actor, CreateAccount $create): JsonResponse
    {
        $user = $create->handle(
            actor: $actor,
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            roleId: $request->string('roleId')->toString(),
        );

        return response()->json(['data' => new UserResource($user->load('role:id,slug'))], 201);
    }

    public function update(
        UserUpdateRequest $request,
        Actor $actor,
        User $user,
        UpdateAccountDetails $updateDetails,
        ChangeAccountRole $changeRole,
        SetAccountActive $setActive,
    ): JsonResponse {
        if ($request->hasAny(['name', 'email'])) {
            $updateDetails->handle(
                $actor,
                $user,
                $request->has('name') ? $request->string('name')->toString() : null,
                $request->has('email') ? $request->string('email')->toString() : null,
            );
        }

        // Not wrapped in one transaction on purpose: each action revokes the
        // target's sessions, and doing that twice in one transaction would
        // increment the token version twice for a single administrative act.
        if ($request->has('roleId')) {
            $changeRole->handle($actor, $user, $request->string('roleId')->toString());
        }

        if ($request->has('isActive')) {
            $setActive->handle($actor, $user, $request->boolean('isActive'));
        }

        return response()->json(['data' => new UserDetailResource($this->withDetail($user->fresh()))]);
    }
}
