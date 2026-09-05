<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeArticlesTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        User::factory()->admin()->create(['email' => 'admin@example.test']);
        $this->adminToken = $this->login('admin@example.test')['token'];
    }

    public function test_articles_are_created_updated_and_retired(): void
    {
        $created = $this->asUser($this->adminToken)->postJson('/api/knowledge', [
            'title' => 'VPN will not connect', 'body' => 'Quit the client and open it again. Then approve the second factor.', 'keywords' => 'vpn, remote',
        ])->assertCreated()->json('data');

        $this->asUser($this->adminToken)->patchJson("/api/knowledge/{$created['id']}", ['title' => 'VPN cannot connect'])
            ->assertOk()->assertJsonPath('data.title', 'VPN cannot connect');

        $this->asUser($this->adminToken)->patchJson("/api/knowledge/{$created['id']}", ['isActive' => false])
            ->assertOk()->assertJsonPath('data.isActive', false);

        $this->asUser($this->adminToken)->getJson('/api/knowledge')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->adminToken)->getJson('/api/knowledge?includeRetired=1')->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('action_logs', ['action' => 'knowledge.retired', 'subject_id' => $created['id']]);
    }

    public function test_validation_errors_come_back_per_field(): void
    {
        $this->asUser($this->adminToken)->postJson('/api/knowledge', ['title' => 'x', 'body' => 'short'])
            ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'title');
    }
}
