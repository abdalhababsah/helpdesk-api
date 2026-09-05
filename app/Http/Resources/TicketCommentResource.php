<?php

namespace App\Http\Resources;

use App\Models\TicketComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketComment */
final class TicketCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'author' => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                // Included so the interface can distinguish an agent's reply
                // from the requester's without another lookup.
                'role' => $this->author->role->slug->value,
            ],
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
