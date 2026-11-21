<?php

namespace App\Console\Commands;

use App\Services\ProviderKeyRing;
use App\Support\StudioPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class StudioDoctor extends Command
{
    protected $signature = 'studio:doctor';

    protected $description = 'Check the rendering engine, storage, queue, and provider configuration.';

    public function handle(ProviderKeyRing $keyRing): int
    {
        $rows = [];
        $failures = 0;

        $this->check($rows, 'PHP', PHP_VERSION_ID >= 80300, PHP_VERSION, $failures);
        $this->check($rows, 'APP_KEY', filled(config('app.key')), filled(config('app.key')) ? 'Configured' : 'Run php artisan key:generate', $failures);

        try {
            $hasReels = Schema::hasTable('reels');
        } catch (Throwable $exception) {
            $hasReels = false;
        }
        $this->check($rows, 'Database', $hasReels, $hasReels ? (string) config('database.default') : 'Run php artisan migrate', $failures);

        $python = (string) config('studio.engine.python_bin');
        $script = (string) config('studio.engine.script');
        $driver = (string) config('studio.engine.driver', 'ffmpeg');

        $this->check($rows, 'Render driver', in_array($driver, ['ffmpeg', 'python'], true), $driver, $failures);

        if ($driver === 'python') {
            $this->check($rows, 'Python', is_file($python) && is_executable($python), $python, $failures);
            $this->check($rows, 'Renderer', is_file($script), $script, $failures);

            if (is_file($python) && is_executable($python)) {
                $version = $this->runProcess([$python, '-V']);
                $this->check($rows, 'Python version', $version !== null, $version ?? 'Could not execute the interpreter', $failures);

                $ffmpeg = $this->runProcess([$python, '-c', 'import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())']);
                $this->check($rows, 'FFmpeg', $ffmpeg !== null && is_file($ffmpeg), $ffmpeg ?? 'Install imageio-ffmpeg or ffmpeg', $failures);
            }
        } else {
            $this->checkFfmpeg($rows, $failures);
        }

        $this->check($rows, 'proc_open', function_exists('proc_open'), function_exists('proc_open')
            ? 'Process spawning is enabled'
            : 'Disabled by the hosting PHP pool; rendering cannot start', $failures);

        $family = trim((string) config('studio.engine.arabic_font', ''));
        $fontsDirectory = rtrim((string) (config('studio.engine.fontsdir') ?: base_path('../python_engine/fonts')), '/');
        $fontFile = (array) config('studio.engine.arabic_fonts', []);
        $fontPath = $fontsDirectory.'/'.($fontFile[$family] ?? 'NotoKufiArabic.ttf');
        $this->check($rows, 'Arabic font', is_file($fontPath) && is_readable($fontPath), $fontPath, $failures);

        $this->check($rows, 'Storage link', file_exists(public_path('storage')), file_exists(public_path('storage'))
            ? public_path('storage')
            : 'Run php artisan storage:link --relative', $failures);

        $this->check($rows, 'Built assets', is_file(public_path('build/manifest.json')), is_file(public_path('build/manifest.json'))
            ? public_path('build/manifest.json')
            : 'Run npm run build and upload public/build', $failures);

        $dataRoot = StudioPaths::root();
        if (! is_dir($dataRoot)) {
            @mkdir($dataRoot, 0775, true);
        }
        $this->check($rows, 'Data root', is_dir($dataRoot) && is_writable($dataRoot), $dataRoot, $failures);

        foreach ([StudioPaths::videos(), StudioPaths::uploads()] as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $this->check($rows, 'Writable: '.str_replace(StudioPaths::root().'/', '', $path), is_writable($path), $path, $failures);
        }

        $queue = (string) config('queue.default');
        $pending = 0;
        try {
            $pending = $queue === 'database' && Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        } catch (Throwable) {
            $pending = 0;
        }
        $this->check($rows, 'Queue', $queue !== '', "{$queue} ({$pending} pending job(s))", $failures);

        $storageRoot = StudioPaths::videos();
        $freeBytes = @disk_free_space($storageRoot);
        $freeDetails = $freeBytes === false ? 'Could not determine free space' : $this->formatBytes((int) $freeBytes).' free';
        $minimumFreeBytes = (int) config('studio.limits.min_free_space_mb', 1024) * 1024 * 1024;
        $this->check($rows, 'Storage space', $freeBytes === false || $freeBytes >= $minimumFreeBytes, $freeDetails, $failures);

        try {
            $storedKeys = $keyRing->activeValues('gemini');
        } catch (Throwable $exception) {
            $storedKeys = [];
            $rows[] = ['Provider keys', '<fg=yellow>warn</>', 'Could not read stored keys: '.$exception->getMessage()];
        }
        $geminiKeys = array_filter(array_merge(
            $storedKeys,
            (array) json_decode((string) config('services.gemini.keys_json', '[]'), true),
        ));
        $this->check($rows, 'Gemini keys', $geminiKeys !== [], count($geminiKeys).' key(s) available', $failures);

        $productionSafe = config('app.env') !== 'production'
            || (! config('app.debug') && (config('studio.security.enabled') || $this->studioRoutesProtected()));
        $this->check(
            $rows,
            'Production safety',
            $productionSafe,
            $productionSafe ? 'Safe defaults detected' : 'Production requires APP_DEBUG=false and an authenticated studio',
            $failures,
        );

        if (config('app.env') === 'production' && (string) config('studio.cron_secret', '') === '') {
            $rows[] = ['Cron secret', '<fg=yellow>warn</>', 'CRON_SECRET is empty; /cron-run uses a public default token'];
        }

        if (config('app.debug') && ! config('studio.security.enabled')) {
            $rows[] = ['Exposure', '<fg=yellow>warn</>', 'APP_DEBUG is on and Studio auth is off; keep it on localhost only'];
        }

        $this->table(['Check', 'Status', 'Details'], $rows);
        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} check(s) failed.");

            return self::FAILURE;
        }

        $this->info('All critical checks passed.');

        return self::SUCCESS;
    }

    /** @param array<int, array{0: string, 1: string, 2: string}> $rows */
    private function checkFfmpeg(array &$rows, int &$failures): void
    {
        $ffmpeg = (string) config('studio.engine.ffmpeg_bin', 'ffmpeg');
        $binaryOk = is_file($ffmpeg) && is_executable($ffmpeg);
        $version = $binaryOk ? $this->runProcess([$ffmpeg, '-version']) : null;
        $this->check($rows, 'FFmpeg executable', $version !== null, $version !== null
            ? strtok($version, "\n")
            : (is_file($ffmpeg) ? 'Not executable: '.$ffmpeg : 'Missing: '.$ffmpeg), $failures);

        $ffprobe = (string) config('studio.engine.ffprobe_bin', 'ffprobe');
        $probeOk = is_file($ffprobe) && is_executable($ffprobe);
        $this->check($rows, 'FFprobe executable', $probeOk && $this->runProcess([$ffprobe, '-version']) !== null, $ffprobe, $failures);

        if ($version === null) {
            return;
        }

        $filters = (string) $this->runProcess([$ffmpeg, '-hide_banner', '-filters']);
        foreach (['xfade', 'subtitles', 'zoompan', 'vignette', 'noise', 'colorbalance', 'sidechaincompress'] as $filter) {
            $this->check($rows, 'Filter: '.$filter, preg_match('/\b'.$filter.'\b/', $filters) === 1, 'Required by the render graph', $failures);
        }

        $encoders = (string) $this->runProcess([$ffmpeg, '-hide_banner', '-encoders']);
        $this->check($rows, 'Encoder: libx264', str_contains($encoders, 'libx264'), 'H.264 video encoder', $failures);
        $this->check($rows, 'Encoder: aac', str_contains($encoders, ' aac '), 'AAC audio encoder', $failures);

        $ass = $this->runProcess([$ffmpeg, '-hide_banner', '-h', 'filter=subtitles']);
        $this->check($rows, 'libass subtitles', is_string($ass) && str_contains(strtolower($ass), 'libass'), 'Arabic text overlay needs libass', $failures);
    }

    private function studioRoutesProtected(): bool
    {
        try {
            $route = Route::getRoutes()->getByName('reels.index');

            return $route !== null && in_array('auth', $route->gatherMiddleware(), true);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int, array{0: string, 1: string, 2: string}> $rows */
    private function check(array &$rows, string $label, bool $ok, string $details, int &$failures = 0): void
    {
        if (! $ok) {
            $failures++;
        }

        $rows[] = [$label, $ok ? '<fg=green>ok</>' : '<fg=red>fail</>', $details];
    }

    private function runProcess(array $command): ?string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(15);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return trim($process->getOutput()) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, 1).' '.$units[$unit];
    }
}
