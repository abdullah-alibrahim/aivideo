<?php

namespace Tests\Feature;

use App\Exceptions\RenderFailedException;
use App\Jobs\GenerateReelVideo;
use App\Models\Reel;
use App\Services\ReelRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenderQueueTest extends TestCase
{
    use RefreshDatabase;

    private function samplePayload(): array
    {
        return [
            'topic' => 'queue test',
            'language' => 'en',
            'duration' => 5,
            'bg_preset' => 'finance_grid',
        ];
    }

    public function test_generation_dispatches_the_render_job_and_returns_immediately(): void
    {
        Queue::fake();
        config()->set('services.gemini.keys_json', '["test-gemini-key"]');

        $response = $this->post(route('reels.store'), array_merge($this->samplePayload(), [
            'quality' => '720',
            'captions' => '0',
            'auto_music' => '0',
            'bg_preset' => 'rainy_city_street',
        ]));

        $response->assertRedirect();
        Queue::assertPushed(GenerateReelVideo::class, function (GenerateReelVideo $job): bool {
            return $job->reel->status === 'queued'
                && $job->outputRelativePath !== ''
                && $job->inputPaths === [public_path('backgrounds/rainy_city_street.jpg')]
                && $job->options['gemini_keys'] !== []
                && $job->options['bg_preset'] === 'rainy_city_street'
                && $job->options['quality'] === 720
                && $job->options['captions'] === false
                && $job->options['auto_music'] === false;
        });

        $reel = Reel::query()->firstOrFail();
        $this->assertSame('queued', $reel->status);
        $this->assertSame(0, $reel->progress);
        $this->assertNull($reel->score);
        $this->assertArrayNotHasKey('gemini_keys', $reel->render_options);
    }

    public function test_studio_engine_configuration_keeps_all_defaults_in_one_section(): void
    {
        $engine = config('studio.engine');

        $this->assertIsArray($engine);
        $this->assertNotEmpty($engine['python_bin']);
        $this->assertNotEmpty($engine['script']);
        $this->assertSame(900, $engine['render_timeout_seconds']);
    }

    public function test_motion_library_settings_are_passed_to_the_render_job(): void
    {
        Queue::fake();
        config()->set('services.gemini.keys_json', '["test-gemini-key"]');

        $response = $this->post(route('reels.store'), array_merge($this->samplePayload(), [
            'camera_motion' => 'parallax',
            'motion_preset' => 'cinematic_depth',
            'text_motion' => 'slide_left',
            'scene_transition' => 'varied',
            'hook_word_by_word' => '1',
            'highlight_important' => '0',
        ]));

        $response->assertRedirect();
        Queue::assertPushed(GenerateReelVideo::class, function (GenerateReelVideo $job): bool {
            return $job->reel->camera_motion === 'parallax'
                && $job->options['motion_preset'] === 'cinematic_depth'
                && $job->options['text_motion'] === 'slide_left'
                && $job->options['scene_transition'] === 'varied'
                && $job->options['hook_word_by_word'] === true
                && $job->options['highlight_important'] === false;
        });
    }

    public function test_render_job_marks_a_reel_as_completed_with_model_metadata(): void
    {
        Storage::fake('public');

        $reel = Reel::create([
            'title' => 'Job success',
            'topic' => 'job success',
            'language' => 'en',
            'status' => 'queued',
            'duration' => 5,
        ]);

        $this->mock(ReelRenderer::class, function ($mock): void {
            $mock->shouldReceive('render')->once()->andReturn([
                'status' => 'success',
                'script' => 'Headline * text',
                'caption' => 'Generated caption',
                'hashtags' => '#generated',
                'score' => 100,
                'content_source' => 'gemini',
                'ai_model' => 'gemini-test',
                'duration' => 6.0,
                'thumbnail_path' => 'thumbnails/job-success.jpg',
                'subtitle_path' => 'subtitles/job-success.srt',
            ]);
        });

        $job = new GenerateReelVideo($reel, ['/tmp/source.mp4'], null, [], 'reels/job-success.mp4');
        $job->handle(app(ReelRenderer::class));

        $reel->refresh();
        $this->assertSame('completed', $reel->status);
        $this->assertSame(100, $reel->progress);
        $this->assertSame('gemini-test', $reel->ai_model);
        $this->assertSame('thumbnails/job-success.jpg', $reel->thumbnail_path);
        $this->assertSame('subtitles/job-success.srt', $reel->subtitle_path);
        $this->assertNull($reel->score);
        $this->assertSame('Headline  text', $reel->generated_script);
        $this->assertSame(6.0, $reel->duration);
    }

    public function test_render_job_marks_a_non_retryable_failure_with_the_user_message(): void
    {
        Storage::fake('public');

        $reel = Reel::create([
            'title' => 'Job failure',
            'topic' => 'job failure',
            'language' => 'en',
            'status' => 'processing',
        ]);

        $this->mock(ReelRenderer::class, function ($mock): void {
            $mock->shouldReceive('render')->once()->andThrow(
                new RenderFailedException('gemini_required', false, 'Gemini key is required.'),
            );
        });

        $job = new GenerateReelVideo($reel, ['/tmp/source.mp4'], null, [], 'reels/job-failure.mp4');
        $job->handle(app(ReelRenderer::class));

        $reel->refresh();
        $this->assertSame('failed', $reel->status);
        $this->assertSame('gemini_required', $reel->progress_stage);
        $this->assertSame('Gemini key is required.', $reel->error_message);
    }

    public function test_cancelled_render_job_does_not_start_the_python_renderer(): void
    {
        Storage::fake('public');

        $reel = Reel::create([
            'title' => 'Cancelled job',
            'topic' => 'Cancelled job',
            'language' => 'en',
            'status' => 'cancellation_requested',
            'cancel_requested_at' => now(),
        ]);

        $this->mock(ReelRenderer::class, function ($mock): void {
            $mock->shouldNotReceive('render');
        });

        $job = new GenerateReelVideo($reel, ['/tmp/source.mp4'], null, [], 'reels/cancelled.mp4');
        $job->handle(app(ReelRenderer::class));

        $reel->refresh();
        $this->assertSame('cancelled', $reel->status);
        $this->assertSame('cancelled', $reel->progress_stage);
        $this->assertNotNull($reel->cancelled_at);
    }

    public function test_prune_command_removes_old_failed_reels_and_orphans(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('reels/old.mp4', 'old');
        Storage::disk('public')->put('reels/orphan.mp4', 'orphan');
        Storage::disk('local')->put('input_videos/orphan.mp4', 'orphan');

        $reel = Reel::create([
            'title' => 'Old failed',
            'topic' => 'old failed',
            'status' => 'failed',
            'output_video_path' => 'reels/old.mp4',
        ]);
        $reel->forceFill(['updated_at' => now()->subDays(40)])->save();

        touch(Storage::disk('public')->path('reels/orphan.mp4'), now()->subDays(40)->timestamp);
        touch(Storage::disk('local')->path('input_videos/orphan.mp4'), now()->subDays(40)->timestamp);

        $this->artisan('reels:prune', ['--days' => 30])
            ->expectsOutputToContain('Pruned 1 failed reel(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('reels', ['id' => $reel->id]);
        Storage::disk('public')->assertMissing('reels/old.mp4');
        Storage::disk('public')->assertMissing('reels/orphan.mp4');
        Storage::disk('local')->assertMissing('input_videos/orphan.mp4');
    }
}
