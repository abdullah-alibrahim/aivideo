<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reels')
            ->where('status', 'completed')
            ->where('progress', 0)
            ->update([
                'progress' => 100,
                'progress_stage' => 'completed',
            ]);
    }

    public function down(): void
    {
        // The previous progress values are not recoverable; keep the backfill.
    }
};
