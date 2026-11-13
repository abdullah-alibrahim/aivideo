<?php

namespace App\Services;

/**
 * Generates ASS (Advanced SubStation Alpha) subtitle overlay files from text content.
 * Used by FfmpegCommandBuilder to render Arabic text with proper word wrapping,
 * line breaking, and styling instead of the drawtext filter.
 */
class AssOverlayBuilder
{
    /**
     * Build an ASS subtitle content string from the given text components.
     *
     * @param  array{headline:string,points:list<string>,cta?:string,handle?:string} $content
     * @param  array $options Additional options (font_size, etc.)
     * @param  int $width Video width
     * @param  int $height Video height
     * @param  float $duration Video duration in seconds
     * @return string ASS format content
     */
    public function build(array $content, array $options = [], int $width = 1080, int $height = 1920, float $duration = 10.0): string
    {
        $font = trim((string) ($options['arabic_font'] ?? ''));
        if ($font === '') {
            $font = 'Noto Kufi Arabic';
        }

        $fontSize = max(24, min(100, (int) ($options['font_scale'] ?? 100)));

        $styles = $this->buildStyles($font, $fontSize);

        $lines = [];

        // Header
        $lines[] = '[Script Info]';
        $lines[] = 'Title: AI Video Subtitle';
        $lines[] = 'ScriptType: V4.00+';
        $lines[] = 'WrapStyle: 0';
        $lines[] = 'ScaledTime: 0';
        $lines[] = 'YCbCr420 true';
        $lines[] = 'PlayResX: '.$width;
        $lines[] = 'PlayResY: '.$height;
        $lines[] = '';

        // V4+ Styles section
        $lines[] = '[V4+ Styles]';
        $lines[] = 'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding';

        // Add styles
        foreach ($styles as $name => $styleLine) {
            $lines[] = 'Style: '.$name.','.$styleLine;
        }

        $lines[] = '';

        // Timer section
        $lines[] = '[Events]';
        $lines[] = 'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text';

        // Helper to build an event
        $buildEvent = function (int $layer, float $start, float $end, string $styleName, string $text) use ($width, $duration) {
            $startStr = $this->formatAssTime($start);
            $endStr = $this->formatAssTime($end);
            $escapedText = addslashes($text);
            $escapedText = str_replace(',', '\\,', $escapedText);
            return 'Dialogue: 0,'.$startStr.','.$endStr.',0,'.$styleName.',0,0,0,2,0,0,40,,'.$escapedText;
        };

        // Add headline entry
        $headlineText = $this->wrapText($content['headline'], $width, $font, $fontSize);
        $headlineStart = 0;
        $headlineEnd = min(3.0, $duration);
        $lines[] = $buildEvent(0, $headlineStart, $headlineEnd, 'Headline', $headlineText);

        // Add points entries (distributed across the video)
        $pointCount = max(1, count($content['points']));
        $pointDuration = $duration / $pointCount;
        foreach ($content['points'] as $index => $point) {
            $start = $index * $pointDuration;
            $end = min(($index + 1) * $pointDuration, $duration);
            $wrappedPoint = $this->wrapText($point, $width, $font, $fontSize - 10);
            $lines[] = $buildEvent($index + 1, $start, $end, 'Points', $wrappedPoint);
        }

        // Add CTA entry (at the end)
        $ctaText = $this->wrapText($content['cta'] ?? '', $width, $font, $fontSize);
        $ctaStart = max(0, $duration - 2.0);
        $ctaEnd = $duration;
        $lines[] = $buildEvent(count($content['points']) + 1, $ctaStart, $ctaEnd, 'CTA', $ctaText);

        // Add handle entry (at the beginning or fixed position)
        $handleText = $this->wrapText($content['handle'] ?? '', $width, $font, $fontSize - 16);
        $lines[] = $buildEvent(count($content['points']) + 2, 0, $duration, 'Handle', $handleText);

        return "\n".implode("\n", $lines)."\n";
    }

