<?php

namespace App\Services;

/**
 * Builds an ASS (Advanced SubStation Alpha) overlay for reel text.
 *
 * libass shapes Arabic correctly, but it does not break long lines by itself in
 * a predictable way. This builder wraps every block to the safe width of the
 * frame, anchors each block to a fixed position, and therefore keeps text from
 * overlapping or running off the edges.
 */
class AssOverlayBuilder
{
    /**
     * @param  array{headline: string, points: list<string>, cta?: string, handle?: string}  $content
     * @param  array<string, mixed>  $options
     */
    public function build(array $content, array $options = [], int $width = 1080, int $height = 1920, float $duration = 10.0): string
    {
        $width = max(240, $width);
        $height = max(240, $height);
        $duration = max(1.0, $duration);

        $family = trim((string) ($options['font_family'] ?? 'Noto Kufi Arabic'));
        if ($family === '') {
            $family = 'Noto Kufi Arabic';
        }

        $scale = max(80, min(130, (int) ($options['font_scale'] ?? 100))) / 100;
        $sizes = [
            'Handle' => max(14, (int) round($height * 0.030 * $scale)),
            'Headline' => max(22, (int) round($height * 0.056 * $scale)),
            'Points' => max(18, (int) round($height * 0.046 * $scale)),
            'CTA' => max(20, (int) round($height * 0.050 * $scale)),
        ];

        $anchors = [
            'Handle' => (int) round($height * 0.075),
            'Headline' => (int) round($height * 0.360),
            'Points' => (int) round($height * 0.560),
            'CTA' => (int) round($height * 0.800),
        ];

        $headline = $this->normalise($content['headline'] ?? '');
        $points = [];
        foreach ($content['points'] ?? [] as $point) {
            $point = $this->normalise($point);
            if ($point !== '' && $point !== $headline && ! in_array($point, $points, true)) {
                $points[] = $point;
            }
        }
        $cta = $this->normalise($content['cta'] ?? '');
        $handle = $this->normalise($content['handle'] ?? '');

        $dialogue = [];

        $headlineEnd = $points !== [] ? min($duration, max(2.5, $duration * 0.34)) : max(2.5, $duration - 2.0);
        $this->addEvent($dialogue, $headline, 'Headline', 0.0, min($duration, $headlineEnd), $anchors['Headline'], $width);

        if ($points !== []) {
            $start = min($duration, $headlineEnd);
            $end = max($start, $duration - 2.0);
            $span = max(0.1, $end - $start);
            $step = $span / count($points);
            foreach ($points as $index => $point) {
                $pointStart = $start + ($index * $step);
                $this->addEvent($dialogue, $point, 'Points', $pointStart, $pointStart + $step, $anchors['Points'], $width);
            }
        }

        $ctaStart = max(0.0, $duration - 2.0);
        $this->addEvent($dialogue, $cta, 'CTA', $ctaStart, $duration, $anchors['CTA'], $width);
        $this->addEvent($dialogue, $handle, 'Handle', 0.0, $duration, $anchors['Handle'], $width);

        $lines = [
            '[Script Info]',
            'Title: AI Reel Overlay',            'ScriptType: V4.00+',
            'WrapStyle: 0',
            'ScaledBorderAndShadow: yes',
            'PlayResX: '.$width,
            'PlayResY: '.$height,
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            $this->style('Handle', $family, $sizes['Handle'], '&H30FFFFFF', '&H00000000', 1, 1, 0, 0, $width),
            // Every style is bold: libass reverses Arabic runs when it uses the
            // regular instance of the bundled variable font.
            $this->style('Headline', $family, $sizes['Headline'], '&H00FFFFFF', '&H00000000', 1, 3, 1, 0, $width),
            $this->style('Points', $family, $sizes['Points'], '&H00FFFFFF', '&H00000000', 1, 2, 0, 0, $width),
            $this->style('CTA', $family, $sizes['CTA'], '&H00FFFFFF', '&H00000000', 1, 3, 1, 0, $width),
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
            ...$dialogue,
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $dialogue
     */
    private function addEvent(array &$dialogue, string $text, string $style, float $start, float $end, int $anchorY, int $width): void
    {
        if ($text === '') {
            return;
        }

        $dialogue[] = sprintf(
            'Dialogue: 0,%s,%s,%s,,0,0,0,,{\an5\pos(%d,%d)}%s',
            $this->timestamp($start),
            $this->timestamp(max($start + 0.1, $end)),
            $style,
            (int) round($width / 2),
            $anchorY,
            $this->escape($text),
        );
    }

    private function style(string $name, string $family, int $fontSize, string $primary, string $outline, int $bold, int $outlineWidth, int $shadow, int $encoding, int $width): string
    {
        $margin = (int) round($width * 0.07);

        return sprintf(
            'Style: %s,%s,%d,%s,&H000000FF,%s,&H80000000,%d,0,0,0,100,100,0,0,1,%d,%d,5,%d,%d,0,%d',
            $name,
            $family,
            $fontSize,
            $primary,
            $outline,
            $bold ? -1 : 0,
            $outlineWidth,
            $shadow,
            $margin,
            $margin,
            $encoding,
        );
    }

    private function escape(string $line): string
    {
        return str_replace(['\\', '{', '}'], ['', '(', ')'], $line);
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function timestamp(float $seconds): string
    {
        $total = max(0, (int) round($seconds * 100));
        $hours = intdiv($total, 360000);
        $minutes = intdiv($total % 360000, 6000);
        $remaining = $total % 6000;

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, intdiv($remaining, 100), $remaining % 100);
    }
}
