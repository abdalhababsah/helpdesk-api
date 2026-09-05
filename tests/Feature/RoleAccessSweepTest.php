<?php

namespace Tests\Feature;

use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\Category;
use App\Models\KnowledgeArticle;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every protected endpoint, called as every role plus anonymously, asserting
 * the exact status.
 *
 * This is the test that performs the bypass attempt directly rather than
 * through the interface, and the only one that catches an endpoint which never
 * checks permission at all: a unit test of the matrix cannot notice a route
 * that forgets to consult it.
 */
final class RoleAccessSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $tokens = [];

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        $this->requester = User::factory()->create(['email' => 'user@example.test']);
        User::factory()->moderator()->create(['email' => 'moderator@example.test']);
        User::factory()->admin()->create(['email' => 'admin@example.test']);

        foreach (['user', 'moderator', 'admin'] as $role) {
            $this->tokens[$role] = $this->login("{$role}@example.test")['token'];
        }
    }

    public function test_every_endpoint_enforces_its_permissions(): void
    {
        $failures = [];

        foreach ($this->matrix() as $label => $case) {
            foreach (['anonymous', 'user', 'moderator', 'admin'] as $role) {
                [$method, $uri, $payload] = ($case['request'])($role);

                $request = $role === 'anonymous' ? $this->asGuest() : $this->asUser($this->tokens[$role]);
                $status = $request->json($method, $uri, $payload)->getStatusCode();

                if ($status !== $case['expect'][$role]) {
                    $failures[] = sprintf(
                        '%s as %s: expected %d, got %d',
                        $label, $role, $case['expect'][$role], $status,
                    );
                }
            }
        }

        $this->assertSame([], $failures, "\n".implode("\n", $failures)."\n");
    }

    /**
     * Fixtures are rebuilt per call because several of these mutate. A shared
     * ticket would let one case decide the outcome of the next.
     *
     * @return array<string, array{request: callable, expect: array<string, int>}>
     */
    private function matrix(): array
    {
        $ownTicket = fn (): Ticket => Ticket::factory()->requestedBy($this->requester)->create();
        $otherTicket = fn (): Ticket => Ticket::factory()->create();
        $category = fn (): Category => Category::factory()->create();
        $agentId = fn (): string => (string) User::whereRelation('role', 'slug', 'moderator')->value('id');
        $userRoleId = fn (): string => (string) Role::where('slug', 'user')->value('id');
        $otherSession = fn (): AssistantSession => AssistantSession::create([
            'conversation_id' => (string) Str::uuid7(),
            'participant_type' => 'assistant_guest',
            'participant_id' => AssistantGuest::create(['last_seen_at' => now()])->id,
            'last_activity_at' => now(),
        ]);

        return [
            'GET /tickets' => [
                'request' => fn () => ['GET', '/api/tickets', []],
                'expect' => ['anonymous' => 401, 'user' => 200, 'moderator' => 200, 'admin' => 200],
            ],
            'GET /tickets/summary' => [
                'request' => fn () => ['GET', '/api/tickets/summary', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 200, 'admin' => 200],
            ],
            'POST /tickets' => [
                'request' => fn () => ['POST', '/api/tickets', [
                    'subject' => 'A subject long enough',
                    'description' => 'A description that is long enough.',
                    'categoryId' => $category()->id,
                ]],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 201, 'admin' => 201],
            ],
            'GET /tickets/{own}' => [
                'request' => fn () => ['GET', '/api/tickets/'.$ownTicket()->id, []],
                'expect' => ['anonymous' => 401, 'user' => 200, 'moderator' => 200, 'admin' => 200],
            ],
            "GET /tickets/{someone else's}" => [
                'request' => fn () => ['GET', '/api/tickets/'.$otherTicket()->id, []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 200, 'admin' => 200],
            ],
            'PATCH /tickets/{id} status' => [
                'request' => fn () => ['PATCH', '/api/tickets/'.$ownTicket()->id, ['status' => 'in_progress']],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 200, 'admin' => 200],
            ],
            'PATCH /tickets/{id} assignee' => [
                'request' => fn () => ['PATCH', '/api/tickets/'.$ownTicket()->id, ['assigneeId' => $agentId()]],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 200, 'admin' => 200],
            ],
            'DELETE /tickets/{id}' => [
                'request' => fn () => ['DELETE', '/api/tickets/'.$otherTicket()->id, []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 204],
            ],
            'POST /tickets/{own}/comments' => [
                'request' => fn () => ['POST', '/api/tickets/'.$ownTicket()->id.'/comments', ['body' => 'A reply.']],
                'expect' => ['anonymous' => 401, 'user' => 201, 'moderator' => 201, 'admin' => 201],
            ],
            "POST /tickets/{someone else's}/comments" => [
                'request' => fn () => ['POST', '/api/tickets/'.$otherTicket()->id.'/comments', ['body' => 'A reply.']],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 201, 'admin' => 201],
            ],
            'GET /categories' => [
                'request' => fn () => ['GET', '/api/categories', []],
                'expect' => ['anonymous' => 401, 'user' => 200, 'moderator' => 200, 'admin' => 200],
            ],
            'GET /categories?includeRetired' => [
                'request' => fn () => ['GET', '/api/categories?includeRetired=1', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'POST /categories' => [
                'request' => fn (string $role) => ['POST', '/api/categories', ['name' => "Category {$role}"]],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 201],
            ],
            'PATCH /categories/{id}' => [
                'request' => fn () => ['PATCH', '/api/categories/'.$category()->id, ['name' => 'Renamed']],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'GET /roles' => [
                'request' => fn () => ['GET', '/api/roles', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'GET /users' => [
                'request' => fn () => ['GET', '/api/users', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'GET /users/assignable' => [
                'request' => fn () => ['GET', '/api/users/assignable', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 200, 'admin' => 200],
            ],
            'POST /users' => [
                'request' => fn (string $role) => ['POST', '/api/users', [
                    'name' => 'New Person',
                    'email' => "new-{$role}@example.test",
                    'password' => 'Sufficient1Password',
                    'roleId' => $userRoleId(),
                ]],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 201],
            ],
            'PATCH /users/{id}' => [
                'request' => fn () => ['PATCH', '/api/users/'.User::factory()->create()->id, ['isActive' => false]],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'GET /users/{id}' => [
                'request' => fn () => ['GET', '/api/users/'.User::factory()->create()->id, []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'DELETE /users/{id}' => [
                'request' => fn () => ['DELETE', '/api/users/'.User::factory()->create()->id, []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 204],
            ],
            'POST /users/{id}/password-reset' => [
                'request' => fn () => ['POST', '/api/users/'.User::factory()->create()->id.'/password-reset', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 202],
            ],
            // The assistant routes accept guests, so an anonymous caller is
            // refused for not owning the conversation rather than for not
            // being signed in.
            'POST /assistant/conversations' => [
                'request' => fn () => ['POST', '/api/assistant/conversations', []],
                'expect' => ['anonymous' => 201, 'user' => 201, 'moderator' => 201, 'admin' => 201],
            ],
            'GET /assistant/conversations/{someone else\'s}' => [
                'request' => fn () => ['GET', '/api/assistant/conversations/'.$otherSession()->id, []],
                'expect' => ['anonymous' => 403, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'POST /assistant/conversations/{someone else\'s}/messages' => [
                'request' => fn () => ['POST', '/api/assistant/conversations/'.$otherSession()->id.'/messages', ['message' => 'hello']],
                'expect' => ['anonymous' => 403, 'user' => 403, 'moderator' => 403, 'admin' => 403],
            ],
            'POST /assistant/conversations/{someone else\'s}/tickets' => [
                'request' => fn () => ['POST', '/api/assistant/conversations/'.$otherSession()->id.'/tickets', [
                    'subject' => 'A subject long enough',
                    'description' => 'A description that is long enough.',
                    'categoryId' => $category()->id,
                ]],
                'expect' => ['anonymous' => 403, 'user' => 403, 'moderator' => 403, 'admin' => 403],
            ],
            'POST /assistant/conversations/{someone else\'s}/claim' => [
                'request' => fn () => ['POST', '/api/assistant/conversations/'.$otherSession()->id.'/claim', []],
                'expect' => ['anonymous' => 403, 'user' => 403, 'moderator' => 403, 'admin' => 403],
            ],
            'GET /assistant/conversations' => [
                'request' => fn () => ['GET', '/api/assistant/conversations', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'GET /knowledge' => [
                'request' => fn () => ['GET', '/api/knowledge', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'POST /knowledge' => [
                'request' => fn (string $role) => ['POST', '/api/knowledge', [
                    'title' => "Article for {$role}",
                    'body' => 'A body that is long enough to pass validation.',
                ]],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 201],
            ],
            'PATCH /knowledge/{id}' => [
                'request' => fn () => ['PATCH', '/api/knowledge/'.KnowledgeArticle::factory()->create()->id, ['title' => 'Renamed article']],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'GET /metrics' => [
                'request' => fn () => ['GET', '/api/metrics', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
        ];
    }
}