    /**
     * Build ASS style definitions.
     */
    private function buildStyles(string $font, int $fontSize): array
    {
        $primaryColor = $this->rgbToAssColor(255, 255, 255);
        $secondaryColor = $this->rgbToAssColor(255, 255, 255);
        $outlineColor = 'FFFFFF';
        $backColor = '00000000';

        $headlineStyle = $this->styleLine($font, $fontSize, $primaryColor, $secondaryColor, $outlineColor, $backColor, -1, 0, 0, 0, 0, 0, 0, 2, 2, 2, 0, 0, 40, 0);
        $pointStyle = $this->styleLine($font, max(20, $fontSize - 10), $primaryColor, $secondaryColor, $outlineColor, $backColor, 0, 0, 0, 0, 0, 0, 1, 1, 0, 2, 0, 20, 0);
        $ctaStyle = $this->styleLine($font, max(24, $fontSize - 8), $primaryColor, $secondaryColor, $outlineColor, $backColor, -1, 0, 0, 0, 0, 0, 0, 2, 2, 2, 0, 0, 30, 0);
        $handleStyle = $this->styleLine($font, max(16, $fontSize - 16), $primaryColor, $secondaryColor, $outlineColor, $backColor, 0, 0, 0, 0, 0, 0, 1, 1, 0, 2, 0, 10, 0);

        return [
            'Headline' => $headlineStyle,
            'Points' => $pointStyle,
            'CTA' => $ctaStyle,
            'Handle' => $handleStyle,
        ];
    }

    /**
     * Build a single ASS style line.
     */
    private function styleLine(string $font, int $fontSize, string $primaryColor, string $secondaryColor, string $outlineColor, string $backColor, int $bold = 0, int $italic = 0, int $underline = 0, int $strikeout = 0, int $scaleX = 0, int $scaleY = 0, int $spacing = 0, int $angle = 0, int $borderStyle = 1, int $outline = 0, int $shadow = 0, int $alignment = 2, int $marginL = 0, int $marginR = 0, int $marginV = 40, int $encoding = 0): string
    {
        return $font.','
            .$fontSize.','
            .$primaryColor.','
            .$secondaryColor.','
            .$outlineColor.','
            .$backColor.','
            .($bold ? '-1' : '0').','
            .($italic ? '1' : '0').','
            .($underline ? '1' : '0').','
            .($strikeout ? '1' : '0').','
            .$scaleX.','
            .$scaleY.','
            .$spacing.','
            .$angle.','
            .$borderStyle.','
            .$outline.','
            .$shadow.','
            .$alignment.','
            .$marginL.','
            .$marginR.','
            .$marginV.','
            .$encoding;
    }

    /**
     * Convert RGB color to ASS format (BBGGRR without #).
     */
    private function rgbToAssColor(int $r, int $g, int $b): string
    {
        return sprintf('%02X%02X%02X', $b, $g, $r);
    }

    /**
     * Wrap text to fit within video width.
     */
    private function wrapText(string $text, int $width, string $font, int $fontSize): string
    {
        if (trim($text) === '') {
            return '';
        }

        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine.' '.$word;
            $estimatedWidth = strlen($testLine) * $fontSize * 0.6;

            if ($estimatedWidth <= $width - 80) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return implode("\n", $lines);
    }

    /**
     * Format a float time in ASS format.
     */
    private function formatAssTime(float $seconds): string
    {
        $totalSeconds = max(0.0, $seconds);
        $intSeconds = (int) $totalSeconds;
        $hours = intdiv($intSeconds * 3600, 3600);
        $minutes = intdiv($intSeconds * 60, 60) % 60;
        $secondsFloor = $intSeconds;
        $centiseconds = round(($totalSeconds - $intSeconds) * 100);

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d,%02d', $hours, $minutes, $secondsFloor, $centiseconds);
        }

        return sprintf('%02d:%02d:%02d,%02d', $minutes, 0, $secondsFloor, $centiseconds);
    }
}