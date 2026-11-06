<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('label', 100)->nullable();
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_api_keys');
    }
};
