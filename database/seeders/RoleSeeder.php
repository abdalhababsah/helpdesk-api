<?php

namespace Database\Seeders;

use App\Authorization\PermissionMatrix;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionMatrix::roles() as $slug => $meta) {
            Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'],
                    'is_system' => true,
                ],
            );
        }
    }
}
