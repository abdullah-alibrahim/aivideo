<?php

namespace Tests\Feature;

use App\Exceptions\RenderFailedException;
use App\Models\ProviderApiKey;
use App\Models\Reel;
use App\Services\FfmpegReelRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FfmpegReelRendererTest extends TestCase
{
    use RefreshDatabase;

    private string $fakeFfmpeg;

    private string $inputPath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->fakeFfmpeg = $this->fakeFfmpeg('success');
        $this->inputPath = tempnam(sys_get_temp_dir(), 'reel-input-');
        file_put_contents($this->inputPath, 'fixture');
        config()->set('studio.engine.ffmpeg_bin', $this->fakeFfmpeg);
        config()->set('studio.engine.render_timeout_seconds', 10);
        config()->set('studio.engine.arabic_font', base_path('../python_engine/fonts/NotoKufiArabic.ttf'));
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeFfmpeg);
        @unlink($this->inputPath);
        parent::tearDown();
    }

    public function test_render_writes_mp4_thumbnail_subtitles_and_normalized_metadata(): void
    {
        $reel = Reel::create([
            'title' => 'Arabic render',
            'topic' => 'فكرة عملية',
            'language' => 'ar',
            'duration' => 5,
            'status' => 'processing',
            'render_options' => [],
        ]);

        $result = app(FfmpegReelRenderer::class)->render(
            $reel,
            [$this->inputPath],
            null,
            [
                'manual_content' => true,
                'custom_headline' => 'ابدأ بخطوة صغيرة',
                'custom_points' => ['خطط للخطوة الأولى'],
                'captions' => true,
            ],
            'reels/fixture.mp4',
        );

        Storage::disk('public')->assertExists('reels/fixture.mp4');
        Storage::disk('public')->assertExists('thumbnails/fixture.jpg');
        Storage::disk('public')->assertExists('subtitles/fixture.srt');
        $this->assertSame('manual', $result['content_source']);
        $this->assertSame('ابدأ بخطوة صغيرة', $result['headline']);
        $this->assertSame('thumbnails/fixture.jpg', $result['thumbnail_path']);
        $this->assertSame('subtitles/fixture.srt', $result['subtitle_path']);
    }

    public function test_render_accepts_english_manual_content_without_python(): void
    {
        $reel = Reel::create([
            'title' => 'English render',
            'topic' => 'Simple planning',
            'language' => 'en',
            'duration' => 5,
            'status' => 'processing',
        ]);

        $result = app(FfmpegReelRenderer::class)->render(
            $reel,
            [$this->inputPath],
            null,
            [
                'manual_content' => true,
                'custom_headline' => 'Plan one small step',
                'custom_points' => ['Write it down'],
            ],
            'reels/english-fixture.mp4',
        );

        Storage::disk('public')->assertExists('reels/english-fixture.mp4');
        $this->assertSame('manual', $result['content_source']);
        $this->assertSame('Plan one small step Write it down Save this for later', $result['script']);
    }

    public function test_render_preview_writes_an_image_without_publishing_a_public_storage_url(): void
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'reel-preview-');
        if ($outputPath === false) {
            $this->fail('Unable to allocate preview output.');
        }
        unlink($outputPath);

        try {
            app(FfmpegReelRenderer::class)->renderPreview([
                'language' => 'ar',
                'headline' => 'عنوان المعاينة',
                'custom_points' => ['خطوة أولى'],
                'aspect_ratio' => '9:16',
            ], $outputPath);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(8, filesize($outputPath));
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_render_draft_video_writes_a_sample_mp4(): void
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'reel-draft-');
        if ($outputPath === false) {
            $this->fail('Unable to allocate draft output.');
        }
        unlink($outputPath);

        try {
            app(FfmpegReelRenderer::class)->renderDraftVideo([
                'language' => 'ar',
                'headline' => 'مسودة سريعة',
                'custom_points' => ['خطوة أولى'],
                'aspect_ratio' => '9:16',
                'template' => 'classic',
                'camera_motion' => 'zoom_in',
                'motion_preset' => 'cinematic_depth',
                'scene_transition' => 'auto',
            ], $outputPath);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_render_draft_video_can_narrate_with_the_selected_voice(): void
    {
        Http::preventStrayRequests();
        $text = 'مسودة صوتية '.uniqid();
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $starts = [];
        $ends = [];
        foreach ($characters as $index => $character) {
            $starts[] = $index * 0.1;
            $ends[] = ($index + 1) * 0.1;
        }
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*/with-timestamps*' => Http::response([
                'audio_base64' => base64_encode('draft-narration'),
                'normalized_alignment' => [
                    'characters' => $characters,
                    'character_start_times_seconds' => $starts,
                    'character_end_times_seconds' => $ends,
                ],
            ], 200),
        ]);
        ProviderApiKey::query()->create([
            'provider' => 'elevenlabs',
            'label' => 'draft-voice',
            'secret' => 'draft-key',
            'is_active' => true,
        ]);
        $outputPath = tempnam(sys_get_temp_dir(), 'reel-draft-voice-');
        if ($outputPath === false) {
            $this->fail('Unable to allocate draft output.');
        }
        unlink($outputPath);

        try {
            app(FfmpegReelRenderer::class)->renderDraftVideo([
                'language' => 'ar',
                'headline' => $text,
                'custom_points' => [],
                'cta' => '',
                'aspect_ratio' => '9:16',
                'voiceover' => true,
                'voice_id' => 'voice_draft',
                'voiceover_tone' => 'warm',
            ], $outputPath);

            $this->assertFileExists($outputPath);
            Http::assertSent(static fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/text-to-speech/voice_draft/with-timestamps')
                && data_get($request->data(), 'text') === $text);
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_render_rejects_missing_inputs_without_publishing_outputs(): void
    {
        $reel = Reel::create(['title' => 'Missing input', 'topic' => 'Missing input', 'language' => 'en', 'status' => 'processing']);

        try {
            app(FfmpegReelRenderer::class)->render($reel, ['/missing/input.mp4'], null, [], 'reels/missing.mp4');
            $this->fail('A missing input must fail before FFmpeg starts.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('input_invalid', $exception->stage);
            $this->assertFalse($exception->retryable);
        }

        Storage::disk('public')->assertMissing('reels/missing.mp4');
    }

    public function test_render_cleans_partial_outputs_when_ffmpeg_fails(): void
    {
        file_put_contents($this->fakeFfmpeg, $this->fakeFfmpegSource('failure'));
        chmod($this->fakeFfmpeg, 0700);
        $reel = Reel::create(['title' => 'Failed render', 'topic' => 'Failed render', 'language' => 'en', 'status' => 'processing']);

        try {
            app(FfmpegReelRenderer::class)->render($reel, [$this->inputPath], null, ['manual_content' => true, 'custom_headline' => 'Headline', 'custom_points' => ['Point']], 'reels/failure.mp4');
            $this->fail('A non-zero FFmpeg exit must fail rendering.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('render_failed', $exception->stage);
        }

        Storage::disk('public')->assertMissing('reels/failure.mp4');
        Storage::disk('public')->assertMissing('thumbnails/failure.jpg');
    }

    public function test_render_cleans_outputs_after_timeout(): void
    {
        file_put_contents($this->fakeFfmpeg, $this->fakeFfmpegSource('timeout'));
        chmod($this->fakeFfmpeg, 0700);
        config()->set('studio.engine.render_timeout_seconds', 1);
        $reel = Reel::create(['title' => 'Timed out render', 'topic' => 'Timed out render', 'language' => 'en', 'status' => 'processing']);

        try {
            app(FfmpegReelRenderer::class)->render($reel, [$this->inputPath], null, ['manual_content' => true, 'custom_headline' => 'Headline', 'custom_points' => ['Point']], 'reels/timeout.mp4');
            $this->fail('A timed-out FFmpeg process must fail rendering.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('timeout', $exception->stage);
            $this->assertTrue($exception->retryable);
        }

        Storage::disk('public')->assertMissing('reels/timeout.mp4');
    }

    public function test_render_rejects_output_paths_outside_the_reels_directory(): void
    {
        $reel = Reel::create(['title' => 'Unsafe path', 'topic' => 'Unsafe path', 'language' => 'en', 'status' => 'processing']);

        try {
            app(FfmpegReelRenderer::class)->render($reel, [$this->inputPath], null, [], '../public/unsafe.mp4');
            $this->fail('A render cannot publish outside the reels directory.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('invalid_result', $exception->stage);
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_cancelled_render_does_not_start_ffmpeg_or_leave_outputs(): void
    {
        $reel = Reel::create([
            'title' => 'Cancelled render',
            'topic' => 'Cancelled render',
            'language' => 'en',
            'status' => 'processing',
            'cancel_requested_at' => now(),
        ]);

        try {
            app(FfmpegReelRenderer::class)->render($reel, [$this->inputPath], null, [], 'reels/cancelled.mp4');
            $this->fail('A cancelled render must stop before starting FFmpeg.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('cancelled', $exception->stage);
        }

        Storage::disk('public')->assertMissing('reels/cancelled.mp4');
    }

    public function test_render_does_not_publish_when_cancelled_after_ffmpeg_exits(): void
    {
        Reel::updated(static function (Reel $reel): void {
            if ($reel->progress_stage === 'finalizing' && $reel->progress === 95) {
                $reel->cancel_requested_at = now();
                $reel->saveQuietly();
            }
        });
        $reel = Reel::create([
            'title' => 'Cancelled after encoding',
            'topic' => 'Cancelled after encoding',
            'language' => 'en',
            'status' => 'processing',
        ]);

        try {
            app(FfmpegReelRenderer::class)->render(
                $reel,
                [$this->inputPath],
                null,
                ['manual_content' => true, 'custom_headline' => 'Headline', 'custom_points' => ['Point']],
                'reels/cancelled-after-encoding.mp4',
            );
            $this->fail('A render cancelled after encoding must not publish files.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('cancelled', $exception->stage);
            $this->assertFalse($exception->retryable);
        }

        Storage::disk('public')->assertMissing('reels/cancelled-after-encoding.mp4');
        Storage::disk('public')->assertMissing('thumbnails/cancelled-after-encoding.jpg');
    }

    private function fakeFfmpeg(string $mode): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fake-ffmpeg-');
        file_put_contents($path, $this->fakeFfmpegSource($mode));
        chmod($path, 0700);

        return $path;
    }

    private function fakeFfmpegSource(string $mode): string
    {
        return match ($mode) {
            'failure' => "#!/bin/sh\nexit 1\n",
            'timeout' => "#!/bin/sh\nsleep 3\nexit 0\n",
            default => <<<'SH'
#!/bin/sh
last=
for arg do last=$arg; done
case "$last" in
  *.mp4) head -c 2048 /dev/zero > "$last" ;;
  *.jpg) printf 'thumbnail' > "$last" ;;
  *.png) head -c 2048 /dev/zero > "$last" ;;
esac
printf 'out_time_ms=5000000\nprogress=continue\n'
printf 'out_time_ms=5000000\nprogress=end\n'
SH,
        };
    }
}
