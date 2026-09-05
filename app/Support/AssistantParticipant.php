<?php

namespace App\Support;

use App\Authorization\Actor;
use App\Models\AssistantGuest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who is talking to the assistant. A signed-in person wins; otherwise the
 * guest named by the header, created on first sight. An unknown guest id is
 * replaced rather than trusted, so a forged id cannot reach another guest's
 * conversation.
 */
final class AssistantParticipant
{
    public const HEADER = 'X-Assistant-Guest';

    public function resolve(Request $request): User|AssistantGuest
    {
        if (app()->bound(Actor::class)) {
            return app(Actor::class)->user;
        }

        $id = (string) $request->header(self::HEADER, '');

        $guest = Str::isUlid($id) ? AssistantGuest::find($id) : null;

        if ($guest === null) {
            return AssistantGuest::create(['last_seen_at' => now()]);
        }

        $guest->forceFill(['last_seen_at' => now()])->save();

        return $guest;
    }
}
