<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // URL-safe filter key used by the ticket list query string.
            $table->string('slug', 64)->unique();
            $table->string('name', 120);
            // Retired rather than deleted: hidden from the new-ticket form but
            // still filterable, and tickets reference it with RESTRICT.
            $table->boolean('is_active')->default(true);
            $table->datetimes(3);

            $table->index(['is_active', 'name'], 'idx_categories_active_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
