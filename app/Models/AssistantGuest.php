<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An anonymous person talking to the assistant. Identified only by the id the
 * browser keeps; nothing about them is stored.
 *
 * @property string $id
 * @property Carbon $last_seen_at
 */
class AssistantGuest extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    protected $fillable = ['last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
