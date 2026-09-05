<?php

namespace App\Actions\Assistant;

use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

/**
 * Opens a conversation before the first word is typed.
 *
 * Creating it up front rather than on the first message gives the browser an
 * id to keep straight away, and means the tracking row exists for a
 * conversation that is abandoned before anything is said.
 */
final class StartConversation
{
    public function __construct(private readonly ConversationStore $store) {}

    public function handle(User|AssistantGuest $participant): AssistantSession
    {
        return DB::transaction(function () use ($participant): AssistantSession {
            $conversationId = $this->store->storeConversation(
                Conversation::participantType($participant),
                Conversation::participantKey($participant),
                'Support conversation',
            );

            return AssistantSession::create([
                'conversation_id' => $conversationId,
                'participant_type' => $participant->getMorphClass(),
                'participant_id' => (string) $participant->getKey(),
                'last_activity_at' => now(),
            ]);
        });
    }
}
