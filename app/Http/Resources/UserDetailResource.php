<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The account page. The list resource plus the counts an administrator wants
 * before deleting someone: what they raised, what they still hold.
 *
 * @mixin User
 */
final class UserDetailResource extends UserResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'ticketsRaised' => (int) $this->requested_tickets_count,
            'ticketsAssigned' => (int) $this->assigned_tickets_count,
            'openTicketsAssigned' => (int) $this->open_assigned_tickets_count,
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
