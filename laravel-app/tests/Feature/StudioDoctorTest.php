<?php

namespace Tests\Feature;

use Tests\TestCase;

class StudioDoctorTest extends TestCase
{
    public function test_missing_or_incomplete_ffmpeg_is_a_blocker(): void
    {
        config()->set('studio.engine.driver', 'ffmpeg');
        config()->set('studio.engine.ffmpeg_bin', '/missing/ffmpeg');
        config()->set('studio.engine.ffprobe_bin', '/missing/ffprobe');
        config()->set('studio.engine.arabic_font', '/missing/arabic-font.ttf');

        $this->artisan('studio:doctor')
            ->expectsOutputToContain('FFmpeg executable')
            ->expectsOutputToContain('FFprobe executable')
            ->assertFailed();
    }
}
