<?php

namespace App\Actions\Assistant;

use App\Actions\Concerns\RecordsActions;
use App\Enums\ActionType;
use App\Exceptions\ConversationClosed;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * A guest who signs in keeps the conversation they were having.
 *
 * Only the guest that was talking can hand it over, proven by the identifier
 * the browser holds, and only while it is still open. Without that a signed-in
 * person could adopt any guest conversation whose id they guessed.
 */
final class ClaimConversation
{
    use RecordsActions;

    public function handle(AssistantSession $session, AssistantGuest $guest, User $user): AssistantSession
    {
        if (! $session->isWith($guest)) {
            throw new AuthorizationException('This conversation is not yours.');
        }

        if (! $session->isOpen()) {
            throw new ConversationClosed;
        }

        return DB::transaction(function () use ($session, $user): AssistantSession {
            $type = Conversation::participantType($user);
            $key = (string) Conversation::participantKey($user);

            Conversation::whereKey($session->conversation_id)->update(['participant_type' => $type, 'participant_id' => $key]);
            ConversationMessage::where('conversation_id', $session->conversation_id)
                ->update(['participant_type' => $type, 'participant_id' => $key]);

            $session->forceFill([
                'participant_type' => $user->getMorphClass(),
                'participant_id' => (string) $user->getKey(),
                'last_activity_at' => now(),
            ])->save();

            $this->record(ActionType::ConversationClaimed, $user, $session, ['conversation_id' => $session->conversation_id]);

            return $session;
        });
    }
}
