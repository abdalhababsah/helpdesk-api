<?php

namespace Database\Seeders;

use App\Authorization\PermissionMatrix;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roleIds = Role::pluck('id', 'slug');
        $permissionIds = Permission::pluck('id', 'slug');

        foreach (PermissionMatrix::grants() as $roleSlug => $grants) {
            $roleId = $roleIds[$roleSlug];

            $desired = [];
            foreach ($grants as $permissionSlug => $scope) {
                $desired[$permissionIds[$permissionSlug]] = $scope->value;
            }

            // Grants removed from the matrix must disappear from the database,
            // otherwise a revoked permission stays live.
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->whereNotIn('permission_id', array_keys($desired))
                ->delete();

            $existing = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->pluck('scope', 'permission_id');

            foreach ($desired as $permissionId => $scope) {
                if (! isset($existing[$permissionId])) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'scope' => $scope,
                        'created_at' => now(),
                    ]);
                } elseif ($existing[$permissionId] !== $scope) {
                    // Update the scope only. Rewriting created_at on every run
                    // would make the seeder look like it changed something.
                    DB::table('role_permissions')
                        ->where('role_id', $roleId)
                        ->where('permission_id', $permissionId)
                        ->update(['scope' => $scope]);
                }
            }
        }
    }
}
