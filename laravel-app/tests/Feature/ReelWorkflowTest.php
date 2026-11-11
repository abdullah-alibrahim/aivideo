<?php

namespace Tests\Feature;

use App\Jobs\GenerateReelVideo;
use App\Models\Reel;
use App\Models\ReelCollection;
use App\Services\FactCheckService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReelWorkflowTest extends TestCase
{
    public function test_analytics_dashboard_renders_key_metrics(): void
    {
        Reel::create(['title' => 'Complete', 'topic' => 'Complete', 'language' => 'en', 'status' => 'completed', 'duration' => 12]);
        Reel::create(['title' => 'Failed', 'topic' => 'Failed', 'language' => 'ar', 'status' => 'failed']);

        $this->get(route('analytics.dashboard', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('Analytics Dashboard')
            ->assertSee('Recent reels')
            ->assertSee('Complete');
    }

    public function test_fact_check_endpoint_returns_structured_report(): void
    {
        $this->mock(FactCheckService::class, function ($mock): void {
            $mock->shouldReceive('check')->once()->andReturn([
                'status' => 'review',
                'confidence' => 64,
                'summary' => 'Needs a source.',
                'claims' => [['claim' => 'A claim', 'verdict' => 'uncertain', 'reason' => 'No source supplied.', 'needs_source' => true, 'recommendation' => 'Add a source.']],
                'risk_flags' => ['Health claim'],
                'disclaimer' => 'AI screening only.',
                'ai_model' => 'test-model',
            ]);
        });

        $this->postJson(route('ai.fact-check'), [
            'language' => 'en',
            'topic' => 'Health topic',
            'script' => 'A claim without a citation.',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('report.status', 'review')
            ->assertJsonPath('report.claims.0.needs_source', true);
    }

    public function test_wizard_renders_in_arabic_with_a_csrf_token(): void
    {
        $this->get(route('reels.wizard', ['lang' => 'ar']))
            ->assertOk()
            ->assertSee('name="csrf-token"', false)
            ->assertSee('id="wizardMultiStepForm"', false)
            ->assertSee('<option value="ar" selected>', false)
            ->assertSee('value="حدّد خطوة واحدة واضحة للبدء."', false)
            ->assertSee('name="anti_border" value="1" checked', false)
            ->assertSee('id="wizardMusicPreview"', false)
            ->assertSee('name="voiceover_enabled"', false)
            ->assertSee('name="voiceover_tone"', false)
            ->assertSee('name="aspect_ratio"', false)
            ->assertSee('<option value="1:1">', false)
            ->assertSee('<option value="16:9">', false)
            ->assertSee('ثلاث خطوات بسيطة للبدء')
            ->assertDontSee('Studio Pro')
            ->assertDontSee('Sound Collection')
            ->assertDontSee('Green and Gold')
            ->assertDontSee('Abdullah Al-Ibrahem')
            ->assertSee('value="finance_grid_still"', false)
            ->assertSee('data-background-search', false)
            ->assertSee('data-background-type="video"', false)
            ->assertSee('data-background-type="image"', false)
            ->assertSee('data-background-category="nature"', false)
            ->assertSee('data-background-favorites', false)
            ->assertSee('value="rainy_city_motion"', false)
            ->assertSee('value="ocean_dusk"', false)
            ->assertSee('id="motionPreset"', false)
            ->assertSee('value="cinematic_depth"', false)
            ->assertSee('id="cameraMotion"', false)
            ->assertSee('id="sceneTransition"', false)
            ->assertSee('value="rainy_city_street"', false)
            ->assertSee($this->renderableBackgroundCount().' خلفية محلية')
            ->assertSee('مكتبة الخلفيات');
    }

    /**
     * Count catalog entries that actually exist on disk, mirroring the
     * controller filter so the picker badge stays in sync with the page.
     */
    private function renderableBackgroundCount(): int
    {
        return collect(config('reel-backgrounds', []))->filter(function (array $background): bool {
            $directory = ($background['asset_directory'] ?? 'storage') === 'public'
                ? public_path('backgrounds')
                : \App\Support\StudioPaths::backgrounds();

            return is_file($directory.'/'.$background['file']) && is_file($directory.'/'.$background['poster']);
        })->count();
    }

    public function test_preview_rejects_an_unknown_template(): void
    {
        $this->postJson(route('reels.preview'), ['template' => 'unknown'])
            ->assertUnprocessable();
    }

    public function test_preview_reports_a_missing_renderer(): void
    {
        config()->set('studio.engine.python_bin', '/nonexistent/python3');

        $this->postJson(route('reels.preview'), [
            'headline' => 'Preview',
            'language' => 'en',
            'template' => 'classic',
        ])->assertStatus(502);
    }

    public function test_security_headers_are_sent_on_html_responses(): void
    {
        $this->get(route('reels.index'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_studio_auth_protects_sensitive_routes_when_enabled(): void
    {
        config()->set('studio.security.enabled', true);
        config()->set('studio.security.username', 'admin');
        config()->set('studio.security.password', 'test-password');
        $reel = Reel::create([
            'title' => 'Protected stream',
            'topic' => 'Protected stream',
            'status' => 'completed',
        ]);

        $this->get(route('settings.provider-keys'))->assertUnauthorized();
        $this->get(route('reels.stream', $reel))->assertUnauthorized();

        $this->withBasicAuth('admin', 'test-password')
            ->get(route('settings.provider-keys'))
            ->assertOk();
    }

    public function test_player_page_has_an_explicit_retry_control_for_completed_reels(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('reels/player-test.mp4', 'sample-video');

        $reel = Reel::create([
            'title' => 'Player test',
            'topic' => 'Player test',
            'output_video_path' => 'reels/player-test.mp4',
            'status' => 'completed',
        ]);

        $this->get(route('reels.index', ['active' => $reel->id, 'lang' => 'en']))
            ->assertOk()
            ->assertSee('id="retryVideoBtn"', false)
            ->assertSee('<option value="en" selected>', false);
    }

    public function test_reel_status_endpoint_returns_progress_and_stage(): void
    {
        $reel = Reel::create([
            'title' => 'Progress test',
            'topic' => 'Progress test',
            'status' => 'processing',
            'progress' => 35,
            'progress_stage' => 'generating_with_gemini',
        ]);

        $this->getJson(route('reels.status', $reel))
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('progress', 35)
            ->assertJsonPath('stage', 'generating_with_gemini')
            ->assertJsonPath('ready', false);
    }

    public function test_cancel_route_marks_a_running_reel_for_safe_cancellation(): void
    {
        $reel = Reel::create([
            'title' => 'Cancelable render',
            'topic' => 'Cancelable render',
            'language' => 'en',
            'status' => 'processing',
            'progress' => 42,
            'progress_stage' => 'rendering',
        ]);

        $this->post(route('reels.cancel', $reel))
            ->assertRedirect(route('reels.index', ['active' => $reel->id, 'lang' => 'en']))
            ->assertSessionHas('success');

        $reel->refresh();
        $this->assertSame('cancellation_requested', $reel->status);
        $this->assertSame('cancelling', $reel->progress_stage);
        $this->assertNotNull($reel->cancel_requested_at);
    }

    public function test_idea_suggestion_returns_a_complete_result(): void
    {
        $this->fakeGemini(['badge' => 'فكرة', 'title' => 'عنوان', 'desc' => 'وصف', 'why' => 'سبب', 'angle' => 'زاوية', 'hook' => 'Hook', 'hook_desc' => 'وصف hook']);
        $this->postJson(route('ai.idea'), [
            'prompt' => 'خطوات بسيطة للتوفير',
            'language' => 'ar',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'idea' => ['title', 'desc', 'hook'],
            ]);
    }

    public function test_caption_generator_returns_the_selected_style_and_language(): void
    {
        $this->fakeGemini(['name' => 'مختصر', 'caption' => 'كابشن', 'hashtags' => '#نصائح #تعلم #معلومات #ريلز #محتوى']);
        $this->postJson(route('ai.captions'), [
            'headline' => 'ثلاث عادات صباحية',
            'style' => 'short',
            'language' => 'ar',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'مختصر')
            ->assertJsonPath('data.hashtags', '#نصائح #تعلم #معلومات #ريلز #محتوى');
    }

    public function test_script_punctuation_action_adds_a_language_appropriate_terminal_mark(): void
    {
        $this->fakeGemini(['lines' => ['نص تجريبي!', 'هل هذه خطوة واضحة؟', 'نهاية!']]);
        $this->postJson(route('ai.script'), [
            'action' => 'punctuate',
            'language' => 'ar',
            'line1' => 'نص تجريبي',
            'line2' => 'هل هذه خطوة واضحة؟',
            'line3' => 'نهاية',
        ])
            ->assertOk()
            ->assertJsonPath('lines.0', 'نص تجريبي!')
            ->assertJsonPath('lines.1', 'هل هذه خطوة واضحة؟')
            ->assertJsonPath('lines.2', 'نهاية!');
    }

    private function fakeGemini(array $data): void
    {
        config(['services.gemini.keys_json' => json_encode(['AIza-test-key'])]);
        Http::fake([
            '*generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($data, JSON_UNESCAPED_UNICODE)]]],
                ]],
            ], 200),
        ]);
    }

    public function test_generation_rejects_an_unsupported_language_before_creating_a_reel(): void
    {
        $response = $this->from(route('reels.wizard'))
            ->post(route('reels.store'), [
                'topic' => 'Test reel',
                'language' => 'fr',
            ]);

        $response->assertRedirect(route('reels.wizard'))
            ->assertSessionHasErrors('language');
        $this->assertDatabaseCount('reels', 0);
    }

    public function test_voiceover_requires_server_credentials_before_creating_a_reel(): void
    {
        config([
            'services.elevenlabs.api_key' => null,
            'services.elevenlabs.voice_ids.ar' => null,
        ]);

        $response = $this->from(route('reels.wizard', ['lang' => 'ar']))
            ->post(route('reels.store'), [
                'topic' => 'اختبار التعليق الصوتي',
                'language' => 'ar',
                'voiceover_enabled' => '1',
            ]);

        $response->assertRedirect(route('reels.wizard', ['lang' => 'ar']))
            ->assertSessionHasErrors('voiceover_enabled');
        $this->assertDatabaseCount('reels', 0);
    }

    public function test_deleting_a_reel_removes_its_media_without_touching_unowned_paths(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('reels/generated.mp4', 'generated-video');
        Storage::disk('public')->put('kept/protected.mp4', 'keep-public');
        Storage::disk('local')->put('input_videos/source.mp4', 'uploaded-video');
        Storage::disk('local')->put('bg_music/track.mp3', 'uploaded-audio');
        Storage::disk('local')->put('kept/protected.mp4', 'keep-private');

        $reel = Reel::create([
            'title' => 'Delete test',
            'topic' => 'Delete test',
            'output_video_path' => 'reels/generated.mp4',
            'input_videos' => ['input_videos/source.mp4', 'input_videos/../kept/protected.mp4'],
            'bg_music_path' => 'bg_music/track.mp3',
            'status' => 'completed',
        ]);

        $this->delete(route('reels.destroy', $reel))
            ->assertRedirect(route('reels.index', ['lang' => 'ar']));

        $this->assertDatabaseMissing('reels', ['id' => $reel->id]);
        $this->assertFalse(Storage::disk('public')->exists('reels/generated.mp4'));
        $this->assertFalse(Storage::disk('local')->exists('input_videos/source.mp4'));
        $this->assertFalse(Storage::disk('local')->exists('bg_music/track.mp3'));
        $this->assertTrue(Storage::disk('public')->exists('kept/protected.mp4'));
        $this->assertTrue(Storage::disk('local')->exists('kept/protected.mp4'));
    }

    public function test_video_stream_returns_the_requested_byte_range(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('reels/sample.mp4', '0123456789');

        $reel = Reel::create([
            'title' => 'Range test',
            'topic' => 'Range test',
            'output_video_path' => 'reels/sample.mp4',
            'status' => 'completed',
        ]);

        $this->withHeader('Range', 'bytes=2-4')
            ->get(route('reels.stream', $reel))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-4/10')
            ->assertHeader('Content-Length', '3')
            ->assertStreamedContent('234');
    }

    public function test_video_stream_supports_suffix_ranges(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('reels/sample.mp4', '0123456789');

        $reel = Reel::create([
            'title' => 'Suffix range test',
            'topic' => 'Suffix range test',
            'output_video_path' => 'reels/sample.mp4',
            'status' => 'completed',
        ]);

        $this->withHeader('Range', 'bytes=-4')
            ->get(route('reels.stream', $reel))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 6-9/10')
            ->assertHeader('Content-Length', '4')
            ->assertStreamedContent('6789');
    }

    public function test_video_stream_rejects_an_unsatisfiable_range(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('reels/sample.mp4', '0123456789');

        $reel = Reel::create([
            'title' => 'Invalid range test',
            'topic' => 'Invalid range test',
            'output_video_path' => 'reels/sample.mp4',
            'status' => 'completed',
        ]);

        $this->withHeader('Range', 'bytes=50-60')
            ->get(route('reels.stream', $reel))
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_quality_check_endpoint_returns_a_persistable_pre_render_report(): void
    {
        $this->postJson(route('reels.quality-check'), [
            'headline' => 'A clear short hook',
            'topic' => 'Focus habits',
            'user_prompt' => 'A practical short video about focus habits.',
            'line1' => 'Turn off notifications.',
            'line2' => 'Work in short sessions.',
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'language' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('report.stage', 'pre_render')
            ->assertJsonPath('report.checks.0.key', 'hook')
            ->assertJsonPath('report.checks.1.status', 'pass')
            ->assertJsonPath('report.checks.3.status', 'pass');
    }

    public function test_generation_creates_distinct_version_reels_in_one_collection(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['services.gemini.keys_json' => json_encode(['test-gemini-key'])]);

        $this->post(route('reels.store'), [
            'generation_mode' => 'variants',
            'variant_count' => 3,
            'topic' => 'Better focus',
            'user_prompt' => 'Create a useful short video about focus.',
            'language' => 'en',
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'videos' => [UploadedFile::fake()->createWithContent('source.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\0", 128))],
        ])->assertRedirect();

        $this->assertDatabaseCount('reels', 3);
        $this->assertDatabaseCount('reel_collections', 1);
        $reels = Reel::orderBy('collection_position')->get();
        $this->assertSame([1, 2, 3], $reels->pluck('collection_position')->all());
        $this->assertCount(1, $reels->pluck('collection_id')->unique());
        $this->assertCount(3, $reels->pluck('user_prompt')->unique());
        $this->assertNotEmpty($reels->first()->quality_report['checks']);
        Queue::assertPushed(GenerateReelVideo::class, 3);
    }

    public function test_scenario_requires_an_active_gemini_key_before_generating(): void
    {
        config(['services.gemini.keys_json' => '[]']);
        Storage::fake('local');
        Storage::fake('public');

        $response = $this->from(route('reels.wizard'))
            ->post(route('reels.scenario'), [
                'idea' => 'Commit to one project for 48 hours.',
                'language' => 'en',
            ]);

        $response->assertRedirect(route('reels.wizard'))
            ->assertSessionHasErrors('idea');
        $this->assertDatabaseCount('reels', 0);
    }

    public function test_scenario_turns_a_plain_idea_into_a_queued_manual_render(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->fakeGemini([
            'title' => '48-Hour Project Sprint',
            'headline' => 'Give one project exactly 48 hours.',
            'points' => ['Pick the one project that matters most.', 'Clear every distraction before you start.', 'Ship a visible result before the timer ends.'],
            'caption' => 'Two days, one project, zero excuses.',
            'hashtags' => '#focus #productivity #discipline',
        ]);
        config(['services.gemini.keys_json' => json_encode(['test-gemini-key'])]);

        $response = $this->post(route('reels.scenario'), [
            'idea' => 'Commit to one project for the first 48 hours without distraction',
            'language' => 'en',
            'template' => 'bold_stripe',
            'duration' => 10,
            'goal' => 'saves',
            'videos' => [UploadedFile::fake()->createWithContent('source.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\0", 128))],
        ]);

        $reel = Reel::query()->first();
        $this->assertNotNull($reel);
        $response->assertRedirect(route('reels.index', ['active' => $reel->id, 'lang' => 'en']))
            ->assertSessionHas('success', __('studio.flash.scenario_queued', [], 'en'))
            ->assertSessionHas('scenario.headline', 'Give one project exactly 48 hours.');

        $this->assertSame('queued', $reel->status);
        $this->assertTrue($reel->render_options['manual_content']);
        $this->assertSame('Give one project exactly 48 hours.', $reel->render_options['custom_headline']);
        $this->assertCount(3, $reel->render_options['custom_points']);
        $this->assertSame('bold_stripe', $reel->template);
        $this->assertSame('saves', $reel->goal);
        $this->assertSame('Commit to one project for the first 48 hours without distraction', $reel->user_prompt);
        $this->assertNotEmpty($reel->quality_report['checks']);
        Queue::assertPushed(GenerateReelVideo::class, 1);
    }

    public function test_scenario_rejects_an_incomplete_gemini_script_without_queuing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->fakeGemini(['headline' => '', 'points' => []]);
        config(['services.gemini.keys_json' => json_encode(['test-gemini-key'])]);

        $response = $this->from(route('reels.wizard'))
            ->post(route('reels.scenario'), [
                'idea' => 'Commit to one project for 48 hours.',
                'language' => 'en',
            ]);

        $response->assertRedirect(route('reels.wizard'))
            ->assertSessionHasErrors('idea');
        $this->assertDatabaseCount('reels', 0);
    }

    public function test_batch_topics_create_one_queued_reel_per_topic(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['services.gemini.keys_json' => json_encode(['test-gemini-key'])]);

        $this->post(route('reels.store'), [
            'generation_mode' => 'batch',
            'batch_topics' => "Sleep routines\nDaily reading",
            'user_prompt' => 'Keep every video concise and practical.',
            'language' => 'en',
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'videos' => [UploadedFile::fake()->createWithContent('source.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\0", 128))],
        ])->assertRedirect();

        $this->assertDatabaseCount('reels', 2);
        $this->assertSame(['Sleep routines', 'Daily reading'], Reel::orderBy('collection_position')->pluck('topic')->all());
        $this->assertDatabaseHas('reel_collections', ['type' => 'batch', 'item_count' => 2]);
        Queue::assertPushed(GenerateReelVideo::class, 2);
    }

    public function test_deleting_a_collection_member_preserves_shared_source_files_until_last_reference(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('local')->put('input_videos/shared.mp4', 'source');
        Storage::disk('local')->put('bg_music/shared.mp3', 'music');
        $collection = ReelCollection::create(['id' => (string) Str::uuid(), 'type' => 'batch', 'label' => 'Shared', 'item_count' => 2]);
        $shared = ['input_videos/shared.mp4'];
        $first = Reel::create(['title' => 'First', 'topic' => 'First', 'collection_id' => $collection->id, 'input_videos' => $shared, 'bg_music_path' => 'bg_music/shared.mp3']);
        $second = Reel::create(['title' => 'Second', 'topic' => 'Second', 'collection_id' => $collection->id, 'input_videos' => $shared, 'bg_music_path' => 'bg_music/shared.mp3']);

        $this->delete(route('reels.destroy', $first))->assertRedirect();
        Storage::disk('local')->assertExists('input_videos/shared.mp4');
        Storage::disk('local')->assertExists('bg_music/shared.mp3');

        $this->delete(route('reels.destroy', $second))->assertRedirect();
        Storage::disk('local')->assertMissing('input_videos/shared.mp4');
        Storage::disk('local')->assertMissing('bg_music/shared.mp3');
        $this->assertDatabaseMissing('reel_collections', ['id' => $collection->id]);
    }

    public function test_collection_download_contains_completed_video_exports(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP ZipArchive extension is not installed.');
        }

        Storage::fake('public');
        Storage::disk('public')->put('reels/first.mp4', 'first-video');
        $collection = ReelCollection::create(['id' => (string) Str::uuid(), 'type' => 'variants', 'label' => 'Focus', 'item_count' => 1]);
        $reel = Reel::create([
            'title' => 'Focus version',
            'topic' => 'Focus',
            'status' => 'completed',
            'output_video_path' => 'reels/first.mp4',
            'collection_id' => $collection->id,
            'collection_position' => 1,
        ]);

        $this->get(route('reels.collections.download', $collection->id))
            ->assertOk()
            ->assertDownload('focus-versions.zip');
    }
}
