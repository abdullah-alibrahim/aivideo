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

    public function test_the_hormozi_preset_uses_yellow_and_green_with_automatic_emoji(): void
    {
        $ass = $this->build(options: ['subtitle_preset' => 'hormozi']);

        $this->assertMatchesRegularExpression('/^Style: Headline,.*,&H0000FFFF,/m', $ass);
        $this->assertMatchesRegularExpression('/^Style: Points,.*,&H0000FF00,/m', $ass);
        $this->assertMatchesRegularExpression('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $ass);
    }

    public function test_an_explicit_timeline_overrides_text_and_timing(): void
    {
        $ass = $this->build(options: [
            'timeline' => [
                ['kind' => 'headline', 'text' => 'HOOK EDIT', 'start' => 0.0, 'end' => 2.0],
                ['kind' => 'point', 'text' => 'SCENE EDIT', 'start' => 2.0, 'end' => 6.0],
                ['kind' => 'cta', 'text' => 'CTA EDIT', 'start' => 8.0, 'end' => 10.0],
            ],
        ]);

        $this->assertStringContainsString('HOOK EDIT', $ass);
        $this->assertStringContainsString('SCENE EDIT', $ass);
        $this->assertStringContainsString('CTA EDIT', $ass);
        $this->assertStringContainsString('0:00:02.00', $ass);
        $this->assertStringContainsString('0:00:08.00', $ass);
        $this->assertStringNotContainsString('اشرب الماء', $ass);
    }

    public function test_it_accepts_every_trending_preset(): void
    {
        foreach (['classic', 'hormozi', 'karaoke', 'minimal', 'news'] as $preset) {
            $ass = $this->build(options: ['subtitle_preset' => $preset]);
            $this->assertStringContainsString('PlayResX: 1080', $ass);
            $this->assertStringContainsString('Style: Headline', $ass);
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

    public function test_it_highlights_numbers_and_urgency_words_with_the_accent_colour(): void
    {
        $ass = $this->build(
            content: ['points' => ['وفّر 20% من دخلك الآن']],
            options: ['highlight_important' => true, 'accent_color' => '#D6B87A'],
        );

        $this->assertStringContainsString('\1c&H007AB8D6&', $ass);
    }

    public function test_it_keeps_the_long_hook_short_so_body_scenes_get_airtime(): void
    {
        $ass = $this->build(duration: 60.0);
        $headlineLine = '';
        foreach (explode("\n", $ass) as $line) {
            if (str_starts_with($line, 'Dialogue:') && str_contains($line, ',Headline,')) {
                $headlineLine = $line;
                break;
            }
        }

        $this->assertNotSame('', $headlineLine);
        $this->assertLessThanOrEqual(6.0, $this->dialogueDuration($headlineLine));
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
