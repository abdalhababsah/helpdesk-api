<?php

namespace App\Models;

use App\Authorization\Ownable;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $subject
 * @property string $description
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property string $category_id
 * @property string $requester_id
 * @property string|null $assignee_id
 * @property Carbon $due_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read bool $is_overdue
 * @property-read int|null $comments_count
 */
class Ticket extends Model implements Ownable
{
    /** @use HasFactory<TicketFactory> */
    use Concerns\HasMillisecondTimestamps, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'subject',
        'description',
        'status',
        'priority',
        'category_id',
        'requester_id',
        'assignee_id',
        'due_at',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Derived here so the rule lives in one place. A client recomputing it from
     * due_at would drift the moment the definition changes.
     *
     * @return Attribute<bool, never>
     */
    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->due_at->isPast()
            && in_array($this->status, TicketStatus::open(), true));
    }

    /** Ownership for scope-limited grants is the requester, never the assignee. */
    public function ownerId(): string
    {
        return $this->requester_id;
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }
}
