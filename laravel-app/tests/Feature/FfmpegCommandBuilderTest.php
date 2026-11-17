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
