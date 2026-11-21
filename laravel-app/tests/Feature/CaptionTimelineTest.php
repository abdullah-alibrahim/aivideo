<?php

namespace Tests\Feature;

use App\Services\CaptionTimeline;
use Tests\TestCase;

class CaptionTimelineTest extends TestCase
{
    public function test_it_aligns_captions_with_the_spoken_narration(): void
    {
        $timeline = new CaptionTimeline;
        $plan = $timeline->allocateScenes(
            ['Start now'],
            ['Plan one step', 'Track progress'],
            ['Save this'],
            10.0,
            'en',
        );

        $aligned = $timeline->alignToWords($plan, [
            ['text' => 'Start', 'start' => 0.2, 'end' => 0.5],
            ['text' => 'now', 'start' => 0.55, 'end' => 0.9],
            ['text' => 'Plan', 'start' => 1.6, 'end' => 1.9],
            ['text' => 'one', 'start' => 1.95, 'end' => 2.1],
            ['text' => 'step.', 'start' => 2.15, 'end' => 2.6],
            ['text' => 'Track', 'start' => 3.0, 'end' => 3.3],
            ['text' => 'progress', 'start' => 3.35, 'end' => 3.9],
            ['text' => 'Save', 'start' => 5.0, 'end' => 5.3],
            ['text' => 'this', 'start' => 5.35, 'end' => 5.7],
        ], 10.0);

        $this->assertSame(0.2, $aligned['hook']['start']);
        $this->assertSame(0.9, $aligned['hook']['end']);
        $this->assertSame(1.6, $aligned['body'][0]['start']);
        $this->assertSame(2.6, $aligned['body'][0]['end']);
        $this->assertSame(3.0, $aligned['body'][1]['start']);
        $this->assertSame(3.9, $aligned['body'][1]['end']);
        $this->assertSame(5.0, $aligned['cta']['start']);
        $this->assertSame(10.0, $aligned['cta']['end']);
    }

    public function test_unmatched_captions_keep_their_estimated_window_without_overlapping(): void
    {
        $timeline = new CaptionTimeline;
        $plan = $timeline->allocateScenes(
            ['Hook line'],
            ['Completely different wording'],
            [],
            10.0,
            'en',
        );

        $aligned = $timeline->alignToWords($plan, [
            ['text' => 'Only', 'start' => 0.0, 'end' => 0.4],
            ['text' => 'unrelated', 'start' => 0.45, 'end' => 0.9],
        ], 10.0);

        $this->assertSame($plan['body'][0]['start'], $aligned['body'][0]['start']);
        $this->assertGreaterThanOrEqual($aligned['hook']['end'], $aligned['body'][0]['start']);
        $this->assertGreaterThan($aligned['body'][0]['start'], $aligned['body'][0]['end']);
    }

    public function test_spoken_timings_longer_than_the_reel_are_compressed_to_fit(): void
    {
        $timeline = new CaptionTimeline;
        $plan = $timeline->allocateScenes(['Hook'], ['Point'], ['Close'], 10.0, 'en');

        $aligned = $timeline->alignToWords($plan, [
            ['text' => 'Hook', 'start' => 0.0, 'end' => 5.0],
            ['text' => 'Point', 'start' => 5.0, 'end' => 10.0],
            ['text' => 'Close', 'start' => 10.0, 'end' => 15.0],
        ], 10.0);

        $this->assertSame(10.0, $aligned['cta']['end']);
        $this->assertGreaterThan($aligned['body'][0]['start'], $aligned['body'][0]['end']);
        $this->assertGreaterThanOrEqual($aligned['body'][0]['end'], $aligned['cta']['start']);
    }
}
