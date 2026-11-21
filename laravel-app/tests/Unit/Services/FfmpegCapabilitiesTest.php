<?php

namespace Tests\Unit\Services;

use App\Services\FfmpegCapabilities;
use Tests\TestCase;

class FfmpegCapabilitiesTest extends TestCase
{
    public function test_h264_readiness_requires_the_encoder_used_by_the_renderer(): void
    {
        $ffmpeg = tempnam(sys_get_temp_dir(), 'fake-ffmpeg-');
        $ffprobe = tempnam(sys_get_temp_dir(), 'fake-ffprobe-');
        $font = tempnam(sys_get_temp_dir(), 'fake-font-');
        if ($ffmpeg === false || $ffprobe === false || $font === false) {
            $this->fail('Unable to allocate readiness fixtures.');
        }

        file_put_contents($ffmpeg, <<<'SH'
#!/bin/sh
case "$*" in
  *-encoders*) printf ' V..... h264_nvenc hardware encoder\n A..... aac audio encoder\n' ;;
  *-filters*) printf ' TSC drawtext V->V\n TSC subtitles V->V\n' ;;
  *filter=drawtext*) printf 'text_shaping support\n' ;;
  *) printf 'ffmpeg version test\n' ;;
esac
SH);
        file_put_contents($ffprobe, "#!/bin/sh\nprintf 'ffprobe version test\\n'\n");
        file_put_contents($font, 'font');
        chmod($ffmpeg, 0700);
        chmod($ffprobe, 0700);
        config()->set('studio.engine.ffmpeg_bin', $ffmpeg);
        config()->set('studio.engine.ffprobe_bin', $ffprobe);
        config()->set('studio.engine.arabic_font', $font);

        try {
            $status = app(FfmpegCapabilities::class)->inspect();
            $this->assertFalse($status['h264']);
        } finally {
            @unlink($ffmpeg);
            @unlink($ffprobe);
            @unlink($font);
        }
    }
}
