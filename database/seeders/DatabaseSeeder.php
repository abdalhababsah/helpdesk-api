<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Order matters: grants need roles and permissions to exist, tickets
        // need users and categories.
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            CategorySeeder::class,
            KnowledgeArticleSeeder::class,
            UserSeeder::class,
            TicketSeeder::class,
        ]);
    }
}
