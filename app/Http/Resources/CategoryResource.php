<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
final class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'isActive' => $this->is_active,
            // withCount('tickets') populates tickets_count, so it is only
            // present on the listing that asks for it.
            'ticketCount' => $this->when($this->tickets_count !== null, fn (): int => (int) $this->tickets_count),
        ];
    }
}
