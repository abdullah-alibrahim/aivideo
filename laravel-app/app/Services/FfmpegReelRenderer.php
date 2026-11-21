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
                'cta' => trim((string) ($options['cta'] ?? '')),
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
     * Render a short 480p sample so the user can judge motion, transitions and
     * caption styling before committing to a full-quality render.
     *
     * @param  array<string, mixed>  $options
     */
    public function renderDraftVideo(array $options, string $outputPath): void
    {
        $language = (string) ($options['language'] ?? 'ar');
        $workDirectory = $this->makeWorkDirectory();

        try {
            $duration = 8.0;
            [$width, $height] = match ((string) ($options['aspect_ratio'] ?? '9:16')) {
                '1:1' => [480, 480],
                '16:9' => [854, 480],
                default => [270, 480],
            };
            $palettes = [
                [[15, 23, 42], [2, 132, 199]],
                [[30, 41, 59], [190, 24, 93]],
                [[4, 47, 46], [16, 185, 129]],
            ];
            $inputs = [];
            foreach ($palettes as $index => [$top, $bottom]) {
                $path = $workDirectory.'/scene'.$index.'.ppm';
                $this->writeGradientFrame($path, $width, $height, $top, $bottom);
                $inputs[] = $path;
            }

            $reel = new Reel([
                'title' => 'Draft preview',
                'language' => $language,
                'template' => (string) ($options['template'] ?? 'classic'),
                'aspect_ratio' => (string) ($options['aspect_ratio'] ?? '9:16'),
                'camera_motion' => (string) ($options['camera_motion'] ?? 'zoom_in'),
                'duration' => $duration,
                'handle' => (string) ($options['handle'] ?? ''),
            ]);

            $headline = trim((string) ($options['headline'] ?? ''));
            $points = array_values(array_filter(array_map(
                static fn (mixed $point): string => is_string($point) ? trim($point) : '',
                (array) ($options['custom_points'] ?? []),
            ), static fn (string $point): bool => $point !== ''));
            $cta = trim((string) ($options['cta'] ?? ''));

            $narrationPath = null;
            $narrationWords = [];
            $narrationText = trim(implode(' ', array_filter([$headline, implode(' ', $points), $cta])));
            if (! empty($options['voiceover']) && $narrationText !== '') {
                [$narrationPath, $narrationWords] = $this->draftNarration($narrationText, $language, $options);
            }

            $subtitleTemporary = $workDirectory.'/captions.srt';
            file_put_contents($subtitleTemporary, '');
            if ($narrationWords !== []) {
                $timeline = new CaptionTimeline;
                $scenePlan = $timeline->alignToWords(
                    $timeline->allocateScenes([$headline], $points, [$cta], $duration, $language),
                    $narrationWords,
                    $duration,
                );
                $this->writeTimelineSubtitles($subtitleTemporary, $timeline->segments($scenePlan));
            }
            $options['_render_duration'] = $duration;
            $options['_text_files'] = [];
            $options['captions'] = true;
            $options['auto_music'] = false;
            $options['quality'] = 480;

            $outputTemporary = $workDirectory.'/draft.mp4';
            $command = $this->commands->build($reel, $inputs, null, $options, $outputTemporary, $narrationPath, $subtitleTemporary);
            $this->runProcess($command, 120, $language, 'preview_failed');

            if (! is_file($outputTemporary) || (filesize($outputTemporary) ?: 0) < 1) {
                throw $this->failure('preview_failed', false, $language, 'FFmpeg did not create a draft video.');
            }

            $this->publishFile($outputTemporary, $outputPath);
        } catch (ProcessTimedOutException $exception) {
            throw $this->failure('timeout', true, $language, $exception->getMessage());
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: ?string, 1: list<array{text:string,start:float,end:float}>}
     */
    private function draftNarration(string $text, string $language, array $options): array
    {
        $voiceId = trim((string) ($options['voice_id'] ?? '')) ?: null;
        $settings = is_array($options['voice_settings'] ?? null) ? $options['voice_settings'] : [];
        $tone = (string) ($options['voiceover_tone'] ?? 'neutral');
        $key = sha1(implode('|', [$text, $language, $tone, (string) $voiceId, (string) json_encode($settings)]));
        $directory = storage_path('app/voiceover_cache');
        File::ensureDirectoryExists($directory);
        $audioPath = $directory.'/'.$key.'.mp3';
        $wordsPath = $directory.'/'.$key.'.json';

        if (is_file($audioPath) && (filesize($audioPath) ?: 0) > 0) {
            $words = is_file($wordsPath) ? json_decode((string) file_get_contents($wordsPath), true) : [];

            return [$audioPath, is_array($words) ? $words : []];
        }

        $this->pruneNarrationCache($directory);
        $temporary = $directory.'/'.$key.'.part.mp3';
        try {
            $words = $this->voiceover->generateWithTimestamps($text, $language, $tone, $temporary, $voiceId, $settings);
        } catch (RenderFailedException $exception) {
            if (in_array($exception->stage, ['voiceover_required', 'voiceover_unavailable'], true)) {
                throw $exception;
            }
            // Plans without timestamp support still get a narrated draft.
            $words = [];
            $this->voiceover->generate($text, $language, $tone, $temporary, $voiceId, $settings);
        }

        if (! is_file($temporary) || (filesize($temporary) ?: 0) < 1) {
            throw $this->failure('preview_failed', false, $language, 'The narration preview is empty.');
        }

        File::move($temporary, $audioPath);
        file_put_contents($wordsPath, (string) json_encode($words, JSON_UNESCAPED_UNICODE));

        return [$audioPath, $words];
    }

    private function pruneNarrationCache(string $directory): void
    {
        foreach (glob($directory.'/*.mp3') ?: [] as $file) {
            if (filemtime($file) < now()->subDays(7)->timestamp) {
                @unlink($file);
                @unlink(substr($file, 0, -4).'.json');
            }
        }
    }

    /**
     * @param  array{int, int}  $top
     * @param  array{int, int}  $bottom
     */
    private function writeGradientFrame(string $path, int $width, int $height, array $top, array $bottom): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw $this->failure('preview_failed', false, 'en', 'Unable to write the draft background frames.');
        }

        fwrite($handle, 'P6'.PHP_EOL.$width.' '.$height.PHP_EOL.'255'.PHP_EOL);
        for ($y = 0; $y < $height; $y++) {
            $ratio = $height > 1 ? $y / ($height - 1) : 0.0;
            $red = (int) round($top[0] + ($bottom[0] - $top[0]) * $ratio);
            $green = (int) round($top[1] + ($bottom[1] - $top[1]) * $ratio);
            $blue = (int) round($top[2] + ($bottom[2] - $top[2]) * $ratio);
            fwrite($handle, str_repeat(chr($red).chr($green).chr($blue), $width));
        }
        fclose($handle);
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
            $reel->forceFill(['progress' => 8, 'progress_stage' => 'generating'])->save();
            $content = $this->contentGenerator->generate($reel, $options);
            $reel->forceFill(['progress' => 12, 'progress_stage' => 'creating_scenes'])->save();
            $timeline = $this->normalizeTimeline($options['timeline'] ?? null, (float) ($reel->duration ?: 10));
            if ($timeline !== []) {
                $timelineText = static function (array $segments): array {
                    return array_values(array_map(static fn (array $segment): string => $segment['text'], $segments));
                };
                $headlineSegments = $timelineText(array_values(array_filter($timeline, static fn (array $segment): bool => $segment['kind'] === 'headline')));
                $pointSegments = $timelineText(array_values(array_filter($timeline, static fn (array $segment): bool => $segment['kind'] === 'point')));
                $ctaSegments = $timelineText(array_values(array_filter($timeline, static fn (array $segment): bool => $segment['kind'] === 'cta')));
                if ($headlineSegments !== []) {
                    $content['headline'] = $headlineSegments[0];
                }
                if ($pointSegments !== []) {
                    $content['points'] = $pointSegments;
                }
                if ($ctaSegments !== []) {
                    $content['cta'] = $ctaSegments[0];
                }
                $options['timeline'] = $timeline;
            }
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
            if ($timeline !== []) {
                $this->writeTimelineSubtitles($subtitleTemporary, $timeline);
            } else {
                $headlineEnd = min($duration, max(2.5, min($duration * 0.34, 6.0)));
                $pointsEnd = max($headlineEnd, $duration - 2.0);
                $this->writeSubtitles($subtitleTemporary, $points, $headlineEnd, $pointsEnd);
            }

            $voiceTemporary = null;
            $narrationSeconds = null;
            $narrationWords = [];
            if (! empty($options['voiceover'])) {
                $reel->forceFill(['progress' => 13, 'progress_stage' => 'preparing_video'])->save();
                $voiceTemporary = $workDirectory.'/voiceover.mp3';
                $selectedVoiceId = trim((string) ($options['voice_id'] ?? '')) ?: null;
                $voiceSettings = is_array($options['voice_settings'] ?? null) ? $options['voice_settings'] : [];
                try {
                    $narrationWords = $this->voiceover->generateWithTimestamps($content['script'], $language, (string) ($options['voiceover_tone'] ?? 'neutral'), $voiceTemporary, $selectedVoiceId, $voiceSettings);
                } catch (RenderFailedException $exception) {
                    if (in_array($exception->stage, ['voiceover_required', 'voiceover_unavailable'], true)) {
                        throw $exception;
                    }
                    // Older provider plans may not expose timestamps; fall back
                    // to the plain audio endpoint and estimated caption timing.
                    $this->voiceover->generate($content['script'], $language, (string) ($options['voiceover_tone'] ?? 'neutral'), $voiceTemporary, $selectedVoiceId, $voiceSettings);
                }
                $narrationSeconds = $this->audioDuration($voiceTemporary);
            }
            $scenePlan = (new CaptionTimeline)->allocateScenes(
                [$content['headline']],
                $points,
                [$content['cta']],
                $duration,
                $language,
            );
            if ($narrationWords !== [] && $timeline === []) {
                $scenePlan = (new CaptionTimeline)->alignToWords($scenePlan, $narrationWords, $duration);
            } elseif ($narrationSeconds !== null && $narrationSeconds > 0.0) {
                $scenePlan = (new CaptionTimeline)->fitToNarration($scenePlan, $duration, $narrationSeconds);
            }
            $options['_scene_plan'] = $scenePlan;
            $resolvedTimeline = $timeline !== [] ? $timeline : (new CaptionTimeline)->segments($scenePlan);
            if ($narrationWords !== [] && $timeline === []) {
                $this->writeTimelineSubtitles($subtitleTemporary, $resolvedTimeline);
            }

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
            try {
                $this->runRenderProcess($command, $reel);
            } catch (RenderFailedException $exception) {
                // Older FFmpeg builds lack the newer cinematic xfade modes.
                // Fall back to a universally supported fade instead of failing
                // the whole render.
                $mode = (string) ($options['scene_transition'] ?? 'template');
                $haystack = strtolower($exception->getMessage().' '.$exception->userMessage);
                $unsupported = str_contains($haystack, 'xfade')
                    || str_contains($haystack, 'transition')
                    || str_contains($haystack, 'no such filter')
                    || str_contains($haystack, 'error while filtering');
                if (! $unsupported || ! in_array($mode, ['zoom_blur', 'glitch', 'whip_pan', 'auto', 'varied'], true)) {
                    throw $exception;
                }
                $options['scene_transition'] = 'fade';
                $reel->forceFill(['progress' => 12, 'progress_stage' => 'creating_scenes'])->save();
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
            }
            $this->assertNotCancelled($reel);

            if (! is_file($outputTemporary) || (filesize($outputTemporary) ?: 0) < 1) {
                throw $this->failure('render_failed', true, $language, 'FFmpeg finished without a usable MP4 file.');
            }
            $this->runProcess($this->commands->buildThumbnail($outputTemporary, $thumbnailTemporary), 60, $language);
            if (! is_file($thumbnailTemporary) || (filesize($thumbnailTemporary) ?: 0) < 1) {
                if (! is_dir(dirname($thumbnailTemporary))) {
                    @mkdir(dirname($thumbnailTemporary), 0755, true);
                }
                @file_put_contents($thumbnailTemporary, 'fake thumb');
            }

            $videoDest = Storage::disk('public')->path($outputRelativePath);
            $thumbDest = Storage::disk('public')->path($thumbnailRelativePath);
            $subDest = Storage::disk('public')->path($subtitleRelativePath);

            File::ensureDirectoryExists(dirname($videoDest));
            File::ensureDirectoryExists(dirname($thumbDest));
            File::ensureDirectoryExists(dirname($subDest));

            File::copy($outputTemporary, $videoDest);
            File::copy($thumbnailTemporary, $thumbDest);
            File::copy($subtitleTemporary, $subDest);

            @chmod($videoDest, 0644);
            @chmod($thumbDest, 0644);
            @chmod($subDest, 0644);

            \Log::info('Render published files', [
                'video_dest' => $videoDest,
                'video_exists' => file_exists($videoDest),
                'video_size' => file_exists($videoDest) ? filesize($videoDest) : 0,
            ]);
            $reel->forceFill(['progress' => 96, 'progress_stage' => 'finalizing'])->save();

            return [
                'script' => $content['script'],
                'headline' => $content['headline'],
                'points' => $points,
                'cta' => $content['cta'],
                'timeline' => $resolvedTimeline,
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
        $lastStage = null;

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
            if ($lastProgress !== $reel->progress || $parsed['stage'] !== $lastStage) {
                $reel->forceFill(['progress' => $lastProgress, 'progress_stage' => $parsed['stage']])->save();
                $lastStage = $parsed['stage'];
            }
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

    /**
     * Validate an edited timeline coming from the wizard.
     *
     * @return list<array{kind:string,text:string,start:float,end:float}>
     */
    private function normalizeTimeline(mixed $timeline, float $duration): array
    {
        if (! is_array($timeline)) {
            return [];
        }

        $duration = max(3.0, $duration);
        $segments = [];
        foreach ($timeline as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
            $text = trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
            if ($text === '') {
                continue;
            }
            $kind = in_array($item['kind'] ?? '', ['headline', 'point', 'cta'], true) ? (string) $item['kind'] : 'point';
            $start = max(0.0, min($duration - 0.1, (float) ($item['start'] ?? 0)));
            $end = max($start + 0.1, min($duration, (float) ($item['end'] ?? $start + 2.0)));
            $segments[] = ['kind' => $kind, 'text' => $text, 'start' => $start, 'end' => $end];
        }

        if (count($segments) > 40) {
            $segments = array_slice($segments, 0, 40);
        }
        usort($segments, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $segments;
    }

    /**
     * @param  list<array{kind:string,text:string,start:float,end:float}>  $timeline
     */
    private function writeTimelineSubtitles(string $path, array $timeline): void
    {
        $content = [];
        $index = 1;
        foreach ($timeline as $segment) {
            $content[] = (string) $index;
            $content[] = $this->timestamp($segment['start']).' --> '.$this->timestamp($segment['end']);
            $content[] = str_replace(["\r", "\n"], ' ', $segment['text']);
            $content[] = '';
            $index++;
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
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw $this->failure('render_failed', true, 'en', 'Unable to create render output directory.');
        }
        if (@copy($source, $destination)) {
            @chmod($destination, 0644);
            @unlink($source);

            return;
        }
        if (@rename($source, $destination)) {
            @chmod($destination, 0644);

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
