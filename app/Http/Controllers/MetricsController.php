<?php

namespace App\Http\Controllers;

use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Queries\MetricsQuery;
use Illuminate\Http\JsonResponse;

final class MetricsController extends Controller
{
    public function index(Actor $actor, MetricsQuery $metrics): JsonResponse
    {
        // Cross-user figures. A moderator's summary strip is counts over
        // tickets they already see and needs no grant of its own.
        $actor->authorize(PermissionSlug::MetricsRead);

        return response()->json(['data' => $metrics->overview()]);
    }
}
