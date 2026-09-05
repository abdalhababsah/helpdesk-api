<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\ChangeAccountRole;
use App\Actions\Accounts\SetAccountActive;
use App\Authorization\Actor;
use App\Authorization\PermissionRegistry;
use App\Enums\ActionType;
use App\Enums\RoleSlug;
use App\Models\ActionLog;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_a_malformed_token_is_rejected(): void
    {
        $this->asUser('not.a.jwt')->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_a_token_signed_with_another_secret_is_rejected(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@example.test']);

        $forged = JWT::encode([
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'sub' => $user->id,
            'iat' => time(),
            'exp' => time() + 600,
            'role' => 'admin',
            'ver' => 1,
        ], str_repeat('z', 40), 'HS256');

        $this->asUser($forged)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_a_token_minted_for_another_audience_is_rejected(): void
    {
        $user = User::factory()->admin()->create(['email' => 'admin@example.test']);

        // Same secret, different audience: another service sharing the key must
        // not be able to mint tokens this API accepts.
        $foreign = JWT::encode([
            'iss' => config('jwt.issuer'),
            'aud' => 'some-other-app',
            'sub' => $user->id,
            'iat' => time(),
            'exp' => time() + 600,
            'role' => 'admin',
            'ver' => 1,
        ], (string) config('jwt.secret'), 'HS256');

        $this->asUser($foreign)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        $this->travel(config('jwt.ttl_minutes') + 1)->minutes();

        $this->asUser($session['token'])->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_returns_the_user_and_their_grants(): void
    {
        User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $session = $this->login('sam@example.test');

        $response = $this->asUser($session['token'])->getJson('/api/auth/me')->assertOk();

        $response->assertJsonPath('data.user.role', 'moderator');

        $permissions = $response->json('data.permissions');
        $this->assertCount(6, $permissions);
        $this->assertSame('all', $permissions['ticket:read']);
        $this->assertArrayNotHasKey('ticket:delete', $permissions);
    }

    public function test_a_user_sees_their_own_scope_in_the_grants(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        $permissions = $this->asUser($session['token'])->getJson('/api/auth/me')->json('data.permissions');

        $this->assertSame('own', $permissions['ticket:read']);
        $this->assertSame('own', $permissions['ticket:comment']);
        // A user raises tickets through the assistant, so they hold no
        // ticket:create and the New ticket action never renders for them.
        $this->assertArrayNotHasKey('ticket:create', $permissions);
    }

    public function test_deactivation_takes_effect_on_the_next_request(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
        $target = User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        $this->asUser($session['token'])->getJson('/api/auth/me')->assertOk();

        app(SetAccountActive::class)->handle(
            Actor::for($admin->load('role'), app(PermissionRegistry::class)),
            $target,
            false,
        );

        $this->asUser($session['token'])->getJson('/api/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_ACCOUNT_DISABLED');
    }

    public function test_a_role_change_invalidates_the_token_immediately(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
        $target = User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $session = $this->login('sam@example.test');

        app(ChangeAccountRole::class)->handle(
            Actor::for($admin->load('role'), app(PermissionRegistry::class)),
            $target,
            Role::where('slug', RoleSlug::User->value)->value('id'),
        );

        // A demoted user holding a token that still claims moderator would keep
        // those permissions until it expired. token_version closes that window.
        $this->asUser($session['token'])->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_STALE');
    }

    public function test_logout_revokes_the_family_and_clears_the_cookie(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        $response = $this->withCredentials()
            ->withUnencryptedCookie(config('jwt.refresh.cookie'), $session['refresh'])
            ->postJson('/api/auth/logout')
            ->assertStatus(204);

        $this->assertSame(0, RefreshToken::whereNull('revoked_at')->count());
        // forget() emits the cookie with a past expiry rather than a value,
        // which is what actually removes it from the browser.
        $cleared = $response->getCookie(config('jwt.refresh.cookie'), false);
        $this->assertNotNull($cleared);
        $this->assertLessThan(time(), $cleared->getExpiresTime());
        $this->assertTrue(ActionLog::where('action', ActionType::LoggedOut)->exists());
    }

    public function test_logout_without_a_cookie_still_succeeds(): void
    {
        // Logging out twice is not an error, and reporting which tokens exist
        // would make this an oracle.
        $this->postJson('/api/auth/logout')->assertStatus(204);
    }

    public function test_logout_everywhere_invalidates_every_session(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);
        $phone = $this->login('jordan@example.test');
        $laptop = $this->login('jordan@example.test');

        $this->asUser($laptop['token'])->postJson('/api/auth/logout-all')->assertStatus(204);

        // Both access tokens die with the version bump, and both refresh
        // families are revoked.
        $this->asUser($phone['token'])->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_TOKEN_STALE');
        $this->assertSame(0, RefreshToken::whereNull('revoked_at')->count());
    }

    public function test_logging_out_one_device_leaves_the_other_signed_in(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);
        $phone = $this->login('jordan@example.test');
        $laptop = $this->login('jordan@example.test');

        $this->withCredentials()
            ->withUnencryptedCookie(config('jwt.refresh.cookie'), $laptop['refresh'])
            ->postJson('/api/auth/logout')->assertStatus(204);

        $this->asUser($phone['token'])->getJson('/api/auth/me')->assertOk();
    }
}
