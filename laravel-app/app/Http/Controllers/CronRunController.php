<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes pending queue jobs from the web pool for shared hosting without
 * a long-running daemon. Kept behind a secret token because it starts work.
 */
class CronRunController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $expected = trim((string) config('studio.cron_secret', ''));

        abort_if($expected === '', 404);
        abort_unless(hash_equals($expected, $token), 403);

        $pending = DB::table('jobs')
            ->where('queue', 'default')
            ->whereNull('reserved_at')
            ->count();

        if (! function_exists('proc_open')) {
            return response()->json([
                'status' => 'needs_cli_worker',
                'pending' => $pending,
                'triggered' => false,
                'message' => 'The web PHP pool disables proc_open; run the queue worker from cron with Alt-PHP.',
                'time' => now()->toISOString(),
            ]);
        }

        $processed = 0;
        if ($pending > 0) {
            try {
                Artisan::call('queue:work', [
                    '--stop-when-empty' => true,
                    '--tries' => 2,
                    '--max-time' => 110,
                ]);
                $processed = $pending;
            } catch (Throwable $exception) {
                Log::error('Cron queue:work failed', ['error' => $exception->getMessage()]);
            }
        }

        return response()->json([
            'status' => 'ok',
            'pending' => $pending,
            'triggered' => $processed > 0,
            'time' => now()->toISOString(),
        ]);
    }
}
