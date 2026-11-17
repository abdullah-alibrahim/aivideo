<?php

namespace Tests\Feature;

use App\Services\AssOverlayBuilder;
use Tests\TestCase;

class AssOverlayBuilderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array{headline?: string, points?: list<string>, cta?: string, handle?: string}  $content
     */
    private function build(array $content = [], array $options = [], float $duration = 10.0): string
    {
        return (new AssOverlayBuilder)->build(
            array_merge([
                'headline' => 'ثلاث خطوات لبداية هادئة',
                'points' => ['اشرب الماء', 'تعرّض للضوء'],
                'cta' => 'تابعني',
                'handle' => '@yellowspace',
            ], $content),
            $options,
            1080,
            1920,
            $duration,
        );
    }

    public function test_it_renders_every_text_block_with_a_fixed_anchor(): void
    {
        $ass = $this->build();

        $this->assertStringContainsString('PlayResX: 1080', $ass);
        $this->assertStringContainsString('PlayResY: 1920', $ass);
        $this->assertStringContainsString('\an5\pos(540,691)', $ass);
        $this->assertStringContainsString('ثلاث خطوات لبداية هادئة', $ass);
        $this->assertStringContainsString('تابعني', $ass);
    }

    public function test_it_keeps_every_style_bold(): void
    {
        $ass = $this->build();

        foreach (['Handle', 'Headline', 'Points', 'CTA'] as $style) {
            $this->assertMatchesRegularExpression(
                '/^Style: '.$style.',.*,-1,/m',
                $ass,
                'Style '.$style.' must stay bold so libass does not reverse Arabic runs.',
            );
        }
    }

    public function test_it_supports_the_fade_in_effect(): void
    {
        $ass = $this->build(options: ['text_effect' => 'fade_in']);

        $this->assertStringContainsString('\fad(500,0)', $ass);
    }

    public function test_it_supports_the_slide_up_effect(): void
    {
        $ass = $this->build(options: ['text_effect' => 'slide_up']);

        $this->assertStringContainsString('\move(540,811,540,691,0,400)', $ass);
    }

    public function test_it_supports_word_by_word_highlight_effect(): void
    {
        $ass = $this->build(
            content: ['points' => ['فيديو قصير احترافي']],
            options: ['hook_word_by_word' => true],
        );

        $this->assertStringContainsString('\1c&H0070FF&', $ass);
    }

    public function test_it_uses_no_effect_tags_by_default(): void
    {
        $ass = $this->build();

        $this->assertStringNotContainsString('\fad(', $ass);
        $this->assertStringNotContainsString('\move(', $ass);
        $this->assertStringNotContainsString('\fscx', $ass);
    }

    public function test_it_only_accepts_known_font_families(): void
    {
        $ass = $this->build(options: ['font_family' => 'Not A Real Font']);

        $this->assertStringContainsString('Style: Headline,Noto Kufi Arabic,', $ass);
    }

    public function test_it_uses_a_selected_font_family(): void
    {
        $ass = $this->build(options: ['font_family' => 'Tajawal']);

        $this->assertStringContainsString('Style: Headline,Tajawal,', $ass);
    }

    public function test_it_removes_duplicate_points_and_skips_empty_blocks(): void
    {
        $ass = $this->build([
            'headline' => 'عنوان',
            'points' => ['نقطة', 'نقطة', '  ', 'نقطة ثانية'],
            'cta' => '',
            'handle' => '',
        ]);

        $dialogueLines = array_values(array_filter(
            explode("\n", $ass),
            static fn (string $line): bool => str_starts_with($line, 'Dialogue:'),
        ));

        $this->assertCount(3, $dialogueLines);
        $this->assertSame(1, count(array_filter(
            $dialogueLines,
            static fn (string $line): bool => str_ends_with($line, 'نقطة'),
        )));
        $this->assertSame(1, count(array_filter(
            $dialogueLines,
            static fn (string $line): bool => str_ends_with($line, 'نقطة ثانية'),
        )));
        $this->assertStringNotContainsString('تابعني', $ass);
        $this->assertStringNotContainsString('@yellowspace', $ass);
    }

    public function test_longer_caption_lines_stay_on_screen_longer(): void
    {
        $ass = $this->build([
            'points' => [
                'خطوة واحدة',
                'هذه عبارة أطول تشرح الفكرة بوضوح وتحتاج وقتاً أكثر للقراءة',
            ],
        ], duration: 60.0);
        $pointEvents = array_values(array_filter(
            explode("\n", $ass),
            static fn (string $line): bool => str_starts_with($line, 'Dialogue:') && str_contains($line, ',Points,'),
        ));

        $this->assertCount(2, $pointEvents);
        $this->assertGreaterThan(
            $this->dialogueDuration($pointEvents[0]),
            $this->dialogueDuration($pointEvents[1]),
        );
    }

    private function dialogueDuration(string $line): float
    {
        preg_match('/^Dialogue: 0,([^,]+),([^,]+),/', $line, $matches);

        return $this->assTimestampSeconds($matches[2]) - $this->assTimestampSeconds($matches[1]);
    }

    private function assTimestampSeconds(string $timestamp): float
    {
        [$hours, $minutes, $seconds] = array_map('floatval', explode(':', $timestamp));

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
