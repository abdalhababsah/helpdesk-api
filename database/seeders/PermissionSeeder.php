<?php

namespace Database\Seeders;

use App\Authorization\PermissionMatrix;
use App\Enums\PermissionSlug;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = PermissionMatrix::catalogue();

        foreach ($catalogue as $slug => $description) {
            $permission = PermissionSlug::from($slug);

            Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'resource' => $permission->resource(),
                    'action' => $permission->action(),
                    'description' => $description,
                ],
            );
        }

        // Reconcile rather than only upsert. A permission left behind after it
        // was removed from the catalogue would be grantable with nothing
        // enforcing it, which is worse than not existing.
        Permission::whereNotIn('slug', array_keys($catalogue))->delete();
    }
}
