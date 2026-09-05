<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete()->cascadeOnUpdate();
            // Distinguishes "any ticket" from "only tickets the actor requested".
            // A user holds ticket:read at own, a moderator holds it at all.
            $table->enum('scope', ['own', 'all'])->default('all');
            $table->dateTime('created_at', 3)->nullable();

            // A missing row means denied, so a duplicate grant is meaningless.
            // The composite key makes one unrepresentable and covers role_id;
            // permission_id is indexed by its own foreign key.
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
