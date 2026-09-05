<?php

namespace Database\Factories;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // The model casts this, so the factory hands over the plaintext and
            // never has to know which algorithm is configured.
            'password' => 'Passw0rd!',
            'role_id' => fn (): string => $this->roleId(RoleSlug::User),
            'is_active' => true,
        ];
    }

    public function role(RoleSlug $role): static
    {
        return $this->state(fn (): array => ['role_id' => $this->roleId($role)]);
    }

    public function admin(): static
    {
        return $this->role(RoleSlug::Admin);
    }

    public function moderator(): static
    {
        return $this->role(RoleSlug::Moderator);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Resolved from the seeded rows rather than created on demand. A role
     * invented here would carry no grants, so a test using it would pass
     * against a configuration the application never ships.
     */
    private function roleId(RoleSlug $role): string
    {
        $id = Role::where('slug', $role->value)->value('id');

        if ($id === null) {
            throw new RuntimeException(
                "Role [{$role->value}] does not exist. Seed roles and permissions before using UserFactory."
            );
        }

        return $id;
    }
}
