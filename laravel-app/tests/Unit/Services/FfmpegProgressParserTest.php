<?php

namespace Tests\Unit\Services;

use App\Services\FfmpegProgressParser;
use Tests\TestCase;

class FfmpegProgressParserTest extends TestCase
{
    public function test_parse_maps_elapsed_time_to_monotonic_render_progress(): void
    {
        $parser = new FfmpegProgressParser;

        $this->assertSame(
            ['stage' => 'encoding', 'progress' => 54],
            $parser->parse("frame=30\nout_time_ms=5000000\nprogress=continue\n", 10),
        );
        $this->assertSame(
            ['stage' => 'finalizing', 'progress' => 95],
            $parser->parse("out_time=00:00:10.000\nprogress=end\n", 10),
        );
    }
}
