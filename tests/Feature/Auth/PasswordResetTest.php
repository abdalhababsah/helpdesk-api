<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use App\Models\User;
use App\Notifications\PasswordChanged;
use App\Notifications\PasswordResetLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        Notification::fake();

        $this->person = User::factory()->create(['email' => 'jordan@example.test']);
    }

    public function test_forgot_password_answers_the_same_whether_or_not_the_address_exists(): void
    {
        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'jordan@example.test']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());

        Notification::assertSentTo($this->person, PasswordResetLink::class);
        Notification::assertCount(1);
    }

    public function test_a_deactivated_account_gets_no_link(): void
    {
        $this->person->update(['is_active' => false]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'jordan@example.test'])->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_the_link_sets_a_new_password_once_and_ends_every_session(): void
    {
        $session = $this->login('jordan@example.test');
        $raw = $this->requestLink();

        $this->postJson('/api/auth/reset-password', [
            'token' => $raw,
            'email' => 'jordan@example.test',
            'password' => 'BrandNew2Password',
            'password_confirmation' => 'BrandNew2Password',
        ])->assertNoContent();

        Notification::assertSentTo($this->person, PasswordChanged::class);

        // Old password dead, new one live, old sessions gone.
        $this->postJson('/api/auth/login', ['email' => 'jordan@example.test', 'password' => 'Passw0rd!'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'jordan@example.test', 'password' => 'BrandNew2Password'])->assertOk();
        $this->asUser($session['token'])->getJson('/api/auth/me')->assertUnauthorized();

        // Single use.
        $this->postJson('/api/auth/reset-password', [
            'token' => $raw,
            'email' => 'jordan@example.test',
            'password' => 'Another3Password',
            'password_confirmation' => 'Another3Password',
        ])->assertStatus(400)->assertJsonPath('error.code', 'RESET_LINK_INVALID');
    }

    public function test_an_expired_link_or_the_wrong_address_is_refused(): void
    {
        $raw = $this->requestLink();

        $this->postJson('/api/auth/reset-password', [
            'token' => $raw, 'email' => 'someone-else@example.test',
            'password' => 'BrandNew2Password', 'password_confirmation' => 'BrandNew2Password',
        ])->assertStatus(400);

        PasswordResetToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $raw, 'email' => 'jordan@example.test',
            'password' => 'BrandNew2Password', 'password_confirmation' => 'BrandNew2Password',
        ])->assertStatus(400);

        $this->postJson('/api/auth/login', ['email' => 'jordan@example.test', 'password' => 'Passw0rd!'])->assertOk();
    }

    public function test_a_newer_request_retires_the_older_link(): void
    {
        $first = $this->requestLink();
        $second = $this->requestLink();

        $body = fn (string $raw) => ['token' => $raw, 'email' => 'jordan@example.test', 'password' => 'BrandNew2Password', 'password_confirmation' => 'BrandNew2Password'];

        $this->postJson('/api/auth/reset-password', $body($first))->assertStatus(400);
        $this->postJson('/api/auth/reset-password', $body($second))->assertNoContent();
    }

    public function test_a_weak_password_is_rejected_with_field_errors(): void
    {
        $raw = $this->requestLink();

        $this->postJson('/api/auth/reset-password', [
            'token' => $raw, 'email' => 'jordan@example.test', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'password');
    }

    /** Asks for a link and reads the raw token out of the faked notification. */
    private function requestLink(): string
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'jordan@example.test'])->assertStatus(202);

        $raw = null;
        Notification::assertSentTo($this->person, PasswordResetLink::class, function (PasswordResetLink $mail) use (&$raw): bool {
            parse_str((string) parse_url($mail->link, PHP_URL_QUERY), $query);
            $raw = $query['token'];

            return true;
        });

        return (string) $raw;
    }
}
