<?php

namespace App\Console\Commands;

use App\Models\Reel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PruneReels extends Command
{
    protected $signature = 'reels:prune {--days=30 : Age threshold in days} {--dry-run : Report without deleting}';

    protected $description = 'Delete old failed renders and orphaned media files.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $reels = Reel::query()
            ->where('status', 'failed')
            ->where('updated_at', '<', $cutoff)
            ->get();

        $deletedReels = 0;
        foreach ($reels as $reel) {
            if (! $dryRun) {
                $this->deleteOwnedFiles($reel);
                $reel->delete();
            }
            $deletedReels++;
        }

        $deletedFiles = $this->pruneOrphanFiles($cutoff, $dryRun);

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Pruned {$deletedReels} failed reel(s) and {$deletedFiles} orphaned file(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    private function deleteOwnedFiles(Reel $reel): void
    {
        if (is_string($reel->output_video_path) && str_starts_with($reel->output_video_path, 'reels/')) {
            Storage::disk('public')->delete($reel->output_video_path);
        }

        foreach (array_merge((array) ($reel->input_videos ?? []), [$reel->bg_music_path]) as $path) {
            if (! is_string($path) || $path === '' || str_contains($path, '..')) {
                continue;
            }

            if (str_starts_with($path, 'input_videos/') || str_starts_with($path, 'bg_music/')) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function pruneOrphanFiles(Carbon $cutoff, bool $dryRun): int
    {
        $referencedPublic = Reel::query()
            ->whereNotNull('output_video_path')
            ->pluck('output_video_path')
            ->filter()
            ->flip();

        $referencedLocal = collect()
            ->merge(Reel::query()->whereNotNull('input_videos')->pluck('input_videos')->flatten())
            ->merge(Reel::query()->whereNotNull('bg_music_path')->pluck('bg_music_path'))
            ->filter()
            ->flip();

        $deleted = 0;

        foreach (Storage::disk('public')->allFiles('reels') as $file) {
            if ($referencedPublic->has($file) || Storage::disk('public')->lastModified($file) >= $cutoff->timestamp) {
                continue;
            }

            if (! $dryRun) {
                Storage::disk('public')->delete($file);
            }
            $deleted++;
        }

        foreach (['input_videos', 'bg_music'] as $directory) {
            foreach (Storage::disk('local')->allFiles($directory) as $file) {
                if ($referencedLocal->has($file) || Storage::disk('local')->lastModified($file) >= $cutoff->timestamp) {
                    continue;
                }

                if (! $dryRun) {
                    Storage::disk('local')->delete($file);
                }
                $deleted++;
            }
        }

        return $deleted;
    }
}
