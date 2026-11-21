<?php

namespace Tests\Feature;

use App\Models\Reel;
use App\Services\GeminiContentService;
use App\Services\ReelContentGenerator;
use Tests\TestCase;

class ReelContentGenerationTest extends TestCase
{
    public function test_manual_content_skips_gemini_and_returns_the_supplied_script(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldNotReceive('generate');
        });

        $result = app(ReelContentGenerator::class)->generate(
            new Reel(['topic' => 'Savings', 'language' => 'en']),
            [
                'manual_content' => true,
                'custom_headline' => 'Save a little',
                'custom_points' => ['Track spending', 'Set a weekly limit'],
                'caption' => 'A practical savings tip.',
                'hashtags' => '#saving #habits',
            ],
        );

        $this->assertSame('Save a little Track spending Set a weekly limit Save this for later', $result['script']);
        $this->assertSame('Save a little', $result['headline']);
        $this->assertSame(['Track spending', 'Set a weekly limit'], $result['points']);
        $this->assertSame('A practical savings tip.', $result['caption']);
        $this->assertSame('#saving #habits', $result['hashtags']);
        $this->assertSame('Save this for later', $result['cta']);
        $this->assertSame('manual', $result['content_source']);
        $this->assertNull($result['ai_model']);
    }

    public function test_manual_content_keeps_the_call_to_action_the_user_approved(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldNotReceive('generate');
        });

        $result = app(ReelContentGenerator::class)->generate(
            new Reel(['topic' => 'Savings', 'language' => 'en']),
            [
                'manual_content' => true,
                'custom_headline' => 'Save a little',
                'custom_points' => ['Track spending', 'Set a weekly limit'],
                'cta' => 'Try the budget today',
            ],
        );

        $this->assertSame('Try the budget today', $result['cta']);
        $this->assertSame('Save a little Track spending Set a weekly limit Try the budget today', $result['script']);
    }

    public function test_ai_content_normalizes_storyboard_and_model_metadata(): void
    {
        $this->mock(GeminiContentService::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(fn (string $prompt): bool => str_contains($prompt, 'Emergency fund')
                    && str_contains($prompt, 'English'))
                ->andReturn([
                    'data' => [
                        'headline' => 'Build a buffer',
                        'hook' => 'Start with one small deposit',
                        'scenes' => [
                            ['text' => 'Track small costs', 'caption' => 'Write down each purchase.'],
                            ['text' => 'Automate savings', 'caption' => 'Move a small amount every week.'],
                        ],
                        'cta' => 'Try it today',
                        'caption' => 'A steady savings habit.',
                        'hashtags' => ['#saving', '#money'],
                    ],
                    'model' => 'gemini-test',
                ]);
        });

        $result = app(ReelContentGenerator::class)->generate(
            new Reel([
                'topic' => 'Emergency fund',
                'language' => 'en',
                'niche' => 'money',
                'goal' => 'saves',
                'style' => 'clear',
                'format_type' => 'quick_tip',
                'target_country' => 'US',
            ]),
            [],
        );

        $this->assertSame('Start with one small deposit Write down each purchase. Move a small amount every week. Try it today', $result['script']);
        $this->assertSame('Build a buffer', $result['headline']);
        $this->assertSame(['Track small costs', 'Automate savings'], $result['points']);
        $this->assertSame('A steady savings habit.', $result['caption']);
        $this->assertSame('#saving #money', $result['hashtags']);
        $this->assertSame('Try it today', $result['cta']);
        $this->assertSame('gemini', $result['content_source']);
        $this->assertSame('gemini-test', $result['ai_model']);
    }
}
