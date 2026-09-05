<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every response with an identifier and puts the same value in the log
 * context, so a failure a user reports can be found in the logs from the one
 * string they can see.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        // An upstream proxy may already have assigned one. Reusing it keeps a
        // single request traceable across services.
        $id = $request->headers->get(self::HEADER) ?: (string) Str::ulid();

        $request->headers->set(self::HEADER, $id);
        Log::shareContext(['requestId' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
