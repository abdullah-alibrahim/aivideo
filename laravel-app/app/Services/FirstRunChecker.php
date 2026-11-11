<?php

namespace App\Services;

use App\Support\StudioPaths;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs the First Run readiness checks shown on the standalone wizard screen.
 *
 * @return array{checks: list<array{name: string, ok: bool, state: string, detail: string, fixable: bool}>, all_passed: bool}
 */
class FirstRunChecker
{
    public function __construct(
        private readonly ProviderConnectivityTester $connectivity,
    ) {}

    public function run(): array
    {
        $checks = [];

        $this->add($checks, 'php', PHP_VERSION_ID >= 80300, PHP_VERSION, false);
        $this->add($checks, 'app_key', filled(config('app.key')), 'Run the automatic fix to generate it', true);

        $databaseReady = false;
        $databaseDetail = 'Run the automatic fix to create it';
        try {
            $databaseReady = Schema::hasTable('reels');
            if ($databaseReady) {
                $databaseDetail = config('database.default').' at '.StudioPaths::database();
            }
        } catch (Throwable) {
            $databaseReady = false;
        }
        $this->add($checks, 'database', $databaseReady, $databaseDetail, true);

        $python = (string) config('studio.engine.python_bin');
        $pythonOk = is_file($python) && is_executable($python);
        $this->add($checks, 'python', $pythonOk, $python, true);

        $ffmpeg = $pythonOk ? $this->detectFfmpeg($python) : false;
        $this->add($checks, 'ffmpeg', $ffmpeg !== false, $ffmpeg === false
            ? 'Install imageio-ffmpeg (bundled with the Python requirements) or a system FFmpeg'
            : (string) $ffmpeg, false);

        $videosRoot = StudioPaths::videos();
        $freeBytes = @disk_free_space($videosRoot);
        $minimumFree = (int) config('studio.limits.min_free_space_mb', 1024) * 1024 * 1024;
        $storageOk = $freeBytes === false || $freeBytes >= $minimumFree;
        $this->add($checks, 'storage', $storageOk, $freeBytes === false
            ? 'Could not determine free space'
            : $this->formatBytes((int) $freeBytes).' free at '.$videosRoot, false);

        $unwritable = [];
        foreach ([StudioPaths::root(), StudioPaths::videos(), StudioPaths::uploads(), StudioPaths::logs()] as $directory) {
            if (! is_writable($directory)) {
                $unwritable[] = $directory;
            }
        }
        $this->add($checks, 'permissions', $unwritable === [], $unwritable === []
            ? StudioPaths::root()
            : 'Not writable: '.implode(', ', $unwritable), true);

        [$geminiState, $geminiDetail, $geminiKeyCount] = $this->providerCheck('gemini');
        $checks[] = [
            'name' => 'gemini',
            'ok' => $geminiState === 'connected',
            'state' => $geminiState,
            'detail' => $geminiKeyCount > 0 ? $geminiDetail.' ('.$geminiKeyCount.' key(s))' : $geminiDetail,
            'fixable' => false,
        ];

        [$elevenLabsState, $elevenLabsDetail, $elevenLabsKeyCount] = $this->providerCheck('elevenlabs');
        $checks[] = [
            'name' => 'elevenlabs',
            'ok' => $elevenLabsState === 'connected',
            'state' => $elevenLabsState,
            'detail' => $elevenLabsKeyCount > 0 ? $elevenLabsDetail.' ('.$elevenLabsKeyCount.' key(s))' : $elevenLabsDetail,
            'fixable' => false,
        ];

        return ['checks' => $checks, 'all_passed' => ! in_array(false, array_column($checks, 'ok'), true)];
    }

    /** @param list<array{name: string, ok: bool, state: string, detail: string, fixable: bool}> $checks */
    private function add(array &$checks, string $name, bool $ok, string $detail, bool $fixable): void
    {
        $checks[] = ['name' => $name, 'ok' => $ok, 'state' => $ok ? 'ok' : 'fail', 'detail' => $detail, 'fixable' => $fixable];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function providerCheck(string $provider): array
    {
        $keys = app(ProviderKeyRing::class)->activeValues($provider);
        $envKeys = [];

        if (config('studio.keys.allow_env')) {
            $envKeys = array_filter((array) json_decode((string) config("services.$provider.keys_json", '[]'), true));
        }

        $keyCount = count(array_filter(array_merge($keys, $envKeys)));

        if ($keyCount === 0) {
            return ['missing_key', $provider === 'gemini'
                ? 'Add a Gemini key to enable content generation.'
                : 'Optional: add an ElevenLabs key for voiceover.', 0];
        }

        $firstKey = trim((string) array_values(array_filter(array_merge($keys, $envKeys)))[0]);
        $method = $provider === 'gemini' ? 'testGemini' : 'testElevenLabs';
        $result = $this->connectivity->{$method}($firstKey);

        return [$result['state'], $result['detail'], $keyCount];
    }

    private function detectFfmpeg(string $python): string|false
    {
        try {
            $process = new Process([$python, '-c', 'import imageio_ffmpeg; print(imageio_ffmpeg.get_ffmpeg_exe())']);
            $process->setTimeout(15);
            $process->run();

            if (! $process->isSuccessful()) {
                return false;
            }

            $path = trim($process->getOutput());

            return $path !== '' && is_file($path) ? $path : false;
        } catch (Throwable) {
            return false;
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
