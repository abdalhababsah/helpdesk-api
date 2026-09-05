<?php

namespace Tests\Feature\Auth;

use App\Enums\ActionType;
use App\Enums\RefreshRevokeReason;
use App\Models\ActionLog;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    private function refreshWith(string $raw)
    {
        // withCredentials mirrors the browser: a JSON request carries no
        // cookies unless the client asks for them, which is why the frontend
        // fetch must set credentials: 'include'.
        // Unencrypted because API routes do not run EncryptCookies, so the
        // refresh cookie is a plain opaque value in both directions.
        return $this->withCredentials()
            ->withUnencryptedCookie(config('jwt.refresh.cookie'), $raw)
            ->postJson('/api/auth/refresh');
    }

    public function test_it_rotates_the_token_and_revokes_the_one_presented(): void
    {
        User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $first = $this->login('sam@example.test');

        $response = $this->refreshWith($first['refresh'])->assertOk();
        $second = $response->getCookie(config('jwt.refresh.cookie'), false)->getValue();

        $this->assertNotSame($first['refresh'], $second);

        $parent = RefreshToken::where('token', hash('sha256', $first['refresh']))->firstOrFail();
        $child = RefreshToken::where('token', hash('sha256', $second))->firstOrFail();

        $this->assertNotNull($parent->revoked_at);
        $this->assertSame(RefreshRevokeReason::Rotated, $parent->revoked_reason);
        $this->assertSame($child->id, $parent->replaced_by_id);
        // One login is one family, however many times it rotates.
        $this->assertSame($parent->family_id, $child->family_id);
        $this->assertNull($child->revoked_at);
    }

    public function test_replaying_a_spent_token_burns_the_whole_family(): void
    {
        User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $first = $this->login('sam@example.test');

        $second = $this->refreshWith($first['refresh'])->assertOk()
            ->getCookie(config('jwt.refresh.cookie'), false)->getValue();

        // The first token was already spent. Presenting it again means it
        // leaked, or a client is broken; neither is distinguishable from here.
        $this->refreshWith($first['refresh'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REFRESH_INVALID');

        $this->assertSame(0, RefreshToken::whereNull('revoked_at')->count());
        $this->assertDatabaseHas('refresh_tokens', [
            'token' => hash('sha256', $second),
            'revoked_reason' => RefreshRevokeReason::ReuseDetected->value,
        ]);

        // The still-valid token from before the replay is dead too.
        $this->refreshWith($second)->assertStatus(401);
    }

    public function test_the_family_revocation_survives_the_rejected_request(): void
    {
        User::factory()->moderator()->create(['email' => 'sam@example.test']);
        $first = $this->login('sam@example.test');
        $this->refreshWith($first['refresh'])->assertOk();

        $this->refreshWith($first['refresh'])->assertStatus(401);

        // Throwing from inside the transaction would have rolled this back,
        // leaving a token known to be compromised still usable.
        $this->assertTrue(ActionLog::where('action', ActionType::TokenReuseDetected)->exists());
        $this->assertSame(0, RefreshToken::whereNull('revoked_at')->count());
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->refreshWith('not-a-real-token')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REFRESH_INVALID');
    }

    public function test_an_expired_token_is_rejected_and_marked(): void
    {
        User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        RefreshToken::where('token', hash('sha256', $session['refresh']))
            ->update(['expires_at' => now()->subDay()]);

        $this->refreshWith($session['refresh'])->assertStatus(401);

        $this->assertDatabaseHas('refresh_tokens', [
            'token' => hash('sha256', $session['refresh']),
            'revoked_reason' => RefreshRevokeReason::Expired->value,
        ]);
    }

    public function test_a_deactivated_user_cannot_refresh(): void
    {
        $user = User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        $user->update(['is_active' => false]);

        $this->refreshWith($session['refresh'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_ACCOUNT_DISABLED');
    }

    public function test_a_refreshed_access_token_carries_the_current_role(): void
    {
        $user = User::factory()->create(['email' => 'jordan@example.test']);
        $session = $this->login('jordan@example.test');

        $this->asUser($session['token'])->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.user.role', 'user');

        // Promote without touching sessions, so the old token stays valid and
        // only the refresh should pick the change up.
        $user->update(['role_id' => Role::where('slug', 'moderator')->value('id')]);

        $this->refreshWith($session['refresh'])->assertOk()
            ->assertJsonPath('data.user.role', 'moderator');
    }
}
