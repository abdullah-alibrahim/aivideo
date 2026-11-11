<?php

namespace App\Services;

use App\Exceptions\RenderFailedException;
use App\Models\ProviderSetting;
use App\Models\Reel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ReelRenderer
{
    public function __construct(private readonly ProviderKeyRing $keyRing) {}

    /**
     * Render one reel on the Python engine and return the engine payload.
     *
     * @param  array<int, string>  $inputPaths  Absolute paths to source clips.
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws RenderFailedException
     */
    public function render(Reel $reel, array $inputPaths, ?string $musicPath, array $options, string $outputRelativePath): array
    {
        $lockSeconds = max(
            (int) config('studio.limits.render_lock_seconds', 900),
            (int) config('studio.engine.render_timeout_seconds', 900) + 60,
        );
        $lock = null;
        $maxConcurrentRenders = max(1, (int) config('studio.limits.max_concurrent_renders', 1));
        for ($slot = 0; $slot < $maxConcurrentRenders; $slot++) {
            $candidate = Cache::lock('studio:render:slot:'.$slot, $lockSeconds);
            if ($candidate->get()) {
                $lock = $candidate;
                break;
            }
        }

        if ($lock === null) {
            throw new RenderFailedException(
                stage: 'busy',
                retryable: false,
                userMessage: trans('studio.errors.busy', [], $reel->language),
            );
        }

        $outputAbsolute = Storage::disk('public')->path($outputRelativePath);
        $baseName = pathinfo($outputRelativePath, PATHINFO_FILENAME);
        $thumbnailRelativePath = 'thumbnails/'.$baseName.'.jpg';
        $subtitleRelativePath = 'subtitles/'.$baseName.'.srt';
        $thumbnailAbsolute = Storage::disk('public')->path($thumbnailRelativePath);
        $subtitleAbsolute = Storage::disk('public')->path($subtitleRelativePath);
        Storage::disk('public')->makeDirectory('thumbnails');
        Storage::disk('public')->makeDirectory('subtitles');
        $progressFile = $this->progressFile($reel);
        $this->ensureProgressDirectory();

        try {
            @unlink($progressFile);

            $process = new Process(
                $this->buildCommand($reel, $inputPaths, $musicPath, $options, $outputAbsolute, $progressFile, $thumbnailAbsolute, $subtitleAbsolute),
                base_path('../'),
                $this->buildEnvironment($reel, $options),
            );
            $process->setTimeout((float) config('studio.engine.render_timeout_seconds', 900));

            $reel->forceFill(['progress' => 8, 'progress_stage' => 'preparing'])->save();

            $lastStage = null;
            $lastPercent = -1;
            $recordedProviderFailures = [];
            $process->start();

            while ($process->isRunning()) {
                if ($reel->fresh()?->cancel_requested_at) {
                    $process->stop(5);

                    throw new RenderFailedException(
                        stage: 'cancelled',
                        retryable: false,
                        userMessage: trans('studio.errors.cancelled', [], $reel->language),
                    );
                }

                $this->applyProgress($reel, $progressFile, $lastStage, $lastPercent, $recordedProviderFailures);
                $process->checkTimeout();
                usleep(800_000);
            }

            $process->wait();
            $this->applyProgress($reel, $progressFile, $lastStage, $lastPercent, $recordedProviderFailures);

            if (! $process->isSuccessful()) {
                throw $this->failureFromProcess($reel, $process);
            }

            $data = $this->parseEngineOutput($process->getOutput(), (string) $reel->language);

            if (! is_file($outputAbsolute) || (filesize($outputAbsolute) ?: 0) < 1024) {
                throw new RenderFailedException(
                    stage: 'render_failed',
                    retryable: true,
                    userMessage: trans('studio.errors.render_failed', [], $reel->language),
                    logMessage: 'Renderer finished without a usable MP4 file.',
                );
            }

            $data['thumbnail_path'] = is_file($thumbnailAbsolute) ? $thumbnailRelativePath : null;
            $data['subtitle_path'] = is_file($subtitleAbsolute) ? $subtitleRelativePath : null;

            return $data;
        } catch (ProcessTimedOutException $exception) {
            @unlink($outputAbsolute);
            @unlink($thumbnailAbsolute);
            @unlink($subtitleAbsolute);

            throw new RenderFailedException(
                stage: 'timeout',
                retryable: true,
                userMessage: trans('studio.errors.timeout', [], $reel->language),
                logMessage: $exception->getMessage(),
            );
        } catch (RenderFailedException $exception) {
            @unlink($outputAbsolute);
            @unlink($thumbnailAbsolute);
            @unlink($subtitleAbsolute);

            throw $exception;
        } catch (\Throwable $exception) {
            @unlink($outputAbsolute);
            @unlink($thumbnailAbsolute);
            @unlink($subtitleAbsolute);

            throw new RenderFailedException(
                stage: 'render_failed',
                retryable: true,
                userMessage: trans('studio.errors.render_failed', [], $reel->language),
                logMessage: $exception->getMessage(),
            );
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
            @unlink($progressFile);
        }
    }

    /** @return array<int, string> */
    private function buildCommand(Reel $reel, array $inputPaths, ?string $musicPath, array $options, string $outputAbsolute, string $progressFile, string $thumbnailAbsolute, string $subtitleAbsolute): array
    {
        $command = [
            $this->pythonBinary($reel->language),
            $this->engineScript($reel->language),
            '--topic', (string) $reel->topic,
            '--user-prompt', (string) ($reel->user_prompt ?? ''),
            '--output', $outputAbsolute,
            '--duration', (string) $reel->duration,
            '--language', (string) $reel->language,
            '--niche', (string) $reel->niche,
            '--goal', (string) $reel->goal,
            '--target-country', (string) $reel->target_country,
            '--format-type', (string) $reel->format_type,
            '--style', (string) $reel->style,
            '--template', (string) $reel->template,
            '--handle', (string) ($reel->handle ?? ''),
            '--aspect-ratio', (string) $reel->aspect_ratio,
            '--camera-motion', (string) $reel->camera_motion,
            '--text-motion', (string) ($options['text_motion'] ?? 'vertical'),
            '--scene-transition', (string) ($options['scene_transition'] ?? 'template'),
            '--font-scale', (string) (int) ($options['font_scale'] ?? 100),
            '--background-dimming', (string) (int) ($options['background_dimming'] ?? 35),
            '--progress-file', $progressFile,
            '--quality', (string) (int) ($options['quality'] ?? 1080),
            '--thumbnail', $thumbnailAbsolute,
            '--srt-output', $subtitleAbsolute,
            '--json',
        ];

        if (! empty($options['anti_border'])) {
            $command[] = '--anti-border';
        }

        if (! empty($options['manual_content'])) {
            $command[] = '--manual-content';
        }

        if (array_key_exists('captions', $options) && ! $options['captions']) {
            $command[] = '--no-captions';
        }

        if (array_key_exists('auto_music', $options) && ! $options['auto_music']) {
            $command[] = '--no-auto-music';
        }

        if (! empty($options['hook_word_by_word'])) {
            $command[] = '--hook-word-by-word';
        }

        if (array_key_exists('highlight_important', $options) && ! $options['highlight_important']) {
            $command[] = '--no-highlight-important';
        }

        if (! empty($options['voiceover'])) {
            $command[] = '--voiceover';
            $command[] = '--voiceover-tone';
            $command[] = (string) ($options['voiceover_tone'] ?? 'neutral');
        }

        $command[] = '--input';
        foreach ($inputPaths as $inputPath) {
            $command[] = $inputPath;
        }

        if ($musicPath !== null) {
            $command[] = '--music';
            $command[] = $musicPath;
        }

        if (! empty($options['custom_headline'])) {
            $command[] = '--custom-headline';
            $command[] = (string) $options['custom_headline'];
        }

        if (! empty($options['accent_color'])) {
            $command[] = '--accent-color';
            $command[] = (string) $options['accent_color'];
        }

        $customPoints = array_values(array_filter(
            array_map(static fn ($point): string => is_string($point) ? trim($point) : '', (array) ($options['custom_points'] ?? [])),
            static fn (string $point): bool => $point !== '',
        ));
        if ($customPoints !== []) {
            $command[] = '--custom-points';
            foreach ($customPoints as $point) {
                $command[] = $point;
            }
        }

        return $command;
    }

    /** @return array<string, string> */
    private function buildEnvironment(Reel $reel, array $options): array
    {
        $geminiKeys = ! empty($options['manual_content']) ? [] : $this->mergeKeys(
            $this->keyRing->rotatedValues('gemini'),
            $this->configuredGeminiKeys(),
            (array) ($options['gemini_keys'] ?? []),
        );
        $elevenLabsKeys = $this->mergeKeys(
            $this->keyRing->rotatedValues('elevenlabs'),
            [(string) config('services.elevenlabs.api_key', '')],
        );

        return [
            'AIVIDEO_GEMINI_KEYS_JSON' => json_encode($geminiKeys, JSON_THROW_ON_ERROR),
            'AIVIDEO_ELEVENLABS_KEYS_JSON' => json_encode($elevenLabsKeys, JSON_THROW_ON_ERROR),
            'GEMINI_API_KEY' => '',
            'GOOGLE_API_KEY' => '',
            'ELEVENLABS_API_KEY' => (string) config('services.elevenlabs.api_key', ''),
            'ELEVENLABS_VOICE_ID_AR' => $this->elevenLabsVoiceId('ar'),
            'ELEVENLABS_VOICE_ID_EN' => $this->elevenLabsVoiceId('en'),
            'AIVIDEO_ARABIC_FONT' => (string) config('studio.engine.arabic_font', ''),
            'PYTHON_DOTENV_DISABLED' => '1',
        ];
    }

    /** @param array<int, string> ...$groups @return array<int, string> */
    private function mergeKeys(array ...$groups): array
    {
        return array_values(array_unique(array_filter(
            array_merge(...$groups),
            static fn ($key): bool => is_string($key) && trim($key) !== '',
        )));
    }

    /** @return array<int, string> */
    private function configuredGeminiKeys(): array
    {
        $keys = json_decode((string) config('services.gemini.keys_json', '[]'), true);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    private function elevenLabsVoiceId(string $language): string
    {
        $setting = ProviderSetting::query()->where('name', 'elevenlabs.voice_id.'.$language)->first();

        return trim((string) ($setting?->value ?: config("services.elevenlabs.voice_ids.$language", '')));
    }

    private function pythonBinary(?string $language = null): string
    {
        $binary = (string) config('studio.engine.python_bin');

        if (! is_file($binary) || ! is_executable($binary)) {
            throw new RenderFailedException(
                stage: 'engine_missing',
                retryable: false,
                userMessage: trans('studio.errors.engine_missing', [], $language),
                logMessage: 'Python interpreter not found at '.$binary,
            );
        }

        return $binary;
    }

    private function engineScript(?string $language = null): string
    {
        $script = (string) (config('studio.engine.script') ?: base_path('../python_engine/smart_reels.py'));

        if (! is_file($script)) {
            throw new RenderFailedException(
                stage: 'engine_missing',
                retryable: false,
                userMessage: trans('studio.errors.engine_missing', [], $language),
                logMessage: 'Renderer script not found at '.$script,
            );
        }

        return $script;
    }

    private function progressFile(Reel $reel): string
    {
        return storage_path('app/private/render-progress/'.$reel->id.'.json');
    }

    private function ensureProgressDirectory(): void
    {
        $directory = storage_path('app/private/render-progress');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function applyProgress(Reel $reel, string $progressFile, ?string &$lastStage, int &$lastPercent, array &$recordedProviderFailures): void
    {
        if (! is_file($progressFile)) {
            return;
        }

        $payload = json_decode((string) @file_get_contents($progressFile), true);
        if (! is_array($payload)) {
            return;
        }

        $newFailures = [];
        foreach ((array) ($payload['failed_provider_keys'] ?? []) as $failure) {
            if (! is_array($failure)) {
                continue;
            }

            $fingerprint = $failure['fingerprint'] ?? null;
            if (! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || in_array($fingerprint, $recordedProviderFailures, true)) {
                continue;
            }

            $status = $failure['status'] ?? null;
            $reason = $failure['reason'] ?? null;
            $newFailures[] = [
                'fingerprint' => $fingerprint,
                'status' => is_int($status) && $status >= 100 && $status <= 599 ? $status : null,
                'reason' => is_string($reason) && in_array($reason, ['network unavailable', 'invalid provider response', 'provider error', 'HTTP 401', 'HTTP 403', 'HTTP 429', 'HTTP 500', 'HTTP 501', 'HTTP 502', 'HTTP 503', 'HTTP 504'], true) ? $reason : null,
            ];
            $recordedProviderFailures[] = $fingerprint;
        }

        if ($newFailures !== []) {
            $this->keyRing->recordRenderOutcomes($newFailures, null);
        }

        $stage = (string) ($payload['stage'] ?? '');
        $percent = max(0, min(99, (int) ($payload['percent'] ?? 0)));

        if ($stage === $lastStage && $percent === $lastPercent) {
            return;
        }

        $reel->forceFill([
            'progress' => $percent,
            'progress_stage' => $stage !== '' ? $stage : 'rendering',
        ])->save();

        $lastStage = $stage;
        $lastPercent = $percent;
    }

    /** @return array<string, mixed> */
    private function parseEngineOutput(string $output, string $language): array
    {
        $marker = strpos($output, '__JSON_OUTPUT__');
        $json = $marker === false ? null : trim(substr($output, $marker + strlen('__JSON_OUTPUT__')));
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ($data['status'] ?? null) !== 'success') {
            throw new RenderFailedException(
                stage: 'invalid_result',
                retryable: true,
                userMessage: trans('studio.errors.invalid_result', [], $language),
                logMessage: 'Renderer returned an invalid JSON result.',
            );
        }

        $successFingerprint = $data['provider_key_fingerprint'] ?? null;
        if (! is_string($successFingerprint) || preg_match('/^[a-f0-9]{64}$/D', $successFingerprint) !== 1) {
            $successFingerprint = null;
        }
        $this->keyRing->recordRenderOutcomes([], $successFingerprint);
        unset($data['provider_key_fingerprint']);

        return $data;
    }

    private function failureFromProcess(Reel $reel, Process $process): RenderFailedException
    {
        $errorOutput = $this->redact($process->getErrorOutput()."\n".$process->getOutput(), $reel);

        Log::warning('Video render process failed.', [
            'reel_id' => $reel->id,
            'exit_code' => $process->getExitCode(),
            'renderer_output' => Str::limit(trim($errorOutput), 2000),
        ]);

        [$stage, $retryable] = match (true) {
            str_contains($errorOutput, 'Gemini API key is required') => ['gemini_required', false],
            str_contains($errorOutput, 'HTTP 401') => ['gemini_key_invalid', false],
            str_contains($errorOutput, 'HTTP 403') => ['gemini_restricted', false],
            str_contains($errorOutput, 'HTTP 429') => ['gemini_quota', true],
            preg_match('/HTTP 5\d{2}/', $errorOutput) === 1 => ['gemini_unavailable', true],
            str_contains($errorOutput, 'network unavailable') => ['provider_unreachable', true],
            str_contains($errorOutput, 'Gemini') => ['gemini_failed', true],
            str_contains($errorOutput, 'No valid input') => ['input_invalid', false],
            stripos($errorOutput, 'font') !== false => ['font_missing', false],
            default => ['render_failed', true],
        };

        return new RenderFailedException(
            stage: $stage,
            retryable: $retryable,
            userMessage: trans('studio.errors.'.$stage, [], $reel->language),
            logMessage: trim($errorOutput),
        );
    }

    private function redact(string $output, Reel $reel): string
    {
        $keys = $this->mergeKeys(
            $this->keyRing->activeValues('gemini'),
            $this->keyRing->activeValues('elevenlabs'),
            $this->configuredGeminiKeys(),
            [(string) config('services.elevenlabs.api_key', '')],
        );

        foreach ($keys as $key) {
            $output = str_replace($key, '[redacted API key]', $output);
        }

        return preg_replace(
            '/\b(?:sk-(?:proj-)?[A-Za-z0-9_-]{8,}|AIza[0-9A-Za-z_-]{20,}|AQ\.[A-Za-z0-9_-]{20,})\b/i',
            '[redacted API key]',
            $output,
        ) ?? $output;
    }
}
