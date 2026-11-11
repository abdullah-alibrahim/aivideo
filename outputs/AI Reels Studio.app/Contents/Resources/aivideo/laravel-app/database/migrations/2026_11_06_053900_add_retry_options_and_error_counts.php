<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_api_keys', function (Blueprint $table): void {
            $table->unsignedInteger('error_count')->default(0)->after('use_count');
        });

        Schema::table('reels', function (Blueprint $table): void {
            $table->json('render_options')->nullable()->after('progress_stage');
        });
    }

    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table): void {
            $table->dropColumn('render_options');
        });

        Schema::table('provider_api_keys', function (Blueprint $table): void {
            $table->dropColumn('error_count');
        });
    }
};
