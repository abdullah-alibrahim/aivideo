<?php

namespace App\Jobs;

use App\Exceptions\RenderFailedException;
use App\Models\Reel;
use App\Services\ReelRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateReelVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 20;

    public int $timeout = 1200;

    /**
     * @param  array<int, string>  $inputPaths
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public Reel $reel,
        public array $inputPaths,
        public ?string $musicPath,
        public array $options,
        public string $outputRelativePath,
    ) {}

    public function handle(ReelRenderer $renderer): void
    {
        $reel = $this->reel->fresh();

        if (! $reel instanceof Reel || $reel->status === 'completed') {
            return;
        }

        if ($reel->cancel_requested_at) {
            $this->markCancelled($reel);

            return;
        }

        $reel->forceFill([
            'status' => 'processing',
            'progress' => max(5, (int) $reel->progress),
            'progress_stage' => 'generating',
            'error_message' => null,
        ])->save();

        try {
            $data = $renderer->render($reel, $this->inputPaths, $this->musicPath, $this->options, $this->outputRelativePath);
        } catch (RenderFailedException $exception) {
            Storage::disk('public')->delete($this->outputRelativePath);

            if ($exception->stage === 'cancelled') {
                $this->markCancelled($reel);

                return;
            }

            if ($exception->retryable && $this->attempts() < $this->tries) {
                $reel->forceFill(['progress_stage' => 'retrying'])->save();

                throw $exception;
            }

            $this->markFailed($reel, $exception->stage, $exception->userMessage);

            return;
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($this->outputRelativePath);

            if ($this->attempts() < $this->tries) {
                throw $exception;
            }

            $this->markFailed(
                $reel,
                'render_failed',
                trans('studio.errors.render_failed', [], $reel->language),
            );

            return;
        }

        if ($reel->fresh()?->cancel_requested_at) {
            Storage::disk('public')->delete($this->outputRelativePath);
            $this->markCancelled($reel);

            return;
        }

        $this->markCompleted($reel, $data);
    }

    public function failed(?Throwable $exception): void
    {
        $reel = $this->reel->fresh();

        if (! $reel instanceof Reel || $reel->status === 'completed') {
            return;
        }

        if ($reel->cancel_requested_at || $reel->status === 'cancellation_requested') {
            $this->markCancelled($reel);

            return;
        }

        Storage::disk('public')->delete($this->outputRelativePath);

        $this->markFailed(
            $reel,
            'render_failed',
            trans('studio.errors.render_failed', [], $reel->language),
        );
    }

    /** @param array<string, mixed> $data */
    private function markCompleted(Reel $reel, array $data): void
    {
        $script = isset($data['script'])
            ? Str::limit(str_replace('*', '', (string) $data['script']), 10000, '')
            : null;

        $caption = trim((string) ($this->options['caption'] ?? ''));
        if ($caption === '') {
            $caption = isset($data['caption']) ? (string) $data['caption'] : null;
        }

        $hashtags = trim((string) ($this->options['hashtags'] ?? ''));
        if ($hashtags === '') {
            $hashtags = isset($data['hashtags']) ? (string) $data['hashtags'] : null;
        }

        $reel->forceFill([
            'status' => 'completed',
            'progress' => 100,
            'progress_stage' => 'completed',
            'output_video_path' => $this->outputRelativePath,
            'output_video_url' => Storage::disk('public')->url($this->outputRelativePath),
            'thumbnail_path' => $data['thumbnail_path'] ?? null,
            'subtitle_path' => $data['subtitle_path'] ?? null,
            'generated_script' => $script,
            'caption' => $caption,
            'hashtags' => $hashtags,
            'content_source' => $data['content_source'] ?? 'gemini',
            'ai_model' => $data['ai_model'] ?? null,
            'score' => null,
            'duration' => isset($data['duration'])
                ? max((float) $reel->duration, (float) $data['duration'])
                : $reel->duration,
            'error_message' => null,
        ])->save();
    }

    private function markFailed(Reel $reel, string $stage, string $message): void
    {
        $reel->forceFill([
            'status' => 'failed',
            'progress_stage' => $stage,
            'error_message' => $message,
        ])->save();
    }

    private function markCancelled(Reel $reel): void
    {
        $reel->forceFill([
            'status' => 'cancelled',
            'progress_stage' => 'cancelled',
            'cancelled_at' => now(),
            'error_message' => trans('studio.errors.cancelled', [], $reel->language),
        ])->save();
    }
}
