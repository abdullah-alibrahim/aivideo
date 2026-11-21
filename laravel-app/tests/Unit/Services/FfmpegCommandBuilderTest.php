<?php

namespace Tests\Unit\Services;

use App\Models\Reel;
use App\Services\FfmpegCommandBuilder;
use Tests\TestCase;

class FfmpegCommandBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'studio.engine.ffmpeg_bin' => 'ffmpeg',
            'studio.engine.ffprobe_bin' => 'ffprobe',
        ]);
    }

    public function test_build_preserves_supported_output_sizes_and_quality(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel(['language' => 'en', 'duration' => 8]);

        foreach ([
            ['9:16', 720, '406:720'],
            ['9:16', 1080, '608:1080'],
            ['1:1', 720, '720:720'],
            ['1:1', 1080, '1080:1080'],
            ['16:9', 720, '1280:720'],
            ['16:9', 1080, '1920:1080'],
        ] as [$ratio, $quality, $size]) {
            $command = $builder->build(
                $reel,
                ['/tmp/source clip.mp4'],
                null,
                ['aspect_ratio' => $ratio, 'quality' => $quality, 'captions' => false],
                '/tmp/output clip.mp4',
                null,
                null,
            );

            $this->assertSame('ffmpeg', $command[0]);
            $this->assertStringContainsString('scale='.$size.':force_original_aspect_ratio=increase', $command[array_search('-filter_complex', $command, true) + 1]);
            $this->assertContains('-c:v', $command);
            $this->assertContains('libx264', $command);
            $this->assertContains('aac', $command);
            $this->assertContains('/tmp/source clip.mp4', $command);
            $this->assertContains('/tmp/output clip.mp4', $command);
        }
    }

    public function test_build_uses_text_files_and_escaped_filter_paths_for_arabic_and_untrusted_values(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel([
            'language' => 'ar',
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'camera_motion' => 'parallax',
            'template' => 'cinematic',
        ]);
        $headlinePath = "/tmp/captions/عنوان 'head':1.txt";
        $linePath = '/tmp/captions/line, [1].txt';
        $hostileInputPath = "/tmp/source ' ; \$(touch nope).mp4";

        $command = $builder->build(
            $reel,
            [$hostileInputPath, '/tmp/scene two.mp4'],
            '/tmp/music track.mp3',
            [
                'quality' => 1080,
                'captions' => true,
                'scene_transition' => 'fade',
                'text_motion' => 'slide_left',
                'motion_preset' => 'cinematic_depth',
                'hook_word_by_word' => true,
                'highlight_important' => true,
                '_text_files' => [
                    'headline' => $headlinePath,
                    'points' => [$linePath],
                    'cta' => '/tmp/captions/cta.txt',
                    'handle' => '/tmp/captions/handle.txt',
                ],
            ],
            '/tmp/output ; $(touch bad).mp4',
            '/tmp/voice track.mp3',
            '/tmp/captions/subtitles [ar].srt',
        );

        $filterGraph = $command[array_search('-filter_complex', $command, true) + 1];
        $this->assertStringNotContainsString('text_shaping=1', $filterGraph);
        $this->assertStringContainsString('fontsdir=', $filterGraph);
        $this->assertStringContainsString('subtitles=', $filterGraph);
        $this->assertStringContainsString('fade', $filterGraph);
        $this->assertStringNotContainsString('touch nope', $filterGraph);
        $this->assertContains($hostileInputPath, $command);
        $this->assertContains('/tmp/music track.mp3', $command);
        $this->assertContains('/tmp/voice track.mp3', $command);
        $this->assertContains('/tmp/output ; $(touch bad).mp4', $command);
    }

    public function test_build_supports_templates_motion_transitions_audio_and_subtitles(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel([
            'language' => 'en',
            'duration' => 12,
            'aspect_ratio' => '16:9',
            'camera_motion' => 'zoom_in',
            'template' => 'bold_stripe',
        ]);
        $command = $builder->build(
            $reel,
            ['/tmp/scene-1.mp4', '/tmp/scene-2.mp4'],
            '/tmp/music.mp3',
            [
                'quality' => 720,
                'captions' => true,
                'scene_transition' => 'wipe',
                'text_motion' => 'slide_right',
                'motion_preset' => 'news_wipe',
                '_text_files' => ['headline' => '/tmp/text/head.txt', 'points' => ['/tmp/text/point.txt']],
            ],
            '/tmp/output.mp4',
            '/tmp/voice.mp3',
            '/tmp/output.srt',
        );

        $this->assertCount(4, array_filter($command, static fn (mixed $arg): bool => $arg === '-i'));
        $this->assertStringContainsString('wipe', $command[array_search('-filter_complex', $command, true) + 1]);
        $this->assertContains('/tmp/music.mp3', $command);
        $this->assertContains('/tmp/voice.mp3', $command);
        $this->assertStringContainsString('/tmp/output.ass', $command[array_search('-filter_complex', $command, true) + 1]);
    }

    public function test_build_supports_all_template_motion_and_transition_choices(): void
    {
        $builder = new FfmpegCommandBuilder;
        $textFiles = ['headline' => '/tmp/text/head.txt', 'points' => ['/tmp/text/point.txt']];

        foreach (['classic', 'clean_news', 'cinematic', 'quote_card', 'bold_stripe'] as $template) {
            $reel = new Reel(['language' => 'en', 'duration' => 6, 'template' => $template]);
            $command = $builder->build($reel, ['/tmp/scene.mp4'], null, ['_text_files' => $textFiles], '/tmp/out.mp4', null, null);
            $this->assertStringContainsString('drawbox=', $command[array_search('-filter_complex', $command, true) + 1]);
        }

        foreach (['static', 'zoom_in', 'pan', 'parallax'] as $motion) {
            $reel = new Reel(['language' => 'en', 'duration' => 6, 'camera_motion' => $motion]);
            $command = $builder->build($reel, ['/tmp/scene.mp4'], null, ['_text_files' => $textFiles], '/tmp/out.mp4', null, null);
            $this->assertStringContainsString('crop=', $command[array_search('-filter_complex', $command, true) + 1]);
        }

        foreach (['fade' => 'fade', 'wipe' => 'wipeleft', 'slide' => 'slideleft', 'varied' => 'smoothleft'] as $choice => $expected) {
            $reel = new Reel(['language' => 'en', 'duration' => 6]);
            $command = $builder->build($reel, ['/tmp/one.mp4', '/tmp/two.mp4'], null, [
                'scene_transition' => $choice,
                '_text_files' => $textFiles,
            ], '/tmp/out.mp4', null, null);
            $this->assertStringContainsString('xfade=transition='.$expected, $command[array_search('-filter_complex', $command, true) + 1]);
        }
    }

    public function test_build_generates_local_ambient_music_and_ducks_it_under_voiceover(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel(['language' => 'en', 'duration' => 8]);
        $command = $builder->build(
            $reel,
            ['/tmp/scene.mp4'],
            null,
            ['auto_music' => true, '_text_files' => ['headline' => '/tmp/head.txt', 'points' => []]],
            '/tmp/output.mp4',
            '/tmp/voice.mp3',
            null,
        );
        $filterGraph = $command[array_search('-filter_complex', $command, true) + 1];

        $this->assertStringContainsString('aevalsrc=0.055*sin(2*PI*220*t)+', implode(' ', $command));
        $this->assertStringContainsString('volume=0.12,afade=t=in', $filterGraph);
        $this->assertStringContainsString('amix=inputs=2', $filterGraph);
    }

    public function test_build_mixes_source_audio_with_music_and_voiceover(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel(['language' => 'en', 'duration' => 8]);
        $command = $builder->build(
            $reel,
            ['/tmp/source.mp4'],
            '/tmp/music.mp3',
            [
                'auto_music' => false,
                '_source_audio_indices' => [0],
                '_text_files' => ['headline' => '/tmp/head.txt', 'points' => []],
            ],
            '/tmp/output.mp4',
            '/tmp/voice.mp3',
            null,
        );
        $filterGraph = $command[array_search('-filter_complex', $command, true) + 1];

        $this->assertStringContainsString('[0:a:0]atrim=', $filterGraph);
        $this->assertStringContainsString('adelay=', $filterGraph);
        $this->assertStringContainsString('amix=inputs=3', $filterGraph);
    }

    public function test_build_cycles_distinct_camera_moves_across_scenes_for_the_dynamic_preset(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel(['language' => 'en', 'duration' => 16]);
        $command = $builder->build(
            $reel,
            ['/tmp/one.mp4', '/tmp/two.mp4', '/tmp/three.mp4', '/tmp/four.mp4'],
            null,
            ['motion_preset' => 'dynamic', 'auto_music' => false, '_text_files' => ['headline' => '/tmp/head.txt', 'points' => []]],
            '/tmp/out.mp4',
            null,
            null,
        );
        $filterGraph = $command[array_search('-filter_complex', $command, true) + 1];

        $this->assertStringContainsString(":x='(in_w-out_w)*t/", $filterGraph);
        $this->assertStringContainsString('sin(2*PI*t/', $filterGraph);
        $this->assertStringContainsString('(1-t/', $filterGraph);
    }

    public function test_build_applies_a_colour_grade_before_the_text_overlay(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel(['language' => 'en', 'duration' => 8]);
        $command = $builder->build(
            $reel,
            ['/tmp/scene.mp4'],
            null,
            ['color_grade' => 'cinematic', 'auto_music' => false, '_text_files' => ['headline' => '/tmp/head.txt', 'points' => []]],
            '/tmp/out.mp4',
            null,
            null,
        );
        $filterGraph = $command[array_search('-filter_complex', $command, true) + 1];

        $this->assertStringContainsString('eq=contrast=1.06:saturation=1.08:gamma=1.02', $filterGraph);
        $this->assertStringContainsString('vignette=PI/7', $filterGraph);
    }

    public function test_build_tightens_glitch_cuts_and_renders_a_true_reveal_zoom(): void
    {
        $builder = new FfmpegCommandBuilder;
        $reel = new Reel(['language' => 'en', 'duration' => 10, 'camera_motion' => 'zoom_out']);
        $command = $builder->build(
            $reel,
            ['/tmp/one.mp4', '/tmp/two.mp4'],
            null,
            ['scene_transition' => 'glitch', 'auto_music' => false, '_text_files' => ['headline' => '/tmp/head.txt', 'points' => []]],
            '/tmp/out.mp4',
            null,
            null,
        );
        $filterGraph = $command[array_search('-filter_complex', $command, true) + 1];

        $this->assertStringContainsString('xfade=transition=hblur:duration=0.2', $filterGraph);
        $this->assertStringContainsString('zoompan=z=', $filterGraph);
    }
}
