<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reels', function (Blueprint $table): void {
            $table->string('content_source', 32)->nullable()->after('hashtags');
            $table->string('ai_model', 80)->nullable()->after('content_source');
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->string('progress_stage', 80)->nullable()->after('progress');
            $table->index(['status', 'progress'], 'reels_status_progress_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table): void {
            $table->dropIndex('reels_status_progress_idx');
            $table->dropColumn(['content_source', 'ai_model', 'progress', 'progress_stage']);
        });
    }
};
