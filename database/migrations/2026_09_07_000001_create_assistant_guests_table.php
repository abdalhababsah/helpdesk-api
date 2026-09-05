<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_guests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('last_seen_at', 3);
            $table->datetimes(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_guests');
    }
};
