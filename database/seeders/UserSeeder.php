<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const PASSWORD = 'Passw0rd!';

    /** @var list<array{name: string, email: string, role: RoleSlug, active?: bool}> */
    private const ACCOUNTS = [
        ['name' => 'Amina Admin', 'email' => 'admin@example.com', 'role' => RoleSlug::Admin],
        ['name' => 'Sam Support', 'email' => 'sam@example.com', 'role' => RoleSlug::Moderator],
        ['name' => 'Priya Agent', 'email' => 'priya@example.com', 'role' => RoleSlug::Moderator],
        ['name' => 'Jordan Employee', 'email' => 'jordan@example.com', 'role' => RoleSlug::User],
        ['name' => 'Omar Haddad', 'email' => 'omar@example.com', 'role' => RoleSlug::User],
        ['name' => 'Lena Fischer', 'email' => 'lena@example.com', 'role' => RoleSlug::User],
        ['name' => 'Tariq Nasser', 'email' => 'tariq@example.com', 'role' => RoleSlug::User],
        // Deactivated so the disabled-login path is demonstrable without
        // having to break a working account first.
        ['name' => 'Dana Former', 'email' => 'dana@example.com', 'role' => RoleSlug::User, 'active' => false],
    ];

    public function run(): void
    {
        $roleIds = Role::pluck('id', 'slug');

        foreach (self::ACCOUNTS as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    // Always reset, so the documented credentials work after any run.
                    'password' => self::PASSWORD,
                    'role_id' => $roleIds[$account['role']->value],
                    'is_active' => $account['active'] ?? true,
                ],
            );
        }
    }
}
