<?php

namespace Tests\Feature;

use App\Models\Reel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReelWorkflowTest extends TestCase
{
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
            ->assertDontSee('Abdullah Al-Ibrahem');
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
}
