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
        Schema::table('provider_api_keys', function (Blueprint $table): void {
            $table->string('secret')->nullable()->change();
            $table->string('secret_ref')->nullable()->after('secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_api_keys', function (Blueprint $table): void {
            $table->dropColumn('secret_ref');
        });
    }
};
