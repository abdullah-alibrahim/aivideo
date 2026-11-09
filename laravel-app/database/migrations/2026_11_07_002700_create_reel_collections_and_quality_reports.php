<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reel_collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 16);
            $table->string('label')->nullable();
            $table->unsignedTinyInteger('item_count');
            $table->timestamps();
        });

        Schema::table('reels', function (Blueprint $table): void {
            $table->uuid('collection_id')->nullable();
            $table->unsignedTinyInteger('collection_position')->nullable();
            $table->json('quality_report')->nullable();
            $table->index(['collection_id', 'collection_position'], 'reels_collection_position_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table): void {
            $table->dropIndex('reels_collection_position_idx');
            $table->dropColumn(['collection_id', 'collection_position', 'quality_report']);
        });

        Schema::dropIfExists('reel_collections');
    }
};
