<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            // Constant across a rotation chain. Replaying any member revokes the
            // whole family, which logs out every device on that login.
            $table->ulid('family_id');
            // SHA-256 digest of the issued token. The raw value exists only in the
            // client cookie, so a database leak yields nothing replayable.
            $table->char('token', 64)->unique();
            // Rotation successor. Unique because a successor replaces exactly one
            // predecessor. Declared before the self-referencing foreign key below.
            $table->ulid('replaced_by_id')->nullable();
            $table->dateTime('expires_at', 3);
            $table->dateTime('revoked_at', 3)->nullable();
            $table->enum('revoked_reason', [
                'rotated',
                'logout',
                'logout_all',
                'reuse_detected',
                'user_deactivated',
                'role_changed',
                'expired',
            ])->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at', 3)->nullable();

            $table->unique('replaced_by_id', 'uq_refresh_replaced_by');
            $table->foreign('replaced_by_id')
                ->references('id')->on('refresh_tokens')
                ->nullOnDelete()->cascadeOnUpdate();

            // Revoke-all on deactivate or role change.
            $table->index(['user_id', 'revoked_at'], 'idx_refresh_user_active');
            // Burn a family on reuse detection.
            $table->index('family_id', 'idx_refresh_family');
            // Cleanup sweep.
            $table->index('expires_at', 'idx_refresh_sweep');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
