<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->string('language')->default('ar')->after('topic');
            $table->string('style')->default('engaging')->after('language');
            $table->string('theme')->default('glass')->after('style');
            $table->string('aspect_ratio')->default('9:16')->after('theme');
            $table->string('bg_music_path')->nullable()->after('output_video_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->dropColumn(['language', 'style', 'theme', 'aspect_ratio', 'bg_music_path']);
        });
    }
};
