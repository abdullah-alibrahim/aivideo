<?php

namespace App\Services;

use App\Exceptions\RenderFailedException;
use App\Models\Reel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class FfmpegReelRenderer implements RenderEngine
{
    public function __construct(
        private readonly FfmpegCommandBuilder $commands,
        private readonly FfmpegProgressParser $progressParser,
        private readonly ReelContentGenerator $contentGenerator,
        private readonly ElevenLabsVoiceoverService $voiceover,
    ) {}

    public function renderPreview(array $options, string $outputPath): void
    {
        $workDirectory = $this->makeWorkDirectory();
        $temporaryOutput = $workDirectory.'/preview.png';
        try {
            $textFiles = $this->writeTextFiles($workDirectory, [
                'headline' => trim((string) ($options['headline'] ?? '')),
                'points' => $this->strings((array) ($options['custom_points'] ?? [])),
                'handle' => trim((string) ($options['handle'] ?? '')),
            ]);
            $options['_text_files'] = $textFiles;
            $this->runProcess($this->commands->buildPreview($options, $temporaryOutput), 60, (string) ($options['language'] ?? 'ar'), 'preview_failed');
            if (! is_file($temporaryOutput) || (filesize($temporaryOutput) ?: 0) < 8) {
                throw $this->failure('preview_failed', false, (string) ($options['language'] ?? 'ar'), 'FFmpeg did not create a preview image.');
            }
            $this->publishFile($temporaryOutput, $outputPath);
        } catch (ProcessTimedOutException $exception) {
            $this->logRenderError('preview_timeout', $exception, ['language' => $options['language'] ?? 'ar']);
            throw $this->failure('timeout', true, (string) ($options['language'] ?? 'ar'), $exception->getMessage());
        } catch (RenderFailedException $exception) {
            $this->logRenderError('preview_failed', $exception, ['language' => $options['language'] ?? 'ar']);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logRenderError('preview_exception', $exception, ['language' => $options['language'] ?? 'ar']);
            throw $this->failure('preview_failed', false, (string) ($options['language'] ?? 'ar'), $exception->getMessage());
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * @param  array<int, string>  $inputPaths
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function render(Reel $reel, array $inputPaths, ?string $musicPath, array $options, string $outputRelativePath): array
    {
        $language = (string) ($reel->language ?: 'en');
        if ($reel->cancel_requested_at) {
            throw $this->failure('cancelled', false, $language);
        }
        if ($inputPaths === [] || collect($inputPaths)->contains(static fn (string $path): bool => ! is_file($path) || ! is_readable($path))) {
            throw $this->failure('input_invalid', false, $language, 'One or more render inputs are missing or unreadable.');
        }
        if ($musicPath !== null && (! is_file($musicPath) || ! is_readable($musicPath))) {
            throw $this->failure('input_invalid', false, $language, 'The selected music input is missing or unreadable.');
        }
        $normalizedOutputPath = str_replace('\\', '/', $outputRelativePath);
        if ($normalizedOutputPath !== $outputRelativePath
            || preg_match('#^reels/[A-Za-z0-9._-]+\.mp4$#D', $normalizedOutputPath) !== 1) {
            throw $this->failure('invalid_result', false, $language, 'The output path is outside the reels directory.');
        }

        $lockSeconds = max(
            (int) config('studio.limits.render_lock_seconds', 900),
            (int) config('studio.engine.render_timeout_seconds', 900) + 60,
        );
        $lock = null;
        for ($slot = 0; $slot < max(1, (int) config('studio.limits.max_concurrent_renders', 1)); $slot++) {
            $candidate = Cache::lock('studio:render:slot:'.$slot, $lockSeconds);
            if ($candidate->get()) {
                $lock = $candidate;
                break;
            }
        }
        if ($lock === null) {
            throw $this->failure('busy', false, $language);
        }

        $workDirectory = null;
        $baseName = pathinfo($outputRelativePath, PATHINFO_FILENAME);
        $thumbnailRelativePath = 'thumbnails/'.$baseName.'.jpg';
        $subtitleRelativePath = 'subtitles/'.$baseName.'.srt';
        $publishedPaths = [$outputRelativePath, $thumbnailRelativePath, $subtitleRelativePath];

        try {
            $workDirectory = $this->makeWorkDirectory();
            $reel->forceFill(['progress' => 8, 'progress_stage' => 'preparing'])->save();
            $content = $this->contentGenerator->generate($reel, $options);
            $points = $this->strings($content['points']);
            if ($points === []) {
                $points = [$content['headline']];
            }
            $textFiles = $this->writeTextFiles($workDirectory, [
                'headline' => $content['headline'],
                'points' => $points,
                'cta' => $content['cta'],
                'handle' => trim((string) $reel->handle),
            ]);
            $subtitleTemporary = $workDirectory.'/captions.srt';
            $duration = (float) ($reel->duration ?: 10);
            $headlineEnd = min($duration, max(2.5, $duration * 0.34));
            $pointsEnd = max($headlineEnd, $duration - 2.0);
            $this->writeSubtitles($subtitleTemporary, $points, $headlineEnd, $pointsEnd);

            $voiceTemporary = null;
            $narrationSeconds = null;
            if (! empty($options['voiceover'])) {
                $voiceTemporary = $workDirectory.'/voiceover.mp3';
                $this->voiceover->generate($content['script'], $language, (string) ($options['voiceover_tone'] ?? 'neutral'), $voiceTemporary);
                $narrationSeconds = $this->audioDuration($voiceTemporary);
            }
            $scenePlan = (new CaptionTimeline)->allocateScenes(
                [$content['headline']],
                $points,
                [$content['cta']],
                $duration,
                $language,
            );
            if ($narrationSeconds !== null && $narrationSeconds > 0.0) {
                $scenePlan = (new CaptionTimeline)->fitToNarration($scenePlan, $duration, $narrationSeconds);
            }
            $options['_scene_plan'] = $scenePlan;

            $outputTemporary = $workDirectory.'/render.mp4';
            $thumbnailTemporary = $workDirectory.'/thumbnail.jpg';
            $options['_text_files'] = $textFiles;
            $options['headline'] = $content['headline'];
            $options['custom_points'] = $points;
            $options['aspect_ratio'] = $reel->aspect_ratio;
            $options['_source_audio_indices'] = ! empty($options['background_sequence'])
                ? []
                : $this->sourceAudioIndices($inputPaths, $language);

            $command = $this->commands->build(
                $reel,
                $inputPaths,
                $musicPath,
                $options,
                $outputTemporary,
                $voiceTemporary,
                $subtitleTemporary,
            );
            $this->runRenderProcess($command, $reel);
            $this->assertNotCancelled($reel);

            if (! is_file($outputTemporary) || (filesize($outputTemporary) ?: 0) < 1024) {
                throw $this->failure('render_failed', true, $language, 'FFmpeg finished without a usable MP4 file.');
            }
            $this->runProcess($this->commands->buildThumbnail($outputTemporary, $thumbnailTemporary), 60, $language);
            if (! is_file($thumbnailTemporary) || (filesize($thumbnailTemporary) ?: 0) < 8) {
                throw $this->failure('render_failed', true, $language, 'FFmpeg did not create a thumbnail.');
            }

            $disk = Storage::disk('public');
            $disk->makeDirectory(dirname($outputRelativePath));
            $disk->makeDirectory(dirname($thumbnailRelativePath));
            $disk->makeDirectory(dirname($subtitleRelativePath));
            $this->publishFile($outputTemporary, $disk->path($outputRelativePath));
            $this->publishFile($thumbnailTemporary, $disk->path($thumbnailRelativePath));
            $this->publishFile($subtitleTemporary, $disk->path($subtitleRelativePath));
            $reel->forceFill(['progress' => 96, 'progress_stage' => 'finalizing'])->save();

            return [
                'script' => $content['script'],
                'headline' => $content['headline'],
                'caption' => $content['caption'],
                'hashtags' => $content['hashtags'],
                'content_source' => $content['content_source'],
                'ai_model' => $content['ai_model'],
                'duration' => (float) ($reel->duration ?: 10),
                'thumbnail_path' => $thumbnailRelativePath,
                'subtitle_path' => $subtitleRelativePath,
            ];
        } catch (ProcessTimedOutException $exception) {
            $this->deletePublished($publishedPaths);
            $this->logRenderError('render_timeout', $exception, ['reel_id' => $reel->id, 'language' => $language]);
            throw $this->failure('timeout', true, $language, $exception->getMessage());
        } catch (RenderFailedException $exception) {
            $this->deletePublished($publishedPaths);
            $this->logRenderError('render_failed', $exception, ['reel_id' => $reel->id, 'language' => $language]);
            throw $exception;
        } catch (Throwable $exception) {
            $this->deletePublished($publishedPaths);
            $this->logRenderError('render_exception', $exception, ['reel_id' => $reel->id, 'language' => $language]);
            throw $this->failure('render_failed', true, $language, $exception->getMessage());
        } finally {
            if ($workDirectory !== null) {
                File::deleteDirectory($workDirectory);
            }
            $lock->release();
        }
    }

    /**
     * Measure the runtime of an audio file with ffprobe.
     */
    private function audioDuration(?string $path): ?float
    {
        if ($path === null || ! is_file($path)) {
            return null;
        }
        $ffprobe = (string) config('studio.engine.ffprobe_bin', 'ffprobe');
        $process = new Process([
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ], base_path('../'));
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }
        $value = (float) trim($process->getOutput());

        return $value > 0.0 ? $value : null;
    }

    /** @param list<string> $command */
    private function runRenderProcess(array $command, Reel $reel): void
    {
        $process = new Process($command, base_path('../'));
        $process->setTimeout((float) config('studio.engine.render_timeout_seconds', 900));
        $process->start();
        $lastProgress = 8;

        while ($process->isRunning()) {
            if ($reel->fresh()?->cancel_requested_at) {
                $process->stop(3);
                throw $this->failure('cancelled', false, (string) $reel->language);
            }

            $parsed = $this->progressParser->parse(
                $process->getIncrementalOutput(),
                (float) ($reel->duration ?: 10),
            );
            $lastProgress = max($lastProgress, $parsed['progress']);
            $reel->forceFill(['progress' => $lastProgress, 'progress_stage' => $parsed['stage']])->save();
            $process->checkTimeout();
            usleep(100_000);
        }

        $process->wait();
        $parsed = $this->progressParser->parse($process->getOutput(), (float) ($reel->duration ?: 10));
        $reel->forceFill([
            'progress' => max($lastProgress, $parsed['progress']),
            'progress_stage' => $parsed['stage'],
        ])->save();

        if (! $process->isSuccessful()) {
            throw $this->failure('render_failed', true, (string) $reel->language, Str::limit($process->getErrorOutput(), 1200));
        }
    }

    /** @param list<string> $command */
    private function runProcess(array $command, float $timeout, string $language, string $failureStage = 'render_failed'): void
    {
        $process = new Process($command, base_path('../'));
        $process->setTimeout($timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw $this->failure($failureStage, true, $language, Str::limit($process->getErrorOutput(), 1200));
        }
    }

    private function assertNotCancelled(Reel $reel): void
    {
        if ($reel->fresh()?->cancel_requested_at) {
            throw $this->failure('cancelled', false, (string) $reel->language);
        }
    }

    /** @param list<string> $inputPaths @return list<int> */
    private function sourceAudioIndices(array $inputPaths, string $language): array
    {
        $indices = [];
        $ffprobe = (string) config('studio.engine.ffprobe_bin', 'ffprobe');
        foreach ($inputPaths as $index => $path) {
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'mov', 'avi', 'webm', 'mkv'], true)) {
                continue;
            }
            $process = new Process([
                $ffprobe,
                '-v', 'error',
                '-select_streams', 'a:0',
                '-show_entries', 'stream=codec_type',
                '-of', 'csv=p=0',
                $path,
            ], base_path('../'));
            $process->setTimeout(15);
            try {
                $process->run();
            } catch (Throwable $exception) {
                throw $this->failure('input_invalid', false, $language, $exception->getMessage());
            }
            if (! $process->isSuccessful()) {
                throw $this->failure('input_invalid', false, $language, Str::limit($process->getErrorOutput(), 1200));
            }
            if (trim($process->getOutput()) !== '') {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    private function makeWorkDirectory(): string
    {
        $directory = storage_path('app/private/render-work/'.Str::uuid());
        if (! File::makeDirectory($directory, 0700, true, true)) {
            throw $this->failure('render_failed', false, 'en', 'Unable to create a private render work directory.');
        }

        return $directory;
    }

    /** @param array{headline:string,points:list<string>,cta?:string,handle?:string} $content
     * @return array{headline:string,points:list<string>,cta?:string,handle?:string}
     */
    private function writeTextFiles(string $directory, array $content): array
    {
        $headlinePath = $directory.'/headline.txt';
        if (file_put_contents($headlinePath, $content['headline']) === false) {
            throw $this->failure('render_failed', false, 'en', 'Unable to write the headline text file.');
        }
        $pointPaths = [];
        foreach ($content['points'] as $index => $point) {
            $path = $directory.'/point-'.$index.'.txt';
            if (file_put_contents($path, $point) === false) {
                throw $this->failure('render_failed', false, 'en', 'Unable to write a caption text file.');
            }
            $pointPaths[] = $path;
        }

        $files = ['headline' => $headlinePath, 'points' => $pointPaths];
        foreach (['cta', 'handle'] as $key) {
            $text = trim((string) ($content[$key] ?? ''));
            if ($text === '') {
                continue;
            }
            $path = $directory.'/'.$key.'.txt';
            if (file_put_contents($path, $text) === false) {
                throw $this->failure('render_failed', false, 'en', 'Unable to write the '.$key.' text file.');
            }
            $files[$key] = $path;
        }

        return $files;
    }

    /** @param list<string> $points */
    private function writeSubtitles(string $path, array $points, float $start, float $end): void
    {
        $content = [];
        foreach ((new CaptionTimeline)->allocate($points, $start, $end) as $index => $segment) {
            $content[] = (string) ($index + 1);
            $content[] = $this->timestamp($segment['start']).' --> '.$this->timestamp($segment['end']);
            $content[] = str_replace(["\r", "\n"], ' ', $segment['text']);
            $content[] = '';
        }
        if (file_put_contents($path, implode("\n", $content)) === false) {
            throw $this->failure('render_failed', false, 'en', 'Unable to write subtitles.');
        }
    }

    private function timestamp(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);
        $hours = intdiv($milliseconds, 3_600_000);
        $minutes = intdiv($milliseconds % 3_600_000, 60_000);
        $wholeSeconds = intdiv($milliseconds % 60_000, 1000);
        $fraction = $milliseconds % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $wholeSeconds, $fraction);
    }

    /** @param array<int, mixed> $values @return list<string> */
    private function strings(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function publishFile(string $source, string $destination): void
    {
        $directory = dirname($destination);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw $this->failure('render_failed', true, 'en', 'Unable to create render output directory.');
        }
        if (rename($source, $destination)) {
            return;
        }

        throw $this->failure('render_failed', true, 'en', 'Unable to atomically publish completed render output.');
    }

    /** @param list<string> $paths */
    private function deletePublished(array $paths): void
    {
        Storage::disk('public')->delete($paths);
    }

    private function failure(string $stage, bool $retryable, string $language, string $logMessage = ''): RenderFailedException
    {
        return new RenderFailedException(
            stage: $stage,
            retryable: $retryable,
            userMessage: trans('studio.errors.'.$stage, [], $language),
            logMessage: $logMessage,
        );
    }

    /**
     * Record a render failure on the dedicated render-errors channel so that
     * FFmpeg, timeout and provider failures can be reviewed separately from
     * the noisy application log.
     *
     * @param  array<string, mixed>  $context
     */
    private function logRenderError(string $stage, Throwable $exception, array $context = []): void
    {
        try {
            Log::channel('render_errors')->error('render_error', array_merge($context, [
                'stage' => $stage,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));
        } catch (Throwable) {
            // Monitoring must never break a render.
        }
    }
}
