<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The words live in the AI package's tables. This row is what the
            // helpdesk tracks about them, so it references rather than owns.
            $table->string('conversation_id', 36)->unique();
            $table->string('participant_type');
            $table->string('participant_id', 36);
            $table->enum('outcome', ['open', 'answered', 'ticket_raised', 'abandoned'])->default('open');
            $table->foreignUlid('ticket_id')->nullable()->constrained('tickets')->nullOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('turns')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('last_agent', 40)->nullable();
            // The most recent draft card. The priority on creation comes from
            // here, never from the client, which only edits the words.
            $table->json('last_draft')->nullable();
            $table->dateTime('last_activity_at', 3);
            $table->datetimes(3);

            $table->index(['participant_type', 'participant_id', 'last_activity_at'], 'idx_sessions_participant');
            $table->index(['outcome', 'last_activity_at'], 'idx_sessions_outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_sessions');
    }
};
