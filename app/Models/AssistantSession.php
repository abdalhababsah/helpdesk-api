<?php

namespace App\Models;

use App\Enums\AssistantOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * What the helpdesk tracks about one conversation: who it was with, how it
 * ended, and what it cost.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $participant_type
 * @property string $participant_id
 * @property AssistantOutcome $outcome
 * @property string|null $ticket_id
 * @property int $turns
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $last_agent
 * @property array<string, mixed>|null $last_draft
 * @property Carbon $last_activity_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AssistantSession extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    protected $fillable = [
        'conversation_id',
        'participant_type',
        'participant_id',
        'outcome',
        'ticket_id',
        'turns',
        'input_tokens',
        'output_tokens',
        'last_agent',
        'last_draft',
        'last_activity_at',
    ];

    protected $attributes = [
        'outcome' => 'open',
        'turns' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
    ];

    protected function casts(): array
    {
        return [
            'outcome' => AssistantOutcome::class,
            'last_draft' => 'array',
            'last_activity_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class)->withTrashed();
    }

    /** @param  Builder<static>  $query */
    public function scopeFor(Builder $query, User|AssistantGuest $participant): void
    {
        $query->where('participant_type', $participant->getMorphClass())
            ->where('participant_id', (string) $participant->getKey());
    }

    /** Whether this conversation belongs to the given person or guest. */
    public function isWith(User|AssistantGuest $participant): bool
    {
        return $this->participant_type === $participant->getMorphClass()
            && $this->participant_id === (string) $participant->getKey();
    }

    public function isOpen(): bool
    {
        return $this->outcome === AssistantOutcome::Open;
    }
}
