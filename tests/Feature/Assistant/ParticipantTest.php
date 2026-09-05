<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantGuest;
use App\Models\User;
use App\Support\AssistantParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ParticipantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        Route::middleware(['api', 'auth.optional'])->get('/api/_participant', function (Request $request) {
            $participant = app(AssistantParticipant::class)->resolve($request);

            return ['type' => $participant->getMorphClass(), 'id' => $participant->getKey()];
        });
    }

    public function test_a_signed_in_person_is_the_participant(): void
    {
        $user = User::factory()->create(['email' => 'p@example.test']);
        $token = $this->login('p@example.test')['token'];

        $this->asUser($token)->getJson('/api/_participant')
            ->assertOk()->assertJson(['type' => 'user', 'id' => $user->id]);
    }

    public function test_a_guest_is_created_then_recognised(): void
    {
        $first = $this->asGuest()->getJson('/api/_participant')->assertOk()->json();
        $this->assertSame('assistant_guest', $first['type']);

        $second = $this->asGuest()->withHeader(AssistantParticipant::HEADER, $first['id'])->getJson('/api/_participant')->json();
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, AssistantGuest::count());
    }

    public function test_a_forged_guest_id_is_replaced(): void
    {
        $this->asGuest()->withHeader(AssistantParticipant::HEADER, '01ARZ3NDEKTSV4RRFFQ69G5FAV')->getJson('/api/_participant')
            ->assertOk()->assertJsonMissing(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    }

    public function test_a_guest_request_after_a_signed_in_one_is_not_that_person(): void
    {
        User::factory()->create(['email' => 'p@example.test']);
        $token = $this->login('p@example.test')['token'];

        $signedIn = $this->asUser($token)->getJson('/api/_participant')->assertOk()->json();
        $this->assertSame('user', $signedIn['type']);

        // The actor is bound into the container for the duration of the process,
        // so dropping the token must drop the actor with it.
        $this->asGuest()->getJson('/api/_participant')->assertOk()->assertJsonPath('type', 'assistant_guest');
    }

    public function test_a_stale_token_is_refused_rather_than_treated_as_a_guest(): void
    {
        User::factory()->create(['email' => 's@example.test']);
        $token = $this->login('s@example.test')['token'];
        User::where('email', 's@example.test')->increment('token_version');

        $this->asUser($token)->getJson('/api/_participant')->assertUnauthorized();
    }
}
