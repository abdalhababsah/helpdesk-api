<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('subject', 200);
            $table->text('description');
            // MySQL sorts ENUM by declaration ordinal. Both lists are declared in
            // ascending lifecycle and severity order so ORDER BY priority DESC
            // returns urgent first, with no weight column and no FIELD() call,
            // and the sort stays index-eligible. Do not reorder these.
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');

            $table->foreignUlid('category_id')->constrained('categories')->restrictOnDelete()->cascadeOnUpdate();
            // RESTRICT so a ticket is never orphaned. Also the column the "own"
            // permission scope compares against.
            $table->foreignUlid('requester_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            // Null means unassigned. Must be an active moderator or admin, which
            // is enforced in the service layer since a foreign key cannot express it.
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();

            // created_at plus the SLA for the priority, recomputed when priority
            // changes. Stored rather than derived so overdue is a range scan.
            $table->dateTime('due_at', 3);
            // Stamped on entry to resolved or closed and cleared on reopen, so a
            // reopened ticket is never counted as finished.
            $table->dateTime('resolved_at', 3)->nullable();
            $table->dateTime('closed_at', 3)->nullable();
            $table->softDeletesDatetime('deleted_at', 3);
            $table->datetimes(3);

            // Indexes on a foreign key column lead with that column. MySQL requires
            // an index whose leftmost prefix is the foreign key, and would otherwise
            // create a second single-column one alongside each of these. Leading
            // with the high-cardinality column also filters better than deleted_at,
            // which only ever holds null or a timestamp.
            $table->index(['requester_id', 'deleted_at', 'created_at'], 'idx_tickets_requester');
            $table->index(['category_id', 'deleted_at', 'status'], 'idx_tickets_category');
            $table->index(['assignee_id', 'deleted_at', 'status'], 'idx_tickets_assignee');

            // The rest lead with deleted_at, which is a predicate on every read
            // path and therefore never a skipped prefix.
            $table->index(['deleted_at', 'status', 'created_at'], 'idx_tickets_queue');
            $table->index(['deleted_at', 'priority', 'created_at'], 'idx_tickets_priority_sort');
            $table->index(['deleted_at', 'status', 'due_at'], 'idx_tickets_overdue');
            $table->index(['deleted_at', 'status', 'resolved_at'], 'idx_tickets_resolution');

            // Free-text search across both columns. A LIKE scan cannot use an
            // index and degrades linearly as the table grows.
            $table->fullText(['subject', 'description'], 'ft_tickets_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
