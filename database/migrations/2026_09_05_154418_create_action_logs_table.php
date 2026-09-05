<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Matches the ActionType enum. Stored as a string rather than a
            // MySQL enum so adding a case does not require a migration, and an
            // old row naming a retired action stays readable.
            $table->string('action', 64);

            // Null for events with no authenticated actor: a failed login, or
            // work done by a console command. SET NULL rather than CASCADE so
            // the record of what happened outlives the account that did it.
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();

            // What the action was performed on. Polymorphic and unconstrained:
            // a soft-deleted ticket must still be traceable, and an FK would
            // block the delete the log is recording.
            $table->string('subject_type', 64)->nullable();
            $table->ulid('subject_id')->nullable();

            // Before and after values for the fields that changed. Deliberately
            // never holds credentials or token values.
            $table->json('properties')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at', 3);

            // Activity for one account, and the timeline of one ticket.
            $table->index(['actor_id', 'created_at'], 'idx_action_logs_actor');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_action_logs_subject');
            // Filtering the feed by action, and the pruning sweep.
            $table->index(['action', 'created_at'], 'idx_action_logs_action');
            $table->index('created_at', 'idx_action_logs_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
