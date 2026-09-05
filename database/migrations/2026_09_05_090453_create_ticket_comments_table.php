<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_comments', function (Blueprint $table) {
            // ULIDs sort chronologically, so the thread reads in order with no
            // secondary sort key.
            $table->ulid('id')->primary();
            // CASCADE: a comment has no meaning without its ticket.
            $table->foreignUlid('ticket_id')->constrained('tickets')->cascadeOnDelete()->cascadeOnUpdate();
            // RESTRICT to preserve attribution.
            $table->foreignUlid('author_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->text('body');
            $table->datetimes(3);

            // Flat, not threaded. No parent_id, so reading a thread is one
            // indexed range scan rather than a recursive walk.
            $table->index(['ticket_id', 'created_at'], 'idx_comments_thread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
    }
};
