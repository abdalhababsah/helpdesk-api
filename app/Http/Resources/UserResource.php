<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->slug->value,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at->toIso8601String(),
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
