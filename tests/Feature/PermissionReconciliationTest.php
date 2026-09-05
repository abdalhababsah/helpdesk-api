<?php

namespace Tests\Feature;

use App\Authorization\PermissionMatrix;
use App\Enums\PermissionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database grant table must equal the one in code, in both directions.
 *
 * Putting grants in rows gave up the guarantee a compiler would have given:
 * a missing row simply ships, and nothing complains. This test is the
 * replacement. Without it the only thing keeping the two in step is that the
 * seeder happens to read from the same source, and a grant edited directly in
 * a deployed database would go unnoticed.
 */
final class PermissionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    /** @return array<string, array<string, string>> */
    private function persisted(): array
    {
        return DB::table('role_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->select('r.slug as role', 'p.slug as permission', 'rp.scope')
            ->get()
            ->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('scope', 'permission')->sortKeys()->all())
            ->sortKeys()
            ->all();
    }

    /** @return array<string, array<string, string>> */
    private function declared(): array
    {
        $out = [];
        foreach (PermissionMatrix::grants() as $role => $grants) {
            $out[$role] = collect($grants)
                ->map(fn (PermissionScope $scope): string => $scope->value)
                ->sortKeys()->all();
        }
        ksort($out);

        return $out;
    }

    public function test_the_database_matches_the_code_exactly(): void
    {
        // Not a subset check in either direction: an extra grant is as wrong as
        // a missing one, because it hands out permission nobody decided to give.
        $this->assertSame($this->declared(), $this->persisted());
    }

    public function test_a_grant_removed_from_the_code_is_removed_from_the_database(): void
    {
        $before = DB::table('role_permissions')->count();

        DB::table('role_permissions')->insert([
            'role_id' => DB::table('roles')->where('slug', 'user')->value('id'),
            'permission_id' => DB::table('permissions')->where('slug', 'ticket:delete')->value('id'),
            'scope' => 'all',
            'created_at' => now(),
        ]);

        // Re-running must take the stray grant away again, not leave it because
        // it only ever adds what is missing.
        $this->seedAuthorization();

        $this->assertSame($before, DB::table('role_permissions')->count());
        $this->assertSame($this->declared(), $this->persisted());
    }

    public function test_a_changed_scope_is_corrected(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission_id', DB::table('permissions')->where('slug', 'ticket:read')->pluck('id'))
            ->update(['scope' => 'all']);

        $this->seedAuthorization();

        $this->assertSame('own', $this->persisted()['user']['ticket:read']);
    }

    public function test_the_permission_catalogue_matches_the_code(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(PermissionMatrix::catalogue()),
            DB::table('permissions')->pluck('slug')->all(),
        );
    }

    public function test_the_seeded_roles_match_the_code(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(PermissionMatrix::roles()),
            DB::table('roles')->pluck('slug')->all(),
        );
    }
}
