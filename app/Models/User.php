<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role_id
 * @property bool $is_active
 * @property int $token_version
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Concerns\HasMillisecondTimestamps, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'is_active'];

    /**
     * Mirrors the column defaults. Eloquent does not read them back after an
     * insert, so without this a freshly created user carries a null
     * token_version, and a token minted from it would be stale on first use.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'token_version' => 1,
    ];

    /**
     * The column is named password, not password_hash, so an accidental
     * serialization would not stand out in review. Hiding it here means a
     * response can only leak it if someone opts back in explicitly.
     */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'token_version' => 'integer',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function requestedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'requester_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assignee_id');
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'author_id');
    }

    /** @return HasMany<PasswordResetToken, $this> */
    public function passwordResetTokens(): HasMany
    {
        return $this->hasMany(PasswordResetToken::class);
    }

    /** @return HasMany<RefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }
}
