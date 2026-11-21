<?php

namespace Tests\Feature;

use App\Jobs\GenerateReelVideo;
use App\Models\Reel;
use App\Models\User;
use App\Services\GeminiContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ScreenshotCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.keys_json' => json_encode(['test-gemini-key'])]);
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    }

    public function test_it_analyses_a_reference_frame_and_queues_a_matching_reel(): void
    {
        $this->fakeVision();

        $response = $this->withSession(['app_locale' => 'en'])->post(route('reels.clone'), [
            'reference_image' => UploadedFile::fake()->image('reference.jpg', 720, 1280),
            'idea' => 'Make it about saving money',
            'language' => 'en',
            'duration' => 30,
            'handle' => '@demo',
            'use_reference_background' => '1',
            'sound_effects_enabled' => '1',
        ]);

        $reel = Reel::firstOrFail();
        $response->assertRedirect(route('reels.index', ['active' => $reel->id, 'lang' => 'en']));

        $this->assertSame('bold_stripe', $reel->template);
        $this->assertSame('drift', $reel->camera_motion);
        $this->assertSame('en', $reel->language);
        $this->assertSame(30.0, (float) $reel->duration);
        $this->assertCount(1, $reel->input_videos);
        $this->assertStringStartsWith('input_videos/', $reel->input_videos[0]);
        Storage::disk('local')->assertExists($reel->input_videos[0]);

        $options = $reel->render_options;
        $this->assertTrue($options['manual_content']);
        $this->assertSame('Stop leaking cash', $options['custom_headline']);
        $this->assertSame(['Track every purchase', 'Cut one subscription', 'Move the savings'], $options['custom_points']);
        $this->assertSame('dynamic', $options['motion_preset']);
        $this->assertSame('warm', $options['color_grade']);
        $this->assertSame('hormozi', $options['subtitle_preset']);
        $this->assertTrue($options['overlay_grain']);
        $this->assertSame('#12AB34', $options['accent_color']);

        Queue::assertPushed(GenerateReelVideo::class, function (GenerateReelVideo $job) use ($reel): bool {
            return $job->reel->is($reel)
                && count($job->inputPaths) === 1
                && str_ends_with(strtolower($job->inputPaths[0]), '.jpg');
        });
    }

    public function test_the_studio_page_offers_the_reference_clone_form(): void
    {
        $this->get(route('reels.index', ['lang' => 'en']))
            ->assertOk()
            ->assertSee('id="cloneForm"', false)
            ->assertSee('name="reference_image"', false)
            ->assertSee('name="use_reference_background"', false)
            ->assertSee('name="color_grade"', false);
    }

    public function test_it_rejects_a_reference_that_is_not_an_image(): void
    {
        $response = $this->from(route('reels.index'))
            ->post(route('reels.clone'), [
                'reference_image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
                'language' => 'en',
            ]);

        $response->assertRedirect(route('reels.index'))
            ->assertSessionHasErrors('reference_image');
        $this->assertDatabaseCount('reels', 0);
    }

    public function test_a_failed_analysis_cleans_the_uploaded_screenshot(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldReceive('generateFromImage')->once()->andThrow(new RuntimeException('Vision quota exhausted.'));
        });

        $response = $this->from(route('reels.index'))
            ->post(route('reels.clone'), [
                'reference_image' => UploadedFile::fake()->image('reference.png', 720, 1280),
                'language' => 'en',
                'use_reference_background' => '1',
            ]);

        $response->assertRedirect(route('reels.index'))
            ->assertSessionHasErrors('reference_image');
        $this->assertDatabaseCount('reels', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function fakeVision(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldReceive('generateFromImage')
                ->once()
                ->andReturn([
                    'data' => [
                        'title' => 'Cash habits',
                        'topic' => 'Saving money',
                        'headline' => 'Stop leaking cash',
                        'points' => ['Track every purchase', 'Cut one subscription', 'Move the savings'],
                        'cta' => 'Start today',
                        'caption' => 'A practical money habit.',
                        'hashtags' => ['#money', '#saving'],
                        'design' => [
                            'template' => 'bold_stripe',
                            'subtitle_preset' => 'hormozi',
                            'accent_color' => '#12ab34',
                            'background_dimming' => 40,
                            'font_scale' => 110,
                            'text_effect' => 'fade_in',
                            'camera_motion' => 'drift',
                            'motion_preset' => 'dynamic',
                            'scene_transition' => 'whip_pan',
                            'color_grade' => 'warm',
                            'overlay_grain' => true,
                            'overlay_light_leak' => false,
                            'emoji_auto' => true,
                            'hook_word_by_word' => true,
                            'highlight_important' => true,
                            'music_mood' => 'energetic',
                            'sfx_style' => 'pop',
                            'background_id' => '',
                            'design_notes' => 'Bold type on a saturated stripe.',
                        ],
                    ],
                    'model' => 'gemini-vision-test',
                ]);
        });
    }
}
