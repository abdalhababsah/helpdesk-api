<?php

namespace App\Models;

use App\Enums\RefreshRevokeReason;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $family_id
 * @property string $token
 * @property string|null $replaced_by_id
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property RefreshRevokeReason|null $revoked_reason
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class RefreshToken extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    /** The table carries created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'family_id',
        'token',
        'replaced_by_id',
        'expires_at',
        'revoked_at',
        'revoked_reason',
        'user_agent',
        'ip_address',
    ];

    /** The stored digest must never reach a response body. */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'revoked_reason' => RefreshRevokeReason::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The token that superseded this one during rotation.
     *
     * @return BelongsTo<self, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /**
     * The token this one superseded. Inverse of replacedBy.
     *
     * @return HasOne<self, $this>
     */
    public function replaces(): HasOne
    {
        return $this->hasOne(self::class, 'replaced_by_id');
    }
}
