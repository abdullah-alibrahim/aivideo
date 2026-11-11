<?php

namespace App\Console\Commands;

use App\Support\StudioPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Throwable;

class MigrateStudioData extends Command
{
    /**
     * Legacy media directories that lived under storage/app(.*) before the
     * user-data split, mapped to their destination inside the data root.
     */
    private const LEGACY_MEDIA = [
        'storage/app/public/reels' => 'videos/reels',
        'storage/app/public/thumbnails' => 'videos/thumbnails',
        'storage/app/public/subtitles' => 'videos/subtitles',
        'storage/app/public/previews' => 'videos/previews',
        'storage/app/public/backgrounds' => 'videos/backgrounds',
        'storage/app/public/music' => 'videos/music',
        'storage/app/input_videos' => 'uploads/input_videos',
        'storage/app/bg_music' => 'uploads/bg_music',
        'storage/app/private/uploaded_music' => 'uploads/uploaded_music',
    ];

    protected $signature = 'studio:migrate-data
        {--app-root= : Application root holding legacy data (defaults to this app)}
        {--force : Re-run even if the migration marker already exists}';

    protected $description = 'Move the legacy database and media files into the per-OS user-data root.';

    public function handle(): int
    {
        StudioPaths::ensure();

        $marker = StudioPaths::root().'/.data-migrated';
        if (is_file($marker) && ! $this->option('force')) {
            $this->info('User data already migrated (marker '.basename($marker).' exists). Nothing to do.');

            return self::SUCCESS;
        }

        $appRoot = rtrim((string) ($this->option('app-root') ?: base_path()), '/');
        $moved = 0;

        $legacyDatabase = $appRoot.'/database/database.sqlite';
        $targetDatabase = StudioPaths::database();
        if (is_file($legacyDatabase) && ! is_file($targetDatabase)) {
            File::ensureDirectoryExists(dirname($targetDatabase));
            $this->movePath($legacyDatabase, $targetDatabase);
            $this->line('database: '.$legacyDatabase.' -> '.$targetDatabase);
            $moved++;
        }

        foreach (self::LEGACY_MEDIA as $legacyRelative => $targetRelative) {
            $source = $appRoot.'/'.$legacyRelative;
            if (! is_dir($source)) {
                continue;
            }

            $target = StudioPaths::root().'/'.$targetRelative;
            File::ensureDirectoryExists($target);

            foreach (Finder::create()->in($source)->depth(0)->files()->ignoreDotFiles(true) as $file) {
                $destination = $target.'/'.$file->getFilename();
                if (is_file($destination)) {
                    continue;
                }
                $this->movePath($file->getPathname(), $destination);
                $moved++;
            }

            if (is_dir($source) && $this->directoryIsEmpty($source)) {
                @rmdir($source);
            }
        }

        File::put($marker, now()->toIso8601String());
        $this->info("Migration complete. {$moved} item(s) moved into ".StudioPaths::root());

        return self::SUCCESS;
    }

    private function movePath(string $source, string $destination): void
    {
        try {
            File::ensureDirectoryExists(dirname($destination));
            File::move($source, $destination);
        } catch (Throwable $exception) {
            // Cross-device moves fall back to copy + delete.
            if (File::copy($source, $destination)) {
                @unlink($source);
            } else {
                $this->warn("Could not move {$source}: {$exception->getMessage()}");
            }
        }
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $entries = array_diff(scandir($directory) ?: [], ['.', '..']);

        return $entries === [];
    }
}
