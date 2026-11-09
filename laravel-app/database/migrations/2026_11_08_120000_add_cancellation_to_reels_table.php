<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reels', function (Blueprint $table): void {
            $table->timestamp('cancel_requested_at')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table): void {
            $table->dropColumn(['cancel_requested_at', 'cancelled_at']);
        });
    }
};
