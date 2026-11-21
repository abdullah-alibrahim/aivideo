<?php

namespace Tests\Feature;

use App\Exceptions\RenderFailedException;
use App\Jobs\GenerateReelVideo;
use App\Models\Reel;
use App\Models\User;
use App\Services\ElevenLabsVoiceoverService;
use App\Services\RenderEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RenderQueueTest extends TestCase
{
    use RefreshDatabase;

    private function samplePayload(): array
    {
        return [
            'topic' => 'queue test',
            'language' => 'en',
            'duration' => 10,
            'bg_preset' => 'finance_grid',
        ];
    }

    public function test_generation_dispatches_the_render_job_and_returns_immediately(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();
        config([
            'services.gemini.keys_json' => '["test-gemini-key"]',
            'services.elevenlabs.api_key' => 'test-elevenlabs-key',
            'services.elevenlabs.voice_ids.en' => 'test-voice-id',
        ]);

        $response = $this->post(route('reels.store'), array_merge($this->samplePayload(), [
            'quality' => '720',
            'captions' => '0',
            'auto_music' => '0',
            'bg_preset' => 'rainy_city_street',
            'voiceover_enabled' => '1',
            'voiceover_tone' => 'warm',
            'sound_effects_enabled' => '1',
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
                && $job->options['auto_music'] === false
                && $job->options['voiceover'] === true
                && $job->options['voiceover_tone'] === 'warm'
                && ($job->options['sound_effects'] ?? false) === true
                && $job->reel->camera_motion === 'static'
                && $job->options['motion_preset'] === 'minimal';
        });

        $reel = Reel::query()->firstOrFail();
        $this->assertSame('queued', $reel->status);
        $this->assertSame(0, $reel->progress);
        $this->assertNull($reel->score);
        $this->assertArrayNotHasKey('gemini_keys', $reel->render_options);
    }

    public function test_video_language_does_not_change_the_interface_language_after_generation(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['app_locale' => 'en']);
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');

        $response = $this->from(route('reels.wizard', ['lang' => 'en']))
            ->post(route('reels.store'), array_merge($this->samplePayload(), [
                'language' => 'ar',
                'manual_content' => '1',
                'headline' => 'عنوان تجريبي',
                'line1' => 'سطر تجريبي',
                'bg_preset' => 'rainy_city_street',
            ]));

        $reel = Reel::query()->firstOrFail();
        $response->assertRedirect(route('reels.index', ['active' => $reel->id, 'lang' => 'en']))
            ->assertSessionHas('success', trans('studio.flash.queued', [], 'en'));
        $this->assertSame('ar', $reel->language);
        $this->assertSame('en', session('app_locale'));
        $this->get(route('reels.index'))->assertSee('<html lang="en" dir="ltr"', false);
    }

    public function test_generation_accepts_a_custom_duration_and_preserves_it_for_rendering(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();
        config(['services.gemini.keys_json' => '["test-gemini-key"]']);

        $this->post(route('reels.store'), array_merge($this->samplePayload(), [
            'duration' => 37,
            'manual_content' => '1',
            'headline' => 'Test headline',
            'line1' => 'First point',
            'bg_preset' => 'rainy_city_street',
        ]))->assertRedirect();

        $reel = Reel::query()->firstOrFail();
        $this->assertSame(37.0, $reel->duration);
        Queue::assertPushed(GenerateReelVideo::class, fn (GenerateReelVideo $job): bool => $job->reel->duration === 37.0);
    }

    public function test_manual_wizard_script_preserves_every_scene_and_approved_cta_for_rendering(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();

        $this->post(route('reels.store'), array_merge($this->samplePayload(), [
            'manual_content' => '1',
            'headline' => 'A practical savings hook',
            'line1' => 'Track small expenses',
            'line2' => 'Choose a weekly limit',
            'line3' => 'Move savings automatically',
            'custom_points' => ['Review your progress', 'Adjust next month'],
            'cta' => 'Start your plan today',
            'bg_preset' => 'rainy_city_street',
        ]))->assertRedirect();

        $reel = Reel::query()->firstOrFail();
        $expectedPoints = [
            'Track small expenses',
            'Choose a weekly limit',
            'Move savings automatically',
            'Review your progress',
            'Adjust next month',
        ];

        $this->assertSame($expectedPoints, $reel->render_options['custom_points']);
        $this->assertSame('Start your plan today', $reel->render_options['cta']);
        Queue::assertPushed(GenerateReelVideo::class, fn (GenerateReelVideo $job): bool => $job->options['custom_points'] === $expectedPoints
            && $job->options['cta'] === 'Start your plan today');
    }

    public function test_numbered_list_design_is_preserved_in_the_queued_render(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();

        $this->post(route('reels.store'), array_merge($this->samplePayload(), [
            'template' => 'numbered_list',
            'manual_content' => '1',
            'headline' => 'Phrases for a billing call',
            'line1' => 'Please send an itemized statement.',
            'bg_preset' => 'rainy_city_street',
        ]))->assertRedirect();

        $reel = Reel::query()->firstOrFail();
        $this->assertSame('numbered_list', $reel->template);
        Queue::assertPushed(GenerateReelVideo::class, fn (GenerateReelVideo $job): bool => $job->reel->template === 'numbered_list');
    }

    public function test_generation_rejects_durations_outside_ten_to_sixty_seconds(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();

        foreach ([9, 61, 10.5] as $duration) {
            $this->from(route('reels.wizard'))
                ->post(route('reels.store'), array_merge($this->samplePayload(), ['duration' => $duration]))
                ->assertSessionHasErrors('duration');
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, Reel::query()->count());
    }

    public function test_scenario_rejects_a_duration_below_ten_seconds(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('reels.index'))
            ->post(route('reels.scenario'), [
                'idea' => 'A practical short video',
                'duration' => 9,
            ])
            ->assertSessionHasErrors('duration');

        $this->assertSame(0, Reel::query()->count());
    }

    public function test_quality_check_rejects_a_duration_below_ten_seconds(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson(route('reels.quality-check'), ['duration' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration');
    }

    public function test_missing_duration_defaults_to_one_minute_and_uses_multiple_motion_backgrounds(): void
    {
        $token = (string) Str::uuid();
        $catalog = [];
        $createdFiles = [];
        for ($index = 0; $index < 4; $index++) {
            $file = "test-motion-{$token}-{$index}.mp4";
            $poster = "test-motion-{$token}-{$index}.jpg";
            file_put_contents(public_path('backgrounds/'.$file), 'motion');
            file_put_contents(public_path('backgrounds/'.$poster), 'poster');
            $createdFiles[] = public_path('backgrounds/'.$file);
            $createdFiles[] = public_path('backgrounds/'.$poster);
            $catalog[] = [
                'id' => "test-motion-{$index}",
                'file' => $file,
                'poster' => $poster,
                'media_type' => 'video',
                'asset_directory' => 'public',
                'category_id' => 'abstract',
                'category' => ['ar' => 'تجريدي', 'en' => 'Abstract'],
                'name' => ['ar' => 'خلفية اختبار', 'en' => 'Test background'],
                'description' => ['ar' => 'متحركة للاختبار', 'en' => 'Motion test asset'],
            ];
        }

        config(['reel-backgrounds' => $catalog]);
        $this->actingAs(User::factory()->create());
        Queue::fake();
        config(['services.gemini.keys_json' => '["test-gemini-key"]']);

        try {
            $this->post(route('reels.store'), [
                'topic' => 'A minute-long video',
                'language' => 'en',
                'bg_preset' => 'test-motion-0',
            ])->assertRedirect();

            $reel = Reel::query()->firstOrFail();
            $this->assertSame(60.0, $reel->duration);
            $selectedBackgroundPath = public_path('backgrounds/'.$catalog[0]['file']);
            Queue::assertPushed(GenerateReelVideo::class, function (GenerateReelVideo $job) use ($selectedBackgroundPath): bool {
                return count($job->inputPaths) === 4
                    && $job->inputPaths[0] === $selectedBackgroundPath
                    && count(array_filter($job->inputPaths, static fn (string $path): bool => str_ends_with($path, '.mp4'))) === 4
                    && count(array_unique($job->inputPaths)) === 4
                    && $job->options['background_sequence'] === true;
            });
        } finally {
            foreach ($createdFiles as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_studio_engine_configuration_keeps_all_defaults_in_one_section(): void
    {
        $engine = config('studio.engine');

        $this->assertIsArray($engine);
        $this->assertSame('ffmpeg', $engine['driver']);
        $this->assertNotEmpty($engine['ffmpeg_bin']);
        $this->assertNotEmpty($engine['ffprobe_bin']);
        $this->assertSame(900, $engine['render_timeout_seconds']);
    }

    public function test_motion_library_settings_are_passed_to_the_render_job(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();
        config()->set('services.gemini.keys_json', '["test-gemini-key"]');

        $response = $this->post(route('reels.store'), array_merge($this->samplePayload(), [
            'camera_motion' => 'parallax',
            'motion_preset' => 'cinematic_depth',
            'text_motion' => 'slide_left',
            'scene_transition' => 'varied',
            'hook_word_by_word' => '1',
            'highlight_important' => '0',
            'bg_preset' => 'rainy_city_street',
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

        $this->mock(RenderEngine::class, function ($mock): void {
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
        $job->handle(app(RenderEngine::class));

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

        $this->mock(RenderEngine::class, function ($mock): void {
            $mock->shouldReceive('render')->once()->andThrow(
                new RenderFailedException('gemini_required', false, 'Gemini key is required.'),
            );
        });

        $job = new GenerateReelVideo($reel, ['/tmp/source.mp4'], null, [], 'reels/job-failure.mp4');
        $job->handle(app(RenderEngine::class));

        $reel->refresh();
        $this->assertSame('failed', $reel->status);
        $this->assertSame('gemini_required', $reel->progress_stage);
        $this->assertSame('Gemini key is required.', $reel->error_message);
    }

    public function test_elevenlabs_failure_returns_a_clear_arabic_message(): void
    {
        config([
            'services.elevenlabs.api_key' => 'test-elevenlabs-key',
            'services.elevenlabs.voice_ids.ar' => 'test-voice-id',
        ]);
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response(['detail' => 'Bad request'], 400),
        ]);
        Http::preventStrayRequests();

        try {
            app(ElevenLabsVoiceoverService::class)->generate(
                'نص اختبار',
                'ar',
                'warm',
                storage_path('framework/testing/narration.mp3'),
            );
            $this->fail('Expected ElevenLabs generation to fail.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('voiceover_failed', $exception->stage);
            $this->assertSame(
                'فشل ElevenLabs في إنشاء التعليق الصوتي. تحقّق من المفتاح ومعرّف الصوت ثم أعد المحاولة.',
                $exception->userMessage,
            );
        }
    }

    public function test_stored_voiceover_translation_key_is_displayed_as_an_arabic_message(): void
    {
        $reel = Reel::create([
            'title' => 'Voiceover failure',
            'topic' => 'Voiceover failure',
            'language' => 'ar',
            'status' => 'failed',
            'progress_stage' => 'voiceover_failed',
            'error_message' => 'studio.errors.voiceover_failed',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('reels.index', ['active' => $reel->id, 'lang' => 'ar']));

        $response->assertOk()
            ->assertSee('فشل ElevenLabs في إنشاء التعليق الصوتي. تحقّق من المفتاح ومعرّف الصوت ثم أعد المحاولة.')
            ->assertDontSee('studio.errors.voiceover_failed');
    }

    public function test_retrying_a_voiceover_failure_skips_narration_and_keeps_sound_effects(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('input_videos/retry-source.mp4', 'source');
        Queue::fake();

        $reel = Reel::create([
            'title' => 'Retry without narration',
            'topic' => 'Retry without narration',
            'language' => 'ar',
            'status' => 'failed',
            'progress_stage' => 'voiceover_failed',
            'input_videos' => ['input_videos/retry-source.mp4'],
            'render_options' => [
                'manual_content' => true,
                'voiceover' => true,
                'sound_effects' => true,
            ],
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('reels.retry', $reel));

        $response->assertRedirect(route('reels.index', ['active' => $reel->id, 'lang' => 'ar']))
            ->assertSessionHas('success', 'تمت إعادة الفيديو إلى قائمة الانتظار.');
        $reel->refresh();
        $this->assertFalse($reel->render_options['voiceover']);
        $this->assertTrue($reel->render_options['sound_effects']);
        Queue::assertPushed(GenerateReelVideo::class, function (GenerateReelVideo $job): bool {
            return $job->options['voiceover'] === false
                && $job->options['sound_effects'] === true;
        });
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

        $this->mock(RenderEngine::class, function ($mock): void {
            $mock->shouldNotReceive('render');
        });

        $job = new GenerateReelVideo($reel, ['/tmp/source.mp4'], null, [], 'reels/cancelled.mp4');
        $job->handle(app(RenderEngine::class));

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
