<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            // SHA-256 digest of the value in the emailed link. The raw value is
            // never stored, so a database leak yields nothing usable.
            $table->char('token', 64)->unique();
            // Who asked: null for a self-service request, an administrator otherwise.
            $table->foreignUlid('requested_by_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->dateTime('expires_at', 3);
            $table->dateTime('used_at', 3)->nullable();
            $table->dateTime('created_at', 3)->nullable();

            // Finding the live token for a user, and sweeping expired rows.
            $table->index(['user_id', 'used_at', 'expires_at'], 'idx_password_resets_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
