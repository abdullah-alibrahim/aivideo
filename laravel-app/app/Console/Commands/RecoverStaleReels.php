<?php

namespace App\Console\Commands;

use App\Models\Reel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RecoverStaleReels extends Command
{
    protected $signature = 'reels:recover-stale {--minutes= : Override the stale threshold}';

    protected $description = 'Mark stale processing reels as failed and remove partial output files.';

    public function handle(): int
    {
        $minutes = max(5, (int) ($this->option('minutes') ?: config('studio.limits.stale_after_minutes', 30)));
        $cutoff = now()->subMinutes($minutes);
        $reels = Reel::query()->where('status', 'processing')->where('updated_at', '<', $cutoff)->get();
        $disk = Storage::disk('public');

        foreach ($reels as $reel) {
            if ($reel->output_video_path && str_starts_with($reel->output_video_path, 'reels/')) {
                $disk->delete($reel->output_video_path);
            }
            $reel->update([
                'status' => 'failed',
                'error_message' => 'Render timed out or was interrupted; retry the generation.',
            ]);
        }

        $this->info("Recovered {$reels->count()} stale reel(s).");

        return self::SUCCESS;
    }
}
