<?php

namespace App\Actions\Assistant;

use App\Ai\Agents\Concierge;
use App\Ai\AssistantContext;
use App\Ai\Cards\CardValidator;
use App\Enums\CardType;
use App\Exceptions\AssistantUnavailable;
use App\Exceptions\ConversationClosed;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * One turn of the conversation.
 *
 * The concierge is prompted inside its remembered conversation, the card it
 * proposes is checked against the database, and what was shown is written back
 * over what was proposed so the transcript and the screen cannot disagree.
 */
final class SendMessage
{
    /** One retry. A second failure is the provider, not a blip. */
    private const ATTEMPTS = 2;

    public function __construct(private readonly CardValidator $cards) {}

    /** @return array{reply: string, card: array<string, mixed>, session: AssistantSession} */
    public function handle(AssistantSession $session, User|AssistantGuest $participant, string $text): array
    {
        if (! $session->isOpen()) {
            throw new ConversationClosed;
        }

        $context = AssistantContext::for($participant);
        $response = $this->prompt($session, $participant, $context, $text);

        $structured = $response instanceof \ArrayAccess ? $response : null;
        $reply = trim((string) ($structured['reply'] ?? $response->text));
        $proposed = is_array($structured['card'] ?? null) ? $structured['card'] : [];
        $card = $this->cards->validate($proposed, $context->user());

        DB::transaction(function () use ($session, $response, $reply, $card): void {
            $this->rewriteLastReply($session, $reply, $card);

            $session->forceFill([
                'turns' => $session->turns + 1,
                'input_tokens' => $session->input_tokens + $response->usage->promptTokens,
                'output_tokens' => $session->output_tokens + $response->usage->completionTokens,
                'last_agent' => $this->lastAgent($response) ?? $session->last_agent,
                'last_draft' => $card['type'] === CardType::TicketDraft->value ? $card : $session->last_draft,
                'last_activity_at' => now(),
            ])->save();
        });

        return ['reply' => $reply, 'card' => $card, 'session' => $session->refresh()];
    }

    private function prompt(AssistantSession $session, User|AssistantGuest $participant, AssistantContext $context, string $text): AgentResponse
    {
        $failure = null;

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            try {
                return (new Concierge($context))
                    ->continue($session->conversation_id, as: $participant)
                    ->prompt($text);
            } catch (Throwable $e) {
                $failure = $e;
            }
        }

        Log::warning('assistant.unavailable', ['conversation' => $session->conversation_id, 'error' => $failure?->getMessage()]);

        throw new AssistantUnavailable;
    }

    /**
     * The package stores the model's raw output. Replacing it with the reply
     * and the validated card keeps the transcript honest: a card that was
     * rejected was never on screen, so it must not be in the history either.
     *
     * @param  array<string, mixed>  $card
     */
    private function rewriteLastReply(AssistantSession $session, string $reply, array $card): void
    {
        $latest = ConversationMessage::query()
            ->where('conversation_id', $session->conversation_id)
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $latest?->forceFill(['content' => json_encode(['reply' => $reply, 'card' => $card])])->save();
    }

    /** Which specialist answered, for the conversations report. */
    private function lastAgent(AgentResponse $response): ?string
    {
        return $response->toolCalls->last()?->name;
    }
}
