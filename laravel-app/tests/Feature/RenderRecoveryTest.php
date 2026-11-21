<?php

namespace Tests\Feature;

use App\Jobs\GenerateReelVideo;
use App\Models\Reel;
use App\Models\User;
use App\Services\RenderEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RenderRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeReel(array $attributes = []): Reel
    {
        return Reel::create(array_merge([
            'title' => 'Recovery test',
            'topic' => 'Recovery test',
            'language' => 'en',
            'status' => 'processing',
            'progress' => 15,
            'progress_stage' => 'encoding',
            'duration' => 10,
        ], $attributes));
    }

    private function ageReel(Reel $reel, string $column, int $minutes): void
    {
        DB::table('reels')->where('id', $reel->id)->update([
            $column => now()->subMinutes($minutes),
        ]);
    }

    private function queueJobFor(Reel $reel, string $token): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['data' => ['command' => 'token:'.$token]]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
    }

    public function test_status_endpoint_exposes_the_real_worker_heartbeat(): void
    {
        $reel = $this->makeReel();
        $this->ageReel($reel, 'updated_at', 15);

        $this->actingAs(User::factory()->create())
            ->getJson(route('reels.status', $reel))
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('stalled', true)
            ->assertJsonPath('updated_at', $reel->fresh()->updated_at?->toIso8601String());
    }

    public function test_status_endpoint_does_not_flag_a_fresh_render_as_stalled(): void
    {
        $reel = $this->makeReel(['status' => 'queued', 'progress' => 0, 'progress_stage' => 'queued']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('reels.status', $reel))
            ->assertOk()
            ->assertJsonPath('stalled', false)
            ->assertJsonPath('updated_seconds_ago', fn ($seconds): bool => is_int($seconds) && $seconds <= 5);
    }

    public function test_watchdog_fails_a_processing_reel_without_a_heartbeat(): void
    {
        Storage::fake('public');
        $reel = $this->makeReel(['output_video_path' => 'reels/partial.mp4']);
        Storage::disk('public')->put('reels/partial.mp4', 'partial');
        $this->ageReel($reel, 'updated_at', 15);

        $this->artisan('reels:recover-stale')->assertExitCode(0);

        $reel->refresh();
        $this->assertSame('failed', $reel->status);
        $this->assertSame('timeout', $reel->progress_stage);
        Storage::disk('public')->assertMissing('reels/partial.mp4');
    }

    public function test_watchdog_keeps_a_processing_reel_that_is_still_sending_heartbeats(): void
    {
        $reel = $this->makeReel();

        $this->artisan('reels:recover-stale')->assertExitCode(0);

        $this->assertSame('processing', $reel->fresh()->status);
    }

    public function test_watchdog_fails_an_orphaned_queued_reel(): void
    {
        $reel = $this->makeReel([
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'render_options' => ['_dispatch_token' => '11111111-1111-1111-1111-111111111111'],
        ]);
        $this->ageReel($reel, 'created_at', 30);

        $this->artisan('reels:recover-stale')->assertExitCode(0);

        $reel->refresh();
        $this->assertSame('failed', $reel->status);
        $this->assertSame('queue_failed', $reel->progress_stage);
    }

    public function test_watchdog_keeps_a_queued_reel_that_still_has_a_pending_job(): void
    {
        $token = '22222222-2222-2222-2222-222222222222';
        $reel = $this->makeReel([
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'render_options' => ['_dispatch_token' => $token],
        ]);
        $this->ageReel($reel, 'created_at', 30);
        $this->queueJobFor($reel, $token);

        $this->artisan('reels:recover-stale')->assertExitCode(0);

        $this->assertSame('queued', $reel->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_stale_job_from_a_previous_attempt_does_not_render_again(): void
    {
        $reel = $this->makeReel([
            'status' => 'queued',
            'progress' => 0,
            'progress_stage' => 'queued',
            'render_options' => ['_dispatch_token' => 'current-token'],
        ]);

        $renderer = \Mockery::mock(RenderEngine::class);
        $renderer->shouldNotReceive('render');

        $job = new GenerateReelVideo(
            $reel,
            [public_path('backgrounds/finance_grid.jpg')],
            null,
            [],
            'reels/reel_'.$reel->id.'_stale.mp4',
            'old-token',
        );

        $job->handle($renderer);

        $this->assertSame('queued', $reel->fresh()->status);
    }
}
