<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 120);
            $table->text('body');
            // Comma separated words the title and body might not contain, such as "vpn, remote".
            $table->string('keywords', 255)->default('');
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete()->cascadeOnUpdate();
            $table->boolean('is_active')->default(true);
            $table->datetimes(3);

            $table->index(['is_active', 'title'], 'idx_articles_active_title');
            $table->fullText(['title', 'body', 'keywords'], 'ft_articles_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_articles');
    }
};
