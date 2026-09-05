<?php

namespace App\Models;

use App\Enums\PermissionSlug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property PermissionSlug $slug
 * @property string $resource
 * @property string $action
 * @property string|null $description
 * @property Carbon $created_at
 */
class Permission extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    /** The table carries created_at only. */
    public const UPDATED_AT = null;

    protected $fillable = ['slug', 'resource', 'action', 'description'];

    protected function casts(): array
    {
        return [
            'slug' => PermissionSlug::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withPivot('scope');
    }
}
