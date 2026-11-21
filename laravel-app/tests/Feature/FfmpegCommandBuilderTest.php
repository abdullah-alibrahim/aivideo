<?php

namespace Tests\Feature;

use App\Models\Reel;
use App\Services\FfmpegCommandBuilder;
use Tests\TestCase;

class FfmpegCommandBuilderTest extends TestCase
{
    public function test_voiceover_ducks_background_music_and_uses_the_rendered_duration(): void
    {
        $reel = new Reel([
            'title' => 'Narrated render',
            'topic' => 'Narrated render',
            'language' => 'en',
            'duration' => 10,
            'template' => 'classic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);
        $command = app(FfmpegCommandBuilder::class)->build(
            $reel,
            ['/tmp/background.jpg'],
            '/tmp/music.mp3',
            ['_render_duration' => 14.0, 'auto_music' => false],
            '/tmp/output.mp4',
            '/tmp/voice.mp3',
            null,
        );

        $this->assertStringContainsString('sidechaincompress=', $this->filterGraph($command));
        $this->assertSame('14', $command[array_search('-t', $command, true) + 1]);
    }

    public function test_transition_sound_effects_are_added_only_when_enabled_and_static_motion_is_preserved(): void
    {
        $reel = new Reel([
            'title' => 'Audio mix',
            'topic' => 'Audio mix',
            'language' => 'ar',
            'duration' => 10,
            'template' => 'cinematic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'zoom_in',
        ]);
        $builder = app(FfmpegCommandBuilder::class);
        $options = [
            'auto_music' => false,
            'motion_preset' => 'minimal',
            'headline' => 'Hook',
            'custom_points' => ['First scene', 'Second scene', 'Third scene'],
            'sound_effects' => true,
        ];

        $enabledGraph = $this->filterGraph($builder->build(
            $reel,
            ['/tmp/background.mp4'],
            null,
            $options,
            '/tmp/output.mp4',
            null,
            null,
        ));

        $this->assertStringContainsString('anoisesrc=', $enabledGraph);
        $this->assertStringContainsString('amix=', $enabledGraph);
        $this->assertStringContainsString('crop=608:1080', $enabledGraph);
        $this->assertStringNotContainsString("x='(in_w-out_w)*t/", $enabledGraph);

        $options['sound_effects'] = false;
        $disabledGraph = $this->filterGraph($builder->build(
            $reel,
            ['/tmp/background.mp4'],
            null,
            $options,
            '/tmp/output.mp4',
            null,
            null,
        ));

        $this->assertStringNotContainsString('anoisesrc=', $disabledGraph);
    }

    public function test_cinematic_transitions_alternate_at_each_scene_boundary(): void
    {
        $reel = new Reel([
            'title' => 'Cinematic',
            'topic' => 'Cinematic',
            'language' => 'en',
            'duration' => 10,
            'template' => 'cinematic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);
        $builder = app(FfmpegCommandBuilder::class);
        $inputs = ['/tmp/a.mp4', '/tmp/b.mp4', '/tmp/c.mp4'];

        $graph = $this->filterGraph($builder->build($reel, $inputs, null, [
            'auto_music' => false,
            'scene_transition' => 'whip_pan',
        ], '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('xfade=transition=smoothleft', $graph);
        $this->assertStringContainsString('xfade=transition=smoothright', $graph);

        $graph = $this->filterGraph($builder->build($reel, $inputs, null, [
            'auto_music' => false,
            'scene_transition' => 'zoom_blur',
        ], '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('xfade=transition=zoomin', $graph);
        $this->assertStringContainsString('xfade=transition=zoomout', $graph);

        $graph = $this->filterGraph($builder->build($reel, $inputs, null, [
            'auto_music' => false,
            'scene_transition' => 'glitch',
        ], '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('xfade=transition=pixelize', $graph);
        $this->assertStringContainsString('xfade=transition=hblur', $graph);
    }

    public function test_optional_cinematic_overlays_are_applied_to_the_video_chain(): void
    {
        $reel = new Reel([
            'title' => 'Overlays',
            'topic' => 'Overlays',
            'language' => 'en',
            'duration' => 10,
            'template' => 'classic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);
        $builder = app(FfmpegCommandBuilder::class);

        $graph = $this->filterGraph($builder->build($reel, ['/tmp/a.mp4'], null, [
            'auto_music' => false,
            'overlay_light_leak' => true,
            'overlay_grain' => true,
            'overlay_dust' => true,
        ], '/tmp/out.mp4', null, null));

        $this->assertStringContainsString('colorbalance=', $graph);
        $this->assertStringContainsString('vignette=PI/6', $graph);
        $this->assertStringContainsString('noise=alls=4:allf=t+u', $graph);
        $this->assertStringContainsString('noise=alls=10:allf=t+u', $graph);

        $plain = $this->filterGraph($builder->build($reel, ['/tmp/a.mp4'], null, [
            'auto_music' => false,
        ], '/tmp/out.mp4', null, null));
        $this->assertStringNotContainsString('vignette=', $plain);
        $this->assertStringNotContainsString('noise=', $plain);
    }

    public function test_the_sfx_library_uses_distinct_synthesized_sounds(): void
    {
        $reel = new Reel([
            'title' => 'SFX',
            'topic' => 'SFX',
            'language' => 'en',
            'duration' => 10,
            'template' => 'cinematic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);
        $builder = app(FfmpegCommandBuilder::class);
        $base = [
            'auto_music' => false,
            'sound_effects' => true,
            'headline' => 'Hook',
            'custom_points' => ['One', 'Two', 'Three', 'Four'],
        ];

        $click = $this->filterGraph($builder->build($reel, ['/tmp/a.mp4'], null, $base + ['sfx_style' => 'click'], '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('sine=frequency=1700', $click);
        $this->assertStringNotContainsString('anoisesrc=', $click);

        $shutter = $this->filterGraph($builder->build($reel, ['/tmp/a.mp4'], null, $base + ['sfx_style' => 'shutter'], '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('anoisesrc=color=white', $shutter);

        $auto = $this->filterGraph($builder->build($reel, ['/tmp/a.mp4'], null, $base, '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('anoisesrc=color=pink', $auto);
        $this->assertStringContainsString('sine=frequency=520', $auto);
        $this->assertStringContainsString('sine=frequency=1700', $auto);
        $this->assertStringContainsString('anoisesrc=color=white', $auto);
    }

    public function test_it_keeps_a_silent_audio_track_when_every_audio_source_is_disabled(): void
    {
        $reel = new Reel([
            'title' => 'Silent',
            'topic' => 'Silent',
            'language' => 'en',
            'duration' => 10,
            'template' => 'classic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);

        $command = implode(' ', app(FfmpegCommandBuilder::class)->build(
            $reel,
            ['/tmp/a.mp4'],
            null,
            ['auto_music' => false, 'sound_effects' => false, 'voiceover' => false],
            '/tmp/out.mp4',
            null,
            null,
        ));

        $this->assertStringContainsString('anullsrc=channel_layout=stereo:sample_rate=44100', $command);
        $this->assertStringContainsString('alimiter=limit=0.95[aout]', $command);
        $this->assertStringNotContainsString('aevalsrc=', $command);
    }

    public function test_an_edited_timeline_drives_the_sound_effect_timing(): void
    {
        $reel = new Reel([
            'title' => 'Timeline SFX',
            'topic' => 'Timeline SFX',
            'language' => 'en',
            'duration' => 10,
            'template' => 'cinematic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);
        $builder = app(FfmpegCommandBuilder::class);

        $graph = $this->filterGraph($builder->build($reel, ['/tmp/a.mp4'], null, [
            'auto_music' => false,
            'sound_effects' => true,
            'sfx_style' => 'pop',
            'timeline' => [
                ['kind' => 'headline', 'text' => 'Hook', 'start' => 0.0, 'end' => 2.0],
                ['kind' => 'point', 'text' => 'First', 'start' => 2.4, 'end' => 6.0],
                ['kind' => 'cta', 'text' => 'End', 'start' => 8.0, 'end' => 10.0],
            ],
        ], '/tmp/out.mp4', null, null));

        $this->assertStringContainsString('adelay=120|120[sfx0]', $graph);
        $this->assertStringContainsString('adelay=2400|2400[sfx1]', $graph);
        $this->assertStringContainsString('adelay=8000|8000[sfx2]', $graph);
        $this->assertStringNotContainsString('[sfx3]', $graph);
    }

    public function test_music_mood_shapes_the_procedural_background_bed(): void
    {
        $reel = new Reel([
            'title' => 'Mood',
            'topic' => 'Mood',
            'language' => 'en',
            'duration' => 10,
            'template' => 'classic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
            'niche' => 'tech',
        ]);
        $builder = app(FfmpegCommandBuilder::class);

        $energetic = implode(' ', $builder->build($reel, ['/tmp/a.mp4'], null, [
            'auto_music' => true,
            'music_mood' => 'auto',
        ], '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('130.81', $energetic);
        $this->assertStringContainsString('3.2*t', $energetic);

        $epic = implode(' ', $builder->build($reel, ['/tmp/a.mp4'], null, [
            'auto_music' => true,
            'music_mood' => 'epic',
        ], '/tmp/out.mp4', null, null));
        $this->assertStringContainsString('98*t', $epic);
    }

    public function test_a_brand_logo_is_overlaid_in_the_top_right_corner(): void
    {
        $logo = tempnam(sys_get_temp_dir(), 'logo-');
        file_put_contents($logo, 'png-bytes');
        $reel = new Reel([
            'title' => 'Logo',
            'topic' => 'Logo',
            'language' => 'en',
            'duration' => 10,
            'template' => 'classic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);

        try {
            $command = app(FfmpegCommandBuilder::class)->build($reel, ['/tmp/a.mp4'], null, [
                'auto_music' => false,
                '_logo_path' => $logo,
            ], '/tmp/out.mp4', null, null);

            $graph = $this->filterGraph($command);
            $this->assertStringContainsString('[brand]', $graph);
            $this->assertStringContainsString('overlay=W-w-24:24', $graph);
            $this->assertSame($logo, $command[array_search($logo, $command, true)]);
        } finally {
            @unlink($logo);
        }
    }

    public function test_delivery_encoding_caps_bitrate_to_keep_downloads_small(): void
    {
        $reel = new Reel([
            'title' => 'Size',
            'topic' => 'Size',
            'language' => 'en',
            'duration' => 30,
            'template' => 'classic',
            'aspect_ratio' => '9:16',
            'camera_motion' => 'static',
        ]);
        $builder = app(FfmpegCommandBuilder::class);

        $full = $builder->build($reel, ['/tmp/a.mp4'], null, ['auto_music' => false], '/tmp/out.mp4', null, null);
        $this->assertSame('23', $this->flagValue($full, '-crf'));
        $this->assertSame('6M', $this->flagValue($full, '-maxrate'));
        $this->assertSame('12M', $this->flagValue($full, '-bufsize'));

        $lite = $builder->build($reel, ['/tmp/a.mp4'], null, ['auto_music' => false, 'quality' => 720], '/tmp/out.mp4', null, null);
        $this->assertSame('25', $this->flagValue($lite, '-crf'));
        $this->assertSame('3M', $this->flagValue($lite, '-maxrate'));
        $this->assertSame('6M', $this->flagValue($lite, '-bufsize'));
    }

    /**
     * @param  list<string>  $command
     */
    private function flagValue(array $command, string $flag): string
    {
        $index = array_search($flag, $command, true);
        $this->assertIsInt($index, 'Missing flag '.$flag);

        return $command[$index + 1];
    }

    /**
     * @param  list<string>  $command
     */
    private function filterGraph(array $command): string
    {
        $index = array_search('-filter_complex', $command, true);
        $this->assertIsInt($index);

        return $command[$index + 1];
    }
}
