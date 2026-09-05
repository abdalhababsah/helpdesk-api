<?php

namespace Tests\Feature\Auth;

use App\Enums\ActionType;
use App\Models\ActionLog;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_it_issues_an_access_token_and_a_refresh_cookie(): void
    {
        $user = User::factory()->moderator()->create(['email' => 'sam@example.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'sam@example.test',
            'password' => 'Passw0rd!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'sam@example.test')
            ->assertJsonPath('data.user.role', 'moderator')
            ->assertJsonStructure(['data' => ['user', 'accessToken', 'expiresIn']]);

        $cookie = $response->getCookie(config('jwt.refresh.cookie'), false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/auth', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseCount('refresh_tokens', 1);
    }

    public function test_the_raw_refresh_token_is_never_stored(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        $this->assertDatabaseMissing('refresh_tokens', ['token' => $session['refresh']]);
        $this->assertDatabaseHas('refresh_tokens', ['token' => hash('sha256', $session['refresh'])]);
    }

    public function test_it_never_returns_the_password(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jordan@example.test',
            'password' => 'Passw0rd!',
        ]);

        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_a_wrong_password_is_rejected_ambiguously(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'jordan@example.test', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
    }

    public function test_an_unknown_address_returns_the_same_error_as_a_wrong_password(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'Passw0rd!'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
    }

    public function test_a_deactivated_account_is_told_so_only_after_the_password_verifies(): void
    {
        User::factory()->inactive()->create(['email' => 'dana@example.test']);

        // Wrong password on a disabled account must not reveal that it exists.
        $this->postJson('/api/auth/login', ['email' => 'dana@example.test', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');

        $this->postJson('/api/auth/login', ['email' => 'dana@example.test', 'password' => 'Passw0rd!'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_ACCOUNT_DISABLED');

        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_login_is_case_insensitive_on_the_address(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'JORDAN@Example.Test', 'password' => 'Passw0rd!'])
            ->assertOk();
    }

    public function test_a_missing_field_fails_validation_with_field_detail(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'jordan@example.test'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'password');
    }

    public function test_failures_and_successes_are_both_recorded(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);

        $this->postJson('/api/auth/login', ['email' => 'jordan@example.test', 'password' => 'wrong']);
        $this->postJson('/api/auth/login', ['email' => 'jordan@example.test', 'password' => 'Passw0rd!']);

        $this->assertTrue(ActionLog::where('action', ActionType::LoginFailed)->exists());
        $this->assertTrue(ActionLog::where('action', ActionType::LoginSucceeded)->exists());
    }

    public function test_a_failed_login_survives_because_it_is_not_in_a_transaction(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'ghost@example.test', 'password' => 'whatever']);

        $entry = ActionLog::where('action', ActionType::LoginFailed)->firstOrFail();
        $this->assertNull($entry->actor_id);
        $this->assertSame('ghost@example.test', $entry->properties['email']);
        $this->assertSame(0, RefreshToken::count());
    }
}
