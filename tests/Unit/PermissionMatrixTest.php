<?php

namespace Tests\Unit;

use App\Authorization\PermissionMatrix;
use App\Enums\PermissionScope;
use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every cell of the grant table, asserted one at a time.
 *
 * Generated rather than hand written. Thirty assertions typed out by hand
 * invite a copy-paste error, and a copy-paste error in a permission test is
 * invisible: the test still passes, it just stops checking the thing it names.
 */
final class PermissionMatrixTest extends TestCase
{
    /**
     * Transcribed from the brief's own permission table. This is the reference,
     * not a copy of the implementation, so a change to the implementation that
     * was not asked for will fail here.
     *
     * @return array<string, array<string, string|null>>
     */
    private static function expected(): array
    {
        return [
            'ticket:create' => ['user' => 'all', 'moderator' => 'all', 'admin' => 'all'],
            'ticket:read' => ['user' => 'own', 'moderator' => 'all', 'admin' => 'all'],
            'ticket:comment' => ['user' => 'own', 'moderator' => 'all', 'admin' => 'all'],
            'ticket:list_queue' => ['user' => null, 'moderator' => 'all', 'admin' => 'all'],
            'ticket:assign' => ['user' => null, 'moderator' => 'all', 'admin' => 'all'],
            'ticket:triage' => ['user' => null, 'moderator' => 'all', 'admin' => 'all'],
            'ticket:delete' => ['user' => null, 'moderator' => null, 'admin' => 'all'],
            'category:manage' => ['user' => null, 'moderator' => null, 'admin' => 'all'],
            'account:manage' => ['user' => null, 'moderator' => null, 'admin' => 'all'],
            'metrics:read' => ['user' => null, 'moderator' => null, 'admin' => 'all'],
        ];
    }

    /** @return array<string, array{string, string, string|null}> */
    public static function cells(): array
    {
        $cases = [];
        foreach (self::expected() as $permission => $roles) {
            foreach ($roles as $role => $scope) {
                $cases["{$role} / {$permission}"] = [$role, $permission, $scope];
            }
        }

        return $cases;
    }

    #[DataProvider('cells')]
    public function test_each_grant_matches_the_brief(string $role, string $permission, ?string $scope): void
    {
        $granted = PermissionMatrix::grants()[$role][$permission] ?? null;

        $this->assertSame($scope, $granted?->value, "{$role} / {$permission}");
    }

    public function test_the_matrix_covers_every_role_and_no_others(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (RoleSlug $r): string => $r->value, RoleSlug::cases()),
            array_keys(PermissionMatrix::grants()),
        );
    }

    public function test_the_catalogue_covers_every_permission_and_no_others(): void
    {
        // A permission the code can ask for but the catalogue never seeds would
        // be permanently denied; one in the catalogue that nothing asks for is
        // a grant with nothing behind it.
        $this->assertEqualsCanonicalizing(
            array_map(fn (PermissionSlug $p): string => $p->value, PermissionSlug::cases()),
            array_keys(PermissionMatrix::catalogue()),
        );
    }

    public function test_every_granted_scope_is_a_real_scope(): void
    {
        foreach (PermissionMatrix::grants() as $role => $grants) {
            foreach ($grants as $permission => $scope) {
                $this->assertInstanceOf(PermissionScope::class, $scope, "{$role} / {$permission}");
            }
        }
    }

    public function test_only_the_owner_scoped_permissions_use_own(): void
    {
        $own = [];
        foreach (PermissionMatrix::grants() as $role => $grants) {
            foreach ($grants as $permission => $scope) {
                if ($scope === PermissionScope::Own) {
                    $own[] = "{$role}:{$permission}";
                }
            }
        }

        // Scope own is only meaningful where the thing being acted on has an
        // owner. Anywhere else it would silently deny.
        $this->assertEqualsCanonicalizing(['user:ticket:read', 'user:ticket:comment'], $own);
    }
}
