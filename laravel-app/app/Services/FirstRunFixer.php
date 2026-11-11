<?php

namespace App\Services;

use App\Support\StudioPaths;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Applies the automatic fixes offered by the First Run wizard: data
 * directories, APP_KEY, migrations, the public storage link, and frontend
 * assets. Environment-level installs (Homebrew, PHP, Python, FFmpeg) belong
 * to the native first-run screen, not to a web request.
 */
class FirstRunFixer
{
    /** @return array{steps: list<array{name: string, ok: bool, detail: string}>, all_passed: bool} */
    public function run(): array
    {
        $steps = [];

        StudioPaths::ensure();
        $this->add($steps, 'data_directories', true, StudioPaths::root());

        if (! filled(config('app.key'))) {
            try {
                Artisan::call('key:generate', ['--force' => true]);
                $this->add($steps, 'app_key', true, 'Generated a new APP_KEY.');
            } catch (Throwable $exception) {
                $this->add($steps, 'app_key', false, $exception->getMessage());
            }
        } else {
            $this->add($steps, 'app_key', true, 'Already configured.');
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->add($steps, 'database', true, 'Migrations applied.');
        } catch (Throwable $exception) {
            $this->add($steps, 'database', false, $exception->getMessage());
        }

        try {
            Artisan::call('storage:link', ['--force' => true]);
            $this->add($steps, 'storage_link', true, 'Public storage link ready.');
        } catch (Throwable $exception) {
            $this->add($steps, 'storage_link', false, $exception->getMessage());
        }

        $manifest = base_path('public/build/manifest.json');
        if (is_file($manifest)) {
            $this->add($steps, 'frontend_assets', true, 'Already built.');
        } elseif ($this->buildFrontend()) {
            $this->add($steps, 'frontend_assets', true, 'Built with npm.');
        } else {
            $this->add($steps, 'frontend_assets', false, 'Run npm install && npm run build inside laravel-app.');
        }

        $python = (string) config('studio.engine.python_bin');
        $this->add($steps, 'python_engine', is_file($python) && is_executable($python), is_file($python)
            ? $python
            : 'Create the venv with python3 -m venv venv and install requirements.lock.txt (done automatically by setup.sh).');

        return ['steps' => $steps, 'all_passed' => ! in_array(false, array_column($steps, 'ok'), true)];
    }

    private function buildFrontend(): bool
    {
        try {
            $process = new Process(['npm', 'run', 'build'], base_path(), null, null, 300);
            $process->run();

            return $process->isSuccessful() && is_file(base_path('public/build/manifest.json'));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<array{name: string, ok: bool, detail: string}> $steps */
    private function add(array &$steps, string $name, bool $ok, string $detail): void
    {
        $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
