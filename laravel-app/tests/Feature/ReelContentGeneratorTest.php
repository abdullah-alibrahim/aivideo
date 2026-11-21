<?php

namespace Tests\Feature;

use App\Models\Reel;
use App\Services\GeminiContentService;
use App\Services\ReelContentGenerator;
use Tests\TestCase;

class ReelContentGeneratorTest extends TestCase
{
    public function test_long_reel_prompt_generates_eight_scenes_with_a_minute_sized_script(): void
    {
        $scenes = [];
        for ($index = 1; $index <= 8; $index++) {
            $text = "المشهد {$index} يشرح خطوة عملية واضحة تساعدك على بناء عادة مفيدة وتحقيق نتيجة أفضل.";
            $scenes[] = ['text' => $text, 'caption' => $text];
        }

        $prompt = '';
        $this->mock(GeminiContentService::class, function ($mock) use (&$prompt, $scenes): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturnUsing(function (string $value) use (&$prompt, $scenes): array {
                    $prompt = $value;

                    return [
                        'data' => [
                            'headline' => 'ابدأ بخطوة صغيرة اليوم',
                            'hook' => 'ابدأ بخطوة صغيرة اليوم',
                            'scenes' => $scenes,
                            'cta' => 'احفظ الفكرة وابدأ الآن',
                        ],
                        'model' => 'test-model',
                    ];
                });
        });

        $result = app(ReelContentGenerator::class)->generate(
            new Reel(['topic' => 'بناء عادة مفيدة', 'language' => 'ar', 'duration' => 60]),
            ['custom_points' => ['أضف مثالاً عملياً من الحياة اليومية']],
        );

        $this->assertCount(8, $result['points']);
        $this->assertStringContainsString('"duration_seconds":60', $prompt);
        $this->assertStringContainsString('8 scenes', $prompt);
        $this->assertStringContainsString('أضف مثالاً عملياً من الحياة اليومية', $prompt);
        $this->assertStringContainsString('caption must exactly match its spoken text', $prompt);
        $this->assertGreaterThanOrEqual(100, count(preg_split('/\s+/u', trim($result['script']))));
    }
}
