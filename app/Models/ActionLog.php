<?php

namespace App\Models;

use App\Enums\ActionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ActionType $action
 * @property string|null $actor_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class ActionLog extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids, MassPrunable;

    /** An entry describes a moment, so there is nothing to update. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'action',
        'actor_id',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'action' => ActionType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The log grows without bound otherwise. Pruning is scheduled rather than
     * automatic, so retention is an explicit operational choice.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('audit.retention_days', 365)));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
