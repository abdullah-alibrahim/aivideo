<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->string('niche')->default('productivity')->after('topic');
            $table->string('goal')->default('followers')->after('niche');
            $table->string('template')->default('classic')->after('theme');
            $table->string('handle')->default('@yourpage')->after('title');
            $table->text('caption')->nullable()->after('generated_script');
            $table->text('hashtags')->nullable()->after('caption');
            $table->integer('score')->default(95)->after('status');
            $table->boolean('anti_border_warning')->default(true)->after('aspect_ratio');
            $table->string('camera_motion')->default('zoom_in')->after('anti_border_warning');
            $table->integer('scenes_count')->default(1)->after('duration');
        });
    }

    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->dropColumn([
                'niche',
                'goal',
                'template',
                'handle',
                'caption',
                'hashtags',
                'score',
                'anti_border_warning',
                'camera_motion',
                'scenes_count',
            ]);
        });
    }
};
