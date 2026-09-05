<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Reads a conversation back as the person saw it.
 *
 * The assistant's stored content is the structured reply, so it is unpacked
 * here. What was stored is what was shown: the card is the validated one, not
 * whatever the model first proposed.
 */
final class AssistantTranscript
{
    /** @return Collection<int, array{id: string, role: string, text: string, card: array<string, mixed>|null, createdAt: string}> */
    public function for(string $conversationId): Collection
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (ConversationMessage $message): array {
                $decoded = $message->role === 'assistant' ? json_decode((string) $message->content, true) : null;

                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'text' => is_array($decoded) ? (string) ($decoded['reply'] ?? '') : (string) $message->content,
                    'card' => is_array($decoded) && is_array($decoded['card'] ?? null) ? $decoded['card'] : null,
                    'createdAt' => $message->created_at->toIso8601String(),
                ];
            })
            ->values();
    }
}
