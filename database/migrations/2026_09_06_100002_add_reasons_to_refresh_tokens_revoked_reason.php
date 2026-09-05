<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REASONS = "'rotated','logout','logout_all','reuse_detected','user_deactivated','role_changed','expired'";

    public function up(): void
    {
        DB::statement('ALTER TABLE refresh_tokens MODIFY revoked_reason ENUM('.self::REASONS.",'password_reset','user_deleted') NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refresh_tokens MODIFY revoked_reason ENUM('.self::REASONS.') NULL');
    }
};
