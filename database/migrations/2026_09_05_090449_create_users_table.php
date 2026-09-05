<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 255)->unique();
            $table->char('password', 60);
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete()->cascadeOnUpdate();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('token_version')->default(1);
            $table->dateTime('last_login_at', 3)->nullable();
            $table->datetimes(3);

            // Assignee picker (active moderators) and the admin account filter.
            $table->index(['role_id', 'is_active'], 'idx_users_role_active');
            $table->index(['is_active', 'created_at'], 'idx_users_active_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
