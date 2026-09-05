<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('source', ['direct', 'assistant'])->default('direct')->after('priority');
            // The assistant conversation this came out of. Not a foreign key:
            // the conversation lives in the AI package's tables and a ticket
            // must survive their retention independently.
            $table->string('conversation_id', 36)->nullable()->after('source');

            $table->index('conversation_id', 'idx_tickets_conversation');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_conversation');
            $table->dropColumn(['source', 'conversation_id']);
        });
    }
};
