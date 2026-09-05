<?php

namespace App\Http\Resources;

use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssistantSession */
final class AssistantSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $participant = $this->participant;

        return [
            'id' => $this->id,
            'outcome' => $this->outcome->value,
            'ticketId' => $this->ticket_id,
            'turns' => $this->turns,
            'participant' => [
                'type' => $this->participant_type,
                'id' => $this->participant_id,
                'name' => $participant instanceof User ? $participant->name : 'Guest',
            ],
            'lastAgent' => $this->last_agent,
            'tokens' => ['input' => $this->input_tokens, 'output' => $this->output_tokens],
            'lastActivityAt' => $this->last_activity_at->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
