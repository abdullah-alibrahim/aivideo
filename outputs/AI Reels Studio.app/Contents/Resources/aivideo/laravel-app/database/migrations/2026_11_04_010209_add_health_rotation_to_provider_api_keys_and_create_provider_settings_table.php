<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_api_keys', function (Blueprint $table) {
            $table->string('health_status', 20)->default('untested');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->index(['provider', 'is_active', 'cooldown_until', 'use_count', 'sort_order'], 'provider_key_rotation_idx');
        });

        Schema::create('provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_settings');

        Schema::table('provider_api_keys', function (Blueprint $table) {
            $table->dropIndex('provider_key_rotation_idx');
            $table->dropColumn([
                'health_status',
                'last_tested_at',
                'last_success_at',
                'last_used_at',
                'last_error',
                'cooldown_until',
                'use_count',
                'sort_order',
            ]);
        });
    }
};
