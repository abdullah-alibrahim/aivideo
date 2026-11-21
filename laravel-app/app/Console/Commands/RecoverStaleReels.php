<?php

namespace App\Console\Commands;

use App\Models\Reel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RecoverStaleReels extends Command
{
    protected $signature = 'reels:recover-stale
        {--minutes= : Override the absolute stale threshold for processing reels}
        {--queued-minutes= : Override the maximum queue wait without a pending job}';

    protected $description = 'Fail renders whose worker stopped reporting or whose queue job disappeared.';

    public function handle(): int
    {
        $processingMinutes = max(5, (int) ($this->option('minutes') ?: config('studio.limits.stale_after_minutes', 30)));
        $queuedMinutes = max(5, (int) ($this->option('queued-minutes') ?: config('studio.limits.queue_wait_minutes', 15)));
        $heartbeatSeconds = max(60, (int) config('studio.limits.heartbeat_timeout_seconds', 600));
        $disk = Storage::disk('public');

        /** @var array<int, array{reel: Reel, stage: string}> $recoveries */
        $recoveries = [];

        $recoveries = $this->collect(
            $recoveries,
            Reel::query()
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subSeconds($heartbeatSeconds))
                ->get(),
            'timeout',
            fn (Reel $reel): bool => ! $this->hasPendingJob($reel),
        );

        $recoveries = $this->collect(
            $recoveries,
            Reel::query()
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes($processingMinutes))
                ->get(),
            'timeout',
        );

        $recoveries = $this->collect(
            $recoveries,
            Reel::query()
                ->where('status', 'queued')
                ->where('created_at', '<', now()->subMinutes($queuedMinutes))
                ->get(),
            'queue_failed',
            fn (Reel $reel): bool => ! $this->hasPendingJob($reel),
        );

        // Absolute cap: even a reel that still has a job row must not wait
        // forever when the scheduled worker never runs.
        $recoveries = $this->collect(
            $recoveries,
            Reel::query()
                ->where('status', 'queued')
                ->where('created_at', '<', now()->subMinutes(max(60, $queuedMinutes * 4)))
                ->get(),
            'queue_failed',
        );

        foreach ($recoveries as $recovery) {
            $reel = $recovery['reel'];

            if ($reel->output_video_path && str_starts_with($reel->output_video_path, 'reels/')) {
                $disk->delete($reel->output_video_path);
            }

            $reel->forceFill([
                'status' => 'failed',
                'progress_stage' => $recovery['stage'],
                'error_message' => trans('studio.errors.'.$recovery['stage'], [], $reel->language),
            ])->save();

            $this->line("Recovered reel #{$reel->id} ({$recovery['stage']}).");
        }

        $this->info('Recovered '.count($recoveries).' stale reel(s).');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{reel: Reel, stage: string}>  $recoveries
     * @param  iterable<int, Reel>  $reels
     * @return array<int, array{reel: Reel, stage: string}>
     */
    private function collect(array $recoveries, iterable $reels, string $stage, ?callable $filter = null): array
    {
        $known = array_map(static fn (array $recovery): int => $recovery['reel']->id, $recoveries);

        foreach ($reels as $reel) {
            if (in_array($reel->id, $known, true)) {
                continue;
            }

            if ($filter !== null && ! $filter($reel)) {
                continue;
            }

            $recoveries[] = ['reel' => $reel, 'stage' => $stage];
        }

        return $recoveries;
    }

    /**
     * Check whether a queue job for this reel is still waiting to run.
     *
     * A reserved job (already picked up by a worker) disappears from the jobs
     * table; the heartbeat check above is what covers that case.
     */
    private function hasPendingJob(Reel $reel): bool
    {
        $token = data_get($reel->render_options, '_dispatch_token');

        if (is_string($token) && preg_match('/^[a-f0-9-]{32,64}$/', $token) === 1) {
            return DB::table('jobs')->where('payload', 'like', '%'.$token.'%')->exists();
        }

        $id = (int) $reel->id;

        return DB::table('jobs')
            ->where('payload', 'like', '%"id":'.$id.'%')
            ->orWhere('payload', 'like', '%"id";s:'.strlen((string) $id).':"'.$id.'"%')
            ->exists();
    }
}
