<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Category extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    protected $fillable = ['slug', 'name', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
