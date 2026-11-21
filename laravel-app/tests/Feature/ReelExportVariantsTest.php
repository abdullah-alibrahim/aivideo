<?php

namespace Tests\Feature;

use App\Jobs\GenerateReelVideo;
use App\Models\Reel;
use App\Models\User;
use App\Services\GeminiContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReelExportVariantsTest extends TestCase
{
    use RefreshDatabase;

    private function completedReel(array $attributes = []): Reel
    {
        return Reel::create(array_merge([
            'title' => 'Export variant',
            'topic' => 'Export variant',
            'language' => 'en',
            'niche' => 'productivity',
            'status' => 'completed',
            'progress' => 100,
            'progress_stage' => 'completed',
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'template' => 'cinematic',
            'input_videos' => ['input_videos/source.mp4'],
            'render_options' => [
                'auto_music' => false,
                'custom_headline' => 'A calmer start',
                'custom_points' => ['Drink water', 'Get sunlight'],
                '_last_timeline' => [
                    ['kind' => 'headline', 'text' => 'A calmer start', 'start' => 0.0, 'end' => 2.0],
                    ['kind' => 'point', 'text' => 'Drink water', 'start' => 2.0, 'end' => 6.0],
                    ['kind' => 'point', 'text' => 'Get sunlight', 'start' => 6.0, 'end' => 8.0],
                    ['kind' => 'cta', 'text' => 'Save this', 'start' => 8.0, 'end' => 10.0],
                ],
            ],
        ], $attributes));
    }

    public function test_it_queues_a_new_aspect_ratio_variant_reusing_the_same_timeline(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('input_videos/source.mp4', 'fake-video');

        $reel = $this->completedReel();

        $this->actingAs(User::factory()->create())
            ->post(route('reels.export-aspect', $reel), ['aspect_ratio' => '1:1'])
            ->assertRedirect();

        $variant = Reel::query()->whereKeyNot($reel->id)->firstOrFail();

        $this->assertSame('1:1', $variant->aspect_ratio);
        $this->assertSame('queued', $variant->status);
        $this->assertSame('A calmer start', $variant->render_options['custom_headline']);
        $this->assertSame('Get sunlight', $variant->render_options['custom_points'][1]);
        $this->assertTrue($variant->render_options['manual_content']);
        $this->assertSame('headline', $variant->render_options['timeline'][0]['kind']);

        Queue::assertPushed(GenerateReelVideo::class);
    }

    public function test_it_rejects_an_unknown_aspect_ratio(): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->create())
            ->post(route('reels.export-aspect', $this->completedReel()), ['aspect_ratio' => '4:3'])
            ->assertSessionHasErrors('aspect_ratio');
    }

    public function test_all_formats_queues_every_missing_aspect_ratio_in_one_request(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('input_videos/source.mp4', 'fake-video');

        $reel = $this->completedReel();

        $this->actingAs(User::factory()->create())
            ->post(route('reels.export-aspect', $reel), ['aspect_ratio' => 'all'])
            ->assertRedirect();

        $ratios = Reel::query()->whereKeyNot($reel->id)->pluck('aspect_ratio')->sort()->values()->all();

        $this->assertSame(['16:9', '1:1'], $ratios);
        Queue::assertPushed(GenerateReelVideo::class, 2);
    }

    public function test_it_regenerates_only_the_last_scene(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn([
                    'data' => ['point' => 'Finish with one small win today.'],
                    'model' => 'test-model',
                ]);
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('ai.regenerate-part'), [
                'part' => 'last_scene',
                'language' => 'en',
                'headline' => 'A calmer start',
                'points' => ['Drink water', 'Get sunlight', 'Review your day'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('points.0', 'Drink water')
            ->assertJsonPath('points.1', 'Get sunlight')
            ->assertJsonPath('points.2', 'Finish with one small win today.');
    }

    public function test_regenerate_part_requires_a_valid_part(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('ai.regenerate-part'), ['part' => 'everything'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('part');
    }
}
