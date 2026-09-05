<?php

namespace App\Models;

use App\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property RoleSlug $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Role extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    protected $fillable = ['slug', 'name', 'description', 'is_system'];

    protected function casts(): array
    {
        return [
            // Cast deliberately: the three rows are seeded and is_system blocks
            // re-slugging, so a slug outside the enum means the seed and the code
            // have diverged and should fail loudly rather than deny silently.
            'slug' => RoleSlug::class,
            'is_system' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * No withTimestamps: the pivot carries created_at only.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot('scope');
    }
}
