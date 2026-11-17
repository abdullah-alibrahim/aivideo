<?php

namespace Tests\Feature;

use App\Services\CaptionTimeline;
use Tests\TestCase;

class CaptionTimelineTest extends TestCase
{
    private CaptionTimeline $timeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeline = new CaptionTimeline;
    }

    public function test_it_allocates_hook_body_and_cta_segments(): void
    {
        $plan = $this->timeline->allocateScenes(
            ['Stop scrolling'],
            ['First point', 'Second point', 'Third point'],
            ['Follow for more'],
            60.0,
            'ar',
        );

        $this->assertSame('Stop scrolling', $plan['hook']['text']);
        $this->assertSame(0.0, $plan['hook']['start']);
        $this->assertSame('Follow for more', $plan['cta']['text']);
        $this->assertSame(60.0, $plan['cta']['end']);
        $this->assertCount(3, $plan['body']);

        $bodyStart = $plan['body'][0]['start'];
        $this->assertGreaterThan(0.0, $bodyStart);
        $this->assertGreaterThan(
            $plan['body'][0]['end'],
            $plan['body'][2]['end'],
            'Body scenes must advance in time order.',
        );
        $this->assertLessThanOrEqual($plan['cta']['start'], $plan['body'][2]['end']);
    }

    public function test_it_gives_longer_captions_more_screen_time(): void
    {
        $plan = $this->timeline->allocateScenes(
            [],
            ['Short', 'This is a much longer caption that needs more reading time'],
            [],
            30.0,
            'ar',
        );

        $short = $plan['body'][0]['end'] - $plan['body'][0]['start'];
        $long = $plan['body'][1]['end'] - $plan['body'][1]['start'];

        $this->assertGreaterThan($short, $long);
    }

    public function test_it_keeps_a_minimum_reading_window_per_scene(): void
    {
        $plan = $this->timeline->allocateScenes(
            [],
            ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight'],
            [],
            10.0,
            'ar',
        );

        foreach ($plan['body'] as $segment) {
            $this->assertGreaterThanOrEqual(
                CaptionTimeline::MINIMUM_READING_SECONDS,
                $segment['end'] - $segment['start'],
            );
        }
    }

    public function test_it_handles_empty_input_without_failing(): void
    {
        $plan = $this->timeline->allocateScenes([], [], [], 10.0, 'ar');

        $this->assertNull($plan['hook']);
        $this->assertNull($plan['cta']);
        $this->assertSame([], $plan['body']);
    }

    public function test_it_stretches_segments_when_narration_runs_long(): void
    {
        $plan = $this->timeline->allocateScenes(
            ['Hook'],
            ['Point one', 'Point two'],
            ['CTA'],
            10.0,
            'ar',
        );

        $fitted = $this->timeline->fitToNarration($plan, 10.0, 14.0);

        $this->assertSame(14.0, $fitted['duration']);
        $this->assertSame(14.0, $fitted['cta']['end']);
        $this->assertGreaterThan(
            $plan['body'][0]['end'] - $plan['body'][0]['start'],
            $fitted['body'][0]['end'] - $fitted['body'][0]['start'],
        );
        $this->assertSame(14.0, $fitted['cta']['end']);
    }

    public function test_it_compresses_segments_when_narration_runs_short(): void
    {
        $plan = $this->timeline->allocateScenes(
            ['Hook'],
            ['Point one', 'Point two'],
            ['CTA'],
            10.0,
            'ar',
        );

        $fitted = $this->timeline->fitToNarration($plan, 10.0, 6.0);

        $this->assertSame(10.0, $fitted['duration']);
        $this->assertSame(10.0, $fitted['cta']['end']);
        $this->assertSame(10.0, $fitted['cta']['end']);
    }

    public function test_it_flattens_caption_segments_in_playback_order(): void
    {
        $plan = $this->timeline->allocateScenes(
            ['Hook'],
            ['Point one', 'Point two'],
            ['CTA'],
            10.0,
            'en',
        );

        $segments = $this->timeline->segments($plan);

        $this->assertSame(['Hook', 'Point one', 'Point two', 'CTA'], array_column($segments, 'text'));
        $this->assertSame(['headline', 'point', 'point', 'cta'], array_column($segments, 'kind'));
        $this->assertSame(10.0, $segments[3]['end']);
    }

    public function test_it_never_overlaps_fitted_segments(): void
    {
        $plan = $this->timeline->allocateScenes(
            ['Hook'],
            ['A', 'B', 'C', 'D'],
            ['CTA'],
            20.0,
            'en',
        );

        foreach ([0.5, 1.0, 1.5, 2.0] as $ratio) {
            $fitted = $this->timeline->fitToNarration($plan, 20.0, 20.0 * $ratio);
            $all = array_values(array_filter(
                [$fitted['hook'], ...$fitted['body'], $fitted['cta']],
                static fn (?array $segment): bool => $segment !== null,
            ));

            for ($index = 1; $index < count($all); $index++) {
                $this->assertGreaterThanOrEqual(
                    $all[$index - 1]['end'],
                    $all[$index]['start'],
                    'Fitted segments must not overlap at ratio '.$ratio,
                );
            }
            $this->assertLessThanOrEqual($fitted['duration'], $all[count($all) - 1]['end']);
        }
    }
}
