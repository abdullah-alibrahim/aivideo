<?php

namespace App\Console\Commands;

use App\Services\ProviderKeyRing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        $python = (string) (config('studio.engine.python_bin') ?: base_path('../venv/bin/python3'));
        $this->check($rows, 'Python', is_file($python) && is_executable($python), $python, $failures);

        $script = (string) (config('studio.engine.script') ?: base_path('../python_engine/smart_reels.py'));
        $this->check($rows, 'Renderer', is_file($script), $script, $failures);

        if (is_file($python) && is_executable($python)) {
            $version = $this->runProcess([$python, '-V']);
            $this->check($rows, 'Python version', $version !== null, $version ?? 'Could not execute the interpreter', $failures);

            $ffmpeg = $this->runProcess([$python, '-c', 'import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())']);
            $this->check($rows, 'FFmpeg', $ffmpeg !== null && is_file($ffmpeg), $ffmpeg ?? 'Install imageio-ffmpeg or ffmpeg', $failures);
        }

        $font = trim((string) config('studio.engine.arabic_font', ''));
        $bundledFont = base_path('../python_engine/fonts/NotoKufiArabic.ttf');
        if ($font !== '') {
            $this->check($rows, 'Arabic font', is_file($font), $font, $failures);
        } else {
            $this->check($rows, 'Arabic font', is_file($bundledFont), $bundledFont, $failures);
        }

        foreach ([storage_path('app'), storage_path('app/public'), storage_path('app/private')] as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            $this->check($rows, 'Writable: '.str_replace(base_path().'/', '', $path), is_writable($path), $path, $failures);
        }

        $queue = (string) config('queue.default');
        $pending = 0;
        try {
            $pending = $queue === 'database' && Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        } catch (Throwable) {
            $pending = 0;
        }
        $this->check($rows, 'Queue', $queue !== '', "{$queue} ({$pending} pending job(s))", $failures);

        $geminiKeys = array_filter(array_merge(
            $keyRing->activeValues('gemini'),
            (array) json_decode((string) config('services.gemini.keys_json', '[]'), true),
        ));
        $this->check($rows, 'Gemini keys', $geminiKeys !== [], count($geminiKeys).' key(s) available', $failures);

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
}
