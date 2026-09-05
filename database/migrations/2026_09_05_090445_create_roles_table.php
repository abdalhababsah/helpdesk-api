<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Code-facing identifier. The policy layer, the seeder and the API
            // serializer all key off this, never the id, so it must not change.
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
            $table->string('description', 255)->nullable();
            // System roles cannot be deleted or re-slugged, so an admin edit
            // cannot leave the application without a role it depends on.
            $table->boolean('is_system')->default(true);
            $table->datetimes(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
