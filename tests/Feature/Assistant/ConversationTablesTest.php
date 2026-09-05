<?php

namespace Tests\Feature\Assistant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ConversationTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_tables_exist_with_string_participants(): void
    {
        $this->assertTrue(Schema::hasTable('agent_conversations'));
        $this->assertTrue(Schema::hasTable('agent_conversation_messages'));
        $this->assertSame('varchar', Schema::getColumnType('agent_conversations', 'participant_id'));
        $this->assertSame('anthropic', config('ai.default'));
        $this->assertFalse(config('ai.conversations.generate_title'));
    }
}
