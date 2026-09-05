<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;

/** @mixin Ticket */
final class TicketDetailResource extends TicketResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'description' => $this->description,
            'closedAt' => $this->closed_at?->toIso8601String(),
            'comments' => TicketCommentResource::collection($this->whenLoaded('comments')),
        ];
    }
}
