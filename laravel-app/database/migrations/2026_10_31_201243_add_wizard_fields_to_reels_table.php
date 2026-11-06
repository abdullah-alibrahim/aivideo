<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->string('target_country')->default('US')->after('niche');
            $table->string('format_type')->default('full_idea')->after('template');
            $table->text('user_prompt')->nullable()->after('topic');
        });
    }

    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->dropColumn(['target_country', 'format_type', 'user_prompt']);
        });
    }
};
