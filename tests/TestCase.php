<?php

namespace Tests;

use Database\Seeders\CategorySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles, permissions and grants are reference data, not fixtures. Every
     * test needs them, and building them by hand per test would let a test
     * pass against a matrix the application does not actually ship.
     */
    protected function seedAuthorization(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    protected function seedCategories(): void
    {
        $this->seed(CategorySeeder::class);
    }

    /** @return array{token: string, refresh: string} */
    protected function login(string $email, string $password = 'Passw0rd!'): array
    {
        $response = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        $response->assertOk();

        return [
            'token' => $response->json('data.accessToken'),
            'refresh' => $response->getCookie(config('jwt.refresh.cookie'), false)->getValue(),
        ];
    }

    /** @param  array<string, mixed>  $headers */
    protected function asUser(string $token, array $headers = []): static
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"] + $headers);
    }
}
