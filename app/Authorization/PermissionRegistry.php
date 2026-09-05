<?php

namespace App\Authorization;

use App\Enums\PermissionScope;
use Illuminate\Support\Facades\DB;

/**
 * Holds the grant matrix in memory for the life of the process.
 *
 * Authorization is checked on nearly every request, so reading it from the
 * database each time would put a join on the hot path to answer a question
 * whose answer changes only when an administrator edits it. Roughly twenty
 * rows, loaded once.
 *
 * Single process is assumed. Behind several workers, an edit is only seen by
 * the one that served it until the others restart; making that correct needs a
 * shared invalidation channel.
 */
final class PermissionRegistry
{
    /** @var array<string, array<string, PermissionScope>>|null */
    private ?array $matrix = null;

    /**
     * Grants held by a role, keyed by permission slug. An absent key is denied.
     *
     * @return array<string, PermissionScope>
     */
    public function grantsFor(string $roleSlug): array
    {
        return $this->matrix()[$roleSlug] ?? [];
    }

    /** @return array<string, array<string, PermissionScope>> */
    private function matrix(): array
    {
        if ($this->matrix !== null) {
            return $this->matrix;
        }

        $rows = DB::table('role_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->select('r.slug as role_slug', 'p.slug as permission_slug', 'rp.scope')
            ->get();

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[$row->role_slug][$row->permission_slug] = PermissionScope::from($row->scope);
        }

        return $this->matrix = $matrix;
    }
}
