<?php

namespace Tests\Feature;

use App\Jobs\GenerateReelVideo;
use App\Models\Reel;
use App\Models\ReelCollection;
use App\Models\User;
use App\Services\FactCheckService;
use App\Services\GeminiContentService;
use App\Services\ReelQualityReviewService;
use App\Services\RenderEngine;
use App\Support\StudioPaths;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReelWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Studio routes require an authenticated, verified account.
        $this->actingAs(User::factory()->create([
            'email_verified_at' => now(),
        ]));
    }

    public function test_analytics_dashboard_renders_key_metrics(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
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
        $this->actingAs(User::factory()->create());
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
        $this->materializeBackgroundCatalog();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get(route('reels.wizard', ['lang' => 'ar']))
            ->assertOk()
            ->assertSee('name="csrf-token"', false)
            ->assertSee('id="wizardMultiStepForm"', false)
            ->assertSee('<option value="ar" selected>', false)
            ->assertSee('id="inputLine1"', false)
            ->assertSee('name="manual_content"', false)
            ->assertSee('name="custom_points_json"', false)
            ->assertSee('name="anti_border" value="1" checked', false)
            ->assertSee('id="wizardMusicPreview"', false)
            ->assertSee('name="voiceover_enabled"', false)
            ->assertSee('name="voiceover_tone"', false)
            ->assertSee('name="aspect_ratio"', false)
            ->assertSee('<option value="1:1">', false)
            ->assertSee('<option value="16:9">', false)
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
            ->assertSee('name="duration" value="60"', false)
            ->assertSee('مكتبة الخلفيات');
    }

    public function test_wizard_hides_the_environment_readiness_panel_in_both_languages(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['ar', 'en'] as $language) {
            $this->get(route('reels.wizard', ['lang' => $language]))
                ->assertOk()
                ->assertDontSee('id="readinessTitle"', false)
                ->assertDontSee('جاهزية الاستوديو')
                ->assertDontSee('Studio readiness');
        }
    }

    public function test_wizard_offers_the_numbered_list_design_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $language) {
            $expected = $language === 'ar'
                ? '<option value="numbered_list">قائمة مرقّمة</option>'
                : '<option value="numbered_list">Numbered cards</option>';

            $this->actingAs(User::factory()->create())
                ->get(route('reels.wizard', ['lang' => $language]))
                ->assertOk()
                ->assertSee($expected, false)
                ->assertSee("selectTemplate('numbered_list'", false);
        }
    }

    public function test_quality_and_export_steps_are_not_nested_inside_another_hidden_step(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('reels.wizard'));
        $response->assertOk();

        $document = new \DOMDocument;
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlSetting);
        $this->assertTrue($loaded);
        foreach (['stepContainer6', 'stepContainer7'] as $stepId) {
            $step = $document->getElementById($stepId);
            $this->assertNotNull($step, "Wizard step {$stepId} should be rendered.");

            for ($ancestor = $step->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
                $this->assertFalse(
                    str_starts_with((string) ($ancestor instanceof \DOMElement ? $ancestor->getAttribute('id') : ''), 'stepContainer'),
                    "Wizard step {$stepId} must not be hidden with a previous step.",
                );
            }
        }
    }

    public function test_wizard_hides_temporary_gemini_keys_and_their_explanation(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['ar', 'en'] as $language) {
            $this->get(route('reels.wizard', ['lang' => $language]))
                ->assertOk()
                ->assertDontSee('name="gemini_keys"', false)
                ->assertDontSee('مفاتيح Gemini لهذه العملية')
                ->assertDontSee('Gemini keys for this run')
                ->assertDontSee('temporary API keys')
                ->assertDontSee('مفاتيح API المؤقتة');
        }
    }

    public function test_wizard_defaults_to_source_background_motion_and_sound_effects_without_narration(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('reels.wizard', ['lang' => 'ar']))
            ->assertOk()
            ->assertDontSee('name="voiceover_enabled" value="1" checked', false)
            ->assertSee('<option value="warm" selected', false)
            ->assertSee('name="sound_effects_enabled" value="1" checked', false)
            ->assertSee('<option value="minimal" selected', false)
            ->assertSee('<option value="static" selected', false)
            ->assertSee('value="60" checked', false)
            ->assertDontSee('value="5" checked', false);
    }

    public function test_wizard_offers_an_elevenlabs_voice_picker_with_optional_tuning(): void
    {
        $this->actingAs(User::factory()->create());
        config([
            'services.elevenlabs.api_key' => null,
            'services.elevenlabs.voice_ids.en' => 'voice_en_default',
            'services.elevenlabs.voice_ids.ar' => null,
        ]);

        $this->get(route('reels.wizard', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('name="voice_id"', false)
            ->assertSee('value="voice_en_default"', false)
            ->assertSee('name="voice_speed"', false)
            ->assertSee('name="voice_stability"', false)
            ->assertSee('id="voicePreviewPlayer"', false);
    }

    public function test_scenario_rejects_an_invalid_voice_id(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['app_locale' => 'en']);
        config([
            'services.elevenlabs.api_key' => 'test-elevenlabs-key',
            'services.elevenlabs.voice_ids.en' => 'test-voice-id',
        ]);

        $this->from(route('reels.wizard', ['lang' => 'en']))
            ->post(route('reels.scenario'), [
                'idea' => 'A short test idea',
                'language' => 'en',
                'voiceover_enabled' => '1',
                'voice_id' => '../unsafe',
            ])
            ->assertRedirect(route('reels.wizard', ['lang' => 'en']))
            ->assertSessionHasErrors('voice_id');

        $this->assertDatabaseCount('reels', 0);
    }

    public function test_saving_a_voice_profile_preserves_other_user_settings(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['settings' => ['theme' => 'dark']])->save();
        $this->actingAs($user);

        $this->postJson(route('reels.profile.voice'), [
            'voice_id' => 'voice_saved',
            'voiceover_tone' => 'calm',
            'voice_stability' => 0.4,
            'voice_similarity' => 0.7,
            'voice_style' => 0.2,
            'voice_speed' => 1.1,
            'voice_boost' => 0,
        ])->assertOk()->assertJson(['status' => 'success']);

        $settings = $user->fresh()->settings;
        $this->assertSame('dark', $settings['theme']);
        $this->assertSame('voice_saved', $settings['voice_profile']['voice_id']);
        $this->assertSame('calm', $settings['voice_profile']['voiceover_tone']);
        $this->assertEquals(0.4, $settings['voice_profile']['stability']);
        $this->assertSame(1.1, $settings['voice_profile']['speed']);
        $this->assertFalse($settings['voice_profile']['speaker_boost']);

        config([
            'services.elevenlabs.api_key' => null,
            'services.elevenlabs.voice_ids.en' => 'voice_saved',
        ]);

        $this->get(route('reels.wizard', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('value="voice_saved"', false);
    }

    public function test_wizard_shows_mobile_step_progress_and_uses_a_font_pair_for_each_language(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('reels.wizard', ['lang' => 'ar']))
            ->assertOk()
            ->assertSee('id="wizardStepProgress"', false)
            ->assertSee('role="progressbar"', false)
            ->assertSee('الخطوة 1 من 7')
            ->assertSee("font-family: 'Cairo', 'Plus Jakarta Sans", false)
            ->assertSee('يتم تحديث معاينة الخط من محرك التصدير', false)
            ->assertSee('onchange="requestRealPreview()"', false)
            ->assertSee('النقطة الأولى');

        $this->get(route('reels.wizard', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('Step 1 of 7')
            ->assertSee("font-family: 'Plus Jakarta Sans', 'Cairo", false)
            ->assertSee('Script point 1');
    }

    public function test_studio_admin_helper_uses_the_existing_admin_flag_when_present(): void
    {
        $user = new User(['email' => 'operator@example.com']);
        $user->setAttribute('is_admin', true);

        $this->assertTrue($user->isStudioAdmin());
    }

    public function test_one_click_scenario_defaults_to_sixty_seconds_and_keeps_eight_scenes(): void
    {
        $points = [];
        for ($index = 1; $index <= 8; $index++) {
            $points[] = "خطوة {$index} تساعدك على بناء عادة واضحة وتحقيق نتيجة أفضل كل يوم.";
        }
        $this->fakeGemini([
            'title' => 'عادات مفيدة',
            'headline' => 'ابدأ بعادة صغيرة اليوم',
            'points' => $points,
            'caption' => 'ابدأ بخطوة عملية',
            'hashtags' => '#عادات',
        ]);
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');

        $this->post(route('reels.scenario'), [
            'idea' => 'بناء عادة مفيدة',
            'language' => 'ar',
            'videos' => [UploadedFile::fake()->createWithContent('source.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\0", 128))],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('reels', 1);
        Queue::assertPushed(GenerateReelVideo::class, function (GenerateReelVideo $job): bool {
            return $job->reel->duration === 60.0
                && count($job->options['custom_points']) === 8
                && count($job->inputPaths) === 1
                && $job->options['background_sequence'] === false;
        });
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
                : StudioPaths::backgrounds();

            return is_file($directory.'/'.$background['file']) && is_file($directory.'/'.$background['poster']);
        })->count();
    }

    /**
     * The picker hides catalog entries whose media is missing. Point the test
     * catalog at writable storage and create placeholders so the rendered page
     * is deterministic regardless of locally generated background assets.
     */
    private function materializeBackgroundCatalog(): void
    {
        $directory = StudioPaths::backgrounds();
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $catalog = collect(config('reel-backgrounds', []))
            ->map(fn (array $background): array => array_merge($background, ['asset_directory' => 'storage']))
            ->all();

        foreach ($catalog as $background) {
            foreach ([$background['file'], $background['poster']] as $file) {
                $path = $directory.'/'.$file;
                if (! is_file($path)) {
                    file_put_contents($path, '');
                }
            }
        }

        config(['reel-backgrounds' => $catalog]);
    }

    public function test_preview_rejects_an_unknown_template(): void
    {
        $this->postJson(route('reels.preview'), ['template' => 'unknown'])
            ->assertUnprocessable();
    }

    public function test_preview_passes_the_numbered_list_design_to_the_renderer(): void
    {
        $previewPath = null;
        $this->mock(RenderEngine::class, function ($mock) use (&$previewPath): void {
            $mock->shouldReceive('renderPreview')->once()->withArgs(function (array $options, string $outputPath) use (&$previewPath): bool {
                $previewPath = $outputPath;
                file_put_contents($outputPath, 'preview-image');

                return ($options['template'] ?? '') === 'numbered_list';
            });
        });

        try {
            $this->postJson(route('reels.preview'), [
                'headline' => 'Phrases for a billing call',
                'custom_points' => ['Ask for an itemized statement.'],
                'language' => 'en',
                'template' => 'numbered_list',
            ])->assertOk()
                ->assertJsonPath('status', 'success');
        } finally {
            if (is_string($previewPath)) {
                @unlink($previewPath);
            }
        }
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

    public function test_real_preview_receives_every_scene_and_the_approved_cta(): void
    {
        $previewPath = null;
        $this->mock(RenderEngine::class, function ($mock) use (&$previewPath): void {
            $mock->shouldReceive('renderPreview')->once()->withArgs(function (array $options, string $outputPath) use (&$previewPath): bool {
                $previewPath = $outputPath;
                file_put_contents($outputPath, 'preview-image');

                return ($options['custom_points'] ?? []) === ['Scene one', 'Scene two', 'Scene three', 'Scene four']
                    && ($options['cta'] ?? '') === 'Start today';
            });
        });

        try {
            $this->postJson(route('reels.preview'), [
                'headline' => 'A practical hook',
                'custom_points' => ['Scene one', 'Scene two', 'Scene three', 'Scene four'],
                'cta' => 'Start today',
                'language' => 'en',
                'template' => 'classic',
            ])->assertOk()
                ->assertJsonPath('status', 'success');
        } finally {
            if (is_string($previewPath)) {
                @unlink($previewPath);
            }
        }
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
        $this->app['auth']->logout();

        $this->get(route('reels.index'))->assertRedirect(route('login'));
        $this->get(route('settings.provider-keys'))->assertRedirect(route('login'));

        $member = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($member)->get(route('settings.provider-keys'))->assertForbidden();

        $admin = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $this->actingAs($admin)->get(route('settings.provider-keys'))->assertOk();
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

    public function test_queued_render_explains_worker_wait_and_shows_wait_metadata(): void
    {
        $this->actingAs(User::factory()->create());

        $reel = Reel::create([
            'title' => 'Queued render',
            'topic' => 'Queued render',
            'language' => 'en',
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
        ]);

        $this->get(route('reels.index', ['active' => $reel->id, 'lang' => 'en']))
            ->assertOk()
            ->assertSee('The render is queued and waiting for an available worker. It has not started yet.')
            ->assertSee('id="renderWaitElapsed"', false)
            ->assertSee('id="renderLastUpdated"', false)
            ->assertSee('id="queueDelayNotice" aria-hidden="true"', false);
    }

    public function test_queued_render_shows_worker_guidance_after_two_minutes(): void
    {
        $this->actingAs(User::factory()->create());

        $reel = Reel::create([
            'title' => 'Long-waiting render',
            'topic' => 'Long-waiting render',
            'language' => 'en',
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
        ]);
        $reel->forceFill(['created_at' => now()->subSeconds(120)])->save();

        $this->get(route('reels.index', ['active' => $reel->id, 'lang' => 'en']))
            ->assertOk()
            ->assertSee('id="queueDelayNotice" aria-hidden="false"', false)
            ->assertSee('Check the scheduled worker or another render ahead of this one.');
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

    public function test_cancel_route_uses_the_interface_language_for_a_reel_in_another_language(): void
    {
        $this->withSession(['app_locale' => 'en']);
        $reel = Reel::create([
            'title' => 'Cancelable render',
            'topic' => 'Cancelable render',
            'language' => 'ar',
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

    public function test_wizard_script_endpoint_generates_the_full_scene_list_with_the_selected_hook(): void
    {
        $scenes = [];
        for ($index = 1; $index <= 8; $index++) {
            $text = "المشهد {$index} يشرح خطوة عملية واضحة للتوفير اليومي.";
            $scenes[] = ['text' => $text, 'caption' => $text];
        }
        $this->fakeGemini([
            'headline' => 'ابدأ بخطة ادخار بسيطة',
            'hook' => 'ابدأ بخطة ادخار بسيطة',
            'scenes' => $scenes,
            'cta' => 'ابدأ بتطبيق الخطوة الأولى اليوم',
            'caption' => 'خطة عملية للتوفير اليومي.',
            'hashtags' => '#توفير #عادات #مال',
        ]);

        $this->postJson('/api/ai/generate-script', [
            'user_prompt' => 'فيديو عملي يساعد على التوفير',
            'topic' => 'التوفير اليومي',
            'selected_idea' => 'خطة ادخار تدريجية',
            'selected_idea_description' => 'خطوات صغيرة قابلة للتطبيق',
            'selected_idea_angle' => 'ابدأ بتغيير واحد أسبوعياً',
            'headline' => 'ابدأ بخطة ادخار بسيطة',
            'language' => 'ar',
            'duration' => 60,
            'niche' => 'money',
            'goal' => 'saves',
            'format_type' => 'list',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('script.headline', 'ابدأ بخطة ادخار بسيطة')
            ->assertJsonPath('script.cta', 'ابدأ بتطبيق الخطوة الأولى اليوم')
            ->assertJsonPath('script.points.7', 'المشهد 8 يشرح خطوة عملية واضحة للتوفير اليومي.')
            ->assertJsonPath('script.caption', 'خطة عملية للتوفير اليومي.');
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

    public function test_script_refinement_keeps_scenes_beyond_the_first_three(): void
    {
        $prompt = '';
        $this->mock(GeminiContentService::class, function ($mock) use (&$prompt): void {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(function (string $value) use (&$prompt): bool {
                    $prompt = $value;

                    return true;
                })
                ->andReturn([
                    'data' => ['lines' => ['Scene one.', 'Scene two.', 'Scene three.', 'Scene four.', 'Scene five.']],
                    'model' => 'test-model',
                ]);
        });
        $this->postJson(route('ai.script'), [
            'action' => 'punctuate',
            'language' => 'en',
            'lines' => ['Scene one', 'Scene two', 'Scene three', 'Scene four', 'Scene five'],
        ])->assertOk()
            ->assertJsonPath('lines.0', 'Scene one.')
            ->assertJsonPath('lines.4', 'Scene five.');
        $this->assertStringContainsString('Scene four', $prompt);
        $this->assertStringContainsString('Scene five', $prompt);
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

    public function test_quality_check_endpoint_returns_readiness_and_semantic_reviews(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldReceive('generate')->once()->andReturn([
                'data' => [
                    'summary' => 'The script is clear.',
                    'checks' => [
                        'hook' => ['score' => 20, 'feedback' => 'Clear opening.'],
                        'clarity' => ['score' => 20, 'feedback' => 'Easy to understand.'],
                        'flow' => ['score' => 20, 'feedback' => 'Logical order.'],
                        'ending' => ['score' => 20, 'feedback' => 'Useful closing.'],
                    ],
                    'strengths' => ['Practical steps.'],
                    'improvements' => [],
                ],
                'model' => 'gemini-test',
            ]);
        });

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
            ->assertJsonPath('report.checks.3.status', 'pass')
            ->assertJsonPath('report.semantic_review.score', 80);
    }

    public function test_quality_check_counts_every_scene_in_a_long_wizard_script(): void
    {
        $this->mock(ReelQualityReviewService::class, function ($mock): void {
            $mock->shouldReceive('review')->once()->andReturn([
                'score' => 80,
                'summary' => 'Clear script.',
                'checks' => [
                    'hook' => ['score' => 20, 'feedback' => 'Clear opening.'],
                    'clarity' => ['score' => 20, 'feedback' => 'Easy to understand.'],
                    'flow' => ['score' => 20, 'feedback' => 'Logical order.'],
                    'ending' => ['score' => 20, 'feedback' => 'Useful closing.'],
                ],
                'strengths' => [],
                'improvements' => [],
                'ai_model' => 'test-model',
            ]);
        });

        $this->postJson(route('reels.quality-check'), [
            'headline' => 'A practical savings hook',
            'topic' => 'Savings',
            'custom_points' => [
                'Track expenses',
                'Set weekly limits',
                'Review your spending',
                'Save automatically',
                'Adjust your goals',
            ],
            'manual_content' => true,
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'language' => 'en',
        ])->assertOk()
            ->assertJsonPath('report.word_count', 13)
            ->assertJsonPath('report.checks.1.value', 5);
    }

    public function test_quality_check_adds_semantic_content_review_including_the_timeline_ending(): void
    {
        $prompt = '';
        $this->mock(GeminiContentService::class, function ($mock) use (&$prompt): void {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(function (string $value) use (&$prompt): bool {
                    $prompt = $value;

                    return true;
                })
                ->andReturn([
                    'data' => [
                        'summary' => 'A clear and practical script.',
                        'checks' => [
                            'hook' => ['score' => 22, 'feedback' => 'The opening is specific.'],
                            'clarity' => ['score' => 21, 'feedback' => 'The idea is easy to follow.'],
                            'flow' => ['score' => 20, 'feedback' => 'The scenes build logically.'],
                            'ending' => ['score' => 21, 'feedback' => 'The closing is actionable.'],
                        ],
                        'strengths' => ['Concrete steps.'],
                        'improvements' => ['Make the transition shorter.'],
                    ],
                    'model' => 'gemini-test',
                ]);
        });

        $this->postJson(route('reels.quality-check'), [
            'headline' => 'A clear short hook',
            'topic' => 'Focus habits',
            'user_prompt' => 'A practical short video about focus habits.',
            'line1' => 'Turn off notifications.',
            'line2' => 'Work in short sessions.',
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'language' => 'en',
            'timeline_json' => json_encode([
                ['kind' => 'headline', 'text' => 'A clear short hook', 'start' => 0, 'end' => 2],
                ['kind' => 'point', 'text' => 'Turn off notifications.', 'start' => 2, 'end' => 5],
                ['kind' => 'cta', 'text' => 'Save this for later.', 'start' => 8, 'end' => 10],
            ], JSON_THROW_ON_ERROR),
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('report.semantic_review.score', 84)
            ->assertJsonPath('report.semantic_review.checks.hook.score', 22)
            ->assertJsonPath('report.semantic_review.checks.ending.feedback', 'The closing is actionable.')
            ->assertJsonPath('report.semantic_review.ai_model', 'gemini-test');

        $this->assertStringContainsString('Save this for later.', $prompt);
    }

    public function test_quality_check_reports_invalid_ai_review_instead_of_returning_a_success_shaped_report(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn(['data' => ['summary' => 'Incomplete review.'], 'model' => 'gemini-test']);
        });

        $this->postJson(route('reels.quality-check'), [
            'headline' => 'A useful opening',
            'line1' => 'Take one small step.',
            'duration' => 10,
            'language' => 'en',
        ])
            ->assertStatus(502)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'تعذّر التقييم بالذكاء الاصطناعي. تحقق من مفتاح Gemini وحاول مجددًا.');
    }

    public function test_wizard_describes_quality_check_as_semantic_ai_review_separate_from_fact_checking(): void
    {
        $this->get(route('reels.wizard'))
            ->assertOk()
            ->assertSee('تقييم جودة المحتوى بالذكاء الاصطناعي')
            ->assertSee('وضوح الفكرة وتسلسل المشاهد والخاتمة');
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
        $this->actingAs(User::factory()->create())->withSession(['app_locale' => 'ar']);
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
        config([
            'services.gemini.keys_json' => json_encode(['test-gemini-key']),
            'services.elevenlabs.api_key' => 'test-elevenlabs-key',
            'services.elevenlabs.voice_ids.en' => 'test-voice-id',
        ]);

        $response = $this->post(route('reels.scenario'), [
            'idea' => 'Commit to one project for the first 48 hours without distraction',
            'language' => 'en',
            'template' => 'bold_stripe',
            'duration' => 10,
            'goal' => 'saves',
            'voiceover_enabled' => '1',
            'voiceover_tone' => 'warm',
            'voice_id' => 'voice_en_custom',
            'voice_stability' => '0.4',
            'voice_similarity' => '0.7',
            'voice_style' => '0.3',
            'voice_speed' => '1.1',
            'voice_boost' => '0',
            'sound_effects_enabled' => '1',
            'videos' => [UploadedFile::fake()->createWithContent('source.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\0", 128))],
        ]);

        $reel = Reel::query()->first();
        $this->assertNotNull($reel);
        $response->assertRedirect(route('reels.index', ['active' => $reel->id, 'lang' => 'ar']))
            ->assertSessionHas('success', __('studio.flash.scenario_queued', [], 'ar'))
            ->assertSessionHas('scenario.headline', 'Give one project exactly 48 hours.');

        $this->assertSame('queued', $reel->status);
        $this->assertSame('en', $reel->language);
        $this->assertTrue($reel->render_options['manual_content']);
        $this->assertSame('Give one project exactly 48 hours.', $reel->render_options['custom_headline']);
        $this->assertCount(3, $reel->render_options['custom_points']);
        $this->assertSame('bold_stripe', $reel->template);
        $this->assertSame('static', $reel->camera_motion);
        $this->assertSame('cinematic_depth', $reel->render_options['motion_preset']);
        $this->assertSame('auto', $reel->render_options['scene_transition']);
        $this->assertSame('cinematic', $reel->render_options['color_grade']);
        $this->assertSame('slide_up', $reel->render_options['text_effect']);
        $this->assertTrue($reel->render_options['voiceover']);
        $this->assertSame('warm', $reel->render_options['voiceover_tone']);
        $this->assertSame('voice_en_custom', $reel->render_options['voice_id']);
        $this->assertSame([
            'stability' => 0.4,
            'similarity' => 0.7,
            'style' => 0.3,
            'speed' => 1.1,
            'speaker_boost' => false,
        ], $reel->render_options['voice_settings']);
        $this->assertTrue($reel->render_options['sound_effects']);
        $this->assertSame('#D6B87A', $reel->render_options['accent_color']);
        $this->assertSame('saves', $reel->goal);
        $this->assertSame('Commit to one project for the first 48 hours without distraction', $reel->user_prompt);
        $this->assertNotEmpty($reel->quality_report['checks']);
        Queue::assertPushed(GenerateReelVideo::class, 1);
    }

    public function test_scenario_rejects_voiceover_without_elevenlabs_credentials_before_generation(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');
        Storage::fake('public');
        $this->fakeGemini([
            'title' => 'Test',
            'headline' => 'A complete headline',
            'points' => ['One', 'Two', 'Three'],
            'caption' => 'Caption',
            'hashtags' => '#test',
        ]);
        config([
            'services.gemini.keys_json' => json_encode(['test-gemini-key']),
            'services.elevenlabs.api_key' => null,
            'services.elevenlabs.voice_ids.ar' => null,
        ]);

        $response = $this->from(route('reels.index', ['lang' => 'ar']))
            ->followingRedirects()
            ->post(route('reels.scenario'), [
                'idea' => 'اختبار تعليق صوتي',
                'language' => 'ar',
                'voiceover_enabled' => '1',
            ]);

        $response->assertOk()
            ->assertSee('أضف مفتاح ElevenLabs نشطاً وVoice ID للغة الفيديو المحددة قبل توليد سيناريو مع تعليق صوتي.');
        $this->assertDatabaseCount('reels', 0);
        Http::assertNothingSent();
    }

    public function test_scenario_form_defaults_to_transition_sound_effects_without_narration(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('reels.index', ['lang' => 'ar']))
            ->assertOk()
            ->assertDontSee('name="voiceover_enabled" value="1" checked', false)
            ->assertSee('name="voiceover_tone"', false)
            ->assertSee('<option value="warm" selected', false)
            ->assertSee('<option value="60" selected>60s</option>', false)
            ->assertSee('name="sound_effects_enabled" value="1" checked', false);
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
