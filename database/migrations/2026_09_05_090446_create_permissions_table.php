<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // resource:action, the string the policy layer asks for.
            $table->string('slug', 64)->unique();
            $table->string('resource', 32);
            $table->string('action', 32);
            $table->string('description', 255)->nullable();
            $table->dateTime('created_at', 3)->nullable();

            // slug is derived from these two, so the pair must also be unique.
            // Its resource prefix serves lookups by resource alone.
            $table->unique(['resource', 'action'], 'uq_permissions_resource_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
