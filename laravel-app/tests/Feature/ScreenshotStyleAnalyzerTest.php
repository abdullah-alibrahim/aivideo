<?php

namespace Tests\Feature;

use App\Services\GeminiContentService;
use App\Services\ScreenshotStyleAnalyzer;
use Tests\TestCase;

class ScreenshotStyleAnalyzerTest extends TestCase
{
    public function test_it_maps_a_reference_frame_to_sanitised_render_options(): void
    {
        $prompt = '';
        $this->mock(GeminiContentService::class, function ($mock) use (&$prompt): void {
            $mock->shouldReceive('generateFromImage')
                ->once()
                ->andReturnUsing(function (string $value) use (&$prompt): array {
                    $prompt = $value;

                    return [
                        'data' => [
                            'title' => 'Cash habits',
                            'topic' => 'Saving money',
                            'headline' => 'Stop leaking cash',
                            'points' => ['Track every purchase', 'Cut one subscription', 'Move the savings', 'Review weekly', 'Extra scene'],
                            'cta' => 'Start today',
                            'caption' => 'A practical money habit.',
                            'hashtags' => ['#money', '#saving'],
                            'design' => [
                                'template' => 'explode',
                                'subtitle_preset' => 'hormozi',
                                'accent_color' => '#12ab34',
                                'background_dimming' => 140,
                                'font_scale' => 500,
                                'text_effect' => 'fade_in',
                                'camera_motion' => 'drift',
                                'motion_preset' => 'dynamic',
                                'scene_transition' => 'whip_pan',
                                'color_grade' => 'warm',
                                'overlay_grain' => 'true',
                                'overlay_light_leak' => 1,
                                'emoji_auto' => 'false',
                                'hook_word_by_word' => true,
                                'highlight_important' => 'yes',
                                'music_mood' => 'energetic',
                                'sfx_style' => 'pop',
                                'background_id' => 'unknown_asset',
                                'design_notes' => 'Bold type on a saturated stripe.',
                            ],
                        ],
                        'model' => 'gemini-vision-test',
                    ];
                });
        });

        $analysis = app(ScreenshotStyleAnalyzer::class)->analyze(
            '/tmp/reference.jpg',
            'ar',
            4,
            ['neon_city', 'clean_studio'],
            'عن التوفير الشهري',
        );

        $this->assertSame('Stop leaking cash', $analysis['headline']);
        $this->assertCount(4, $analysis['points']);
        $this->assertSame('#money #saving', $analysis['hashtags']);
        $this->assertSame('cinematic', $analysis['template']);
        $this->assertSame('hormozi', $analysis['subtitle_preset']);
        $this->assertSame('#12AB34', $analysis['accent_color']);
        $this->assertSame(80, $analysis['background_dimming']);
        $this->assertSame(130, $analysis['font_scale']);
        $this->assertTrue($analysis['overlay_grain']);
        $this->assertTrue($analysis['overlay_light_leak']);
        $this->assertFalse($analysis['emoji_auto']);
        $this->assertTrue($analysis['highlight_important']);
        $this->assertSame('', $analysis['background_id']);
        $this->assertSame('gemini-vision-test', $analysis['ai_model']);

        $this->assertStringContainsString('4 short spoken scenes', $prompt);
        $this->assertStringContainsString('Arabic', $prompt);
        $this->assertStringContainsString('neon_city', $prompt);
        $this->assertStringContainsString('عن التوفير الشهري', $prompt);
    }

    public function test_it_rejects_an_analysis_without_a_usable_script(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldReceive('generateFromImage')->once()->andReturn(['data' => ['headline' => ''], 'model' => 'x']);
        });

        $this->expectException(\RuntimeException::class);

        app(ScreenshotStyleAnalyzer::class)->analyze('/tmp/reference.jpg', 'en', 3, []);
    }
}
