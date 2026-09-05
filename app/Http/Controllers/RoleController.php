<?php

namespace App\Http\Controllers;

use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

final class RoleController extends Controller
{
    /**
     * The options behind the role dropdown on the account screens.
     *
     * Needed because role identifiers are generated when the database is
     * seeded, so they differ per installation and cannot be hardcoded in the
     * client.
     */
    public function index(Actor $actor): JsonResponse
    {
        $actor->authorize(PermissionSlug::AccountManage);

        return response()->json([
            'data' => RoleResource::collection(Role::orderBy('name')->get()),
        ]);
    }
}
