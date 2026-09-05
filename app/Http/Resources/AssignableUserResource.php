<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The narrow projection behind the assignee picker.
 *
 * A separate shape from UserResource on purpose. Populating the picker from the
 * account list would hand every moderator the full directory including email
 * addresses, to answer a question that only needs names.
 *
 * @mixin User
 */
final class AssignableUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role->slug->value,
        ];
    }
}
