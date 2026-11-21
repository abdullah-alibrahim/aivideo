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

        $configuredFonts = config('studio.engine.arabic_fonts', []);
        $configuredFamily = config('studio.engine.arabic_font', 'Noto Kufi Arabic');

        $family = trim((string) ($options['font_family'] ?? $configuredFamily));
        if ($family === '' || ! array_key_exists($family, $configuredFonts)) {
            $family = $configuredFamily;
        }

        $scale = max(80, min(130, (int) ($options['font_scale'] ?? 100))) / 100;
        $presetName = (string) ($options['subtitle_preset'] ?? 'classic');
        $palette = $this->preset($presetName);
        $sizes = [
            'Handle' => max(14, (int) round($height * 0.030 * $scale)),
            'Headline' => max(22, (int) round($height * 0.056 * $scale * $palette['headline']['size'])),
            'Points' => max(18, (int) round($height * 0.046 * $scale * $palette['points']['size'])),
            'CTA' => max(20, (int) round($height * 0.050 * $scale * $palette['cta']['size'])),
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
        $x = (int) round($width / 2);
        $wordByWord = ! empty($options['hook_word_by_word']);
        $emojiAuto = ! empty($options['emoji_auto']) || $presetName === 'hormozi';
        $textEffect = (string) ($options['text_effect'] ?? '');
        $highlight = ! empty($options['highlight_important'])
            ? $this->accentAssColor((string) ($options['accent_color'] ?? '#D6B87A'))
            : null;
        $timeline = $this->normaliseTimeline($options['timeline'] ?? null, $duration);

        if ($timeline !== []) {
            $pointIndex = 0;
            foreach ($timeline as $segment) {
                $styleName = match ($segment['kind']) {
                    'headline' => 'Headline',
                    'cta' => 'CTA',
                    default => 'Points',
                };
                $text = $segment['text'];
                if ($styleName === 'Points' && $emojiAuto) {
                    $text = $this->emojiFor($text, $pointIndex).' '.$text;
                    $pointIndex++;
                }
                $anchorY = $anchors[$styleName];
                $effect = $this->effect($textEffect, $anchorY, $x);
                $this->addEvent(
                    $dialogue,
                    $text,
                    $styleName,
                    $segment['start'],
                    $segment['end'],
                    $anchorY,
                    $width,
                    $effect,
                    $palette[strtolower($styleName)]['primary'],
                    $styleName === 'Headline' && $wordByWord,
                    $highlight,
                );
            }
        } else {
            // A long hook should not stay on screen for a fifth of the reel:
            // cap it to a punchy window so the body scenes get their airtime.
            $headlineEnd = $points !== []
                ? min($duration, max(2.5, min($duration * 0.34, 6.0)))
                : max(2.5, $duration - 2.0);
            $effect = $this->effect($textEffect, $anchors['Headline'], $x);
            $this->addEvent($dialogue, $headline, 'Headline', 0.0, min($duration, $headlineEnd), $anchors['Headline'], $width, $effect, $palette['headline']['primary'], $wordByWord, $highlight);

            if ($points !== []) {
                $start = min($duration, $headlineEnd);
                $end = max($start, $duration - 2.0);
                foreach ((new CaptionTimeline)->allocate($points, $start, $end) as $index => $segment) {
                    $text = $segment['text'];
                    if ($emojiAuto) {
                        $text = $this->emojiFor($text, $index).' '.$text;
                    }
                    $pointEffect = $this->effect($textEffect, $anchors['Points'], $x);
                    $this->addEvent($dialogue, $text, 'Points', $segment['start'], $segment['end'], $anchors['Points'], $width, $pointEffect, $palette['points']['primary'], $wordByWord, $highlight);
                }
            }

            $ctaStart = max(0.0, $duration - 2.0);
            $ctaEffect = $this->effect($textEffect, $anchors['CTA'], $x);
            $this->addEvent($dialogue, $cta, 'CTA', $ctaStart, $duration, $anchors['CTA'], $width, $ctaEffect, $palette['cta']['primary'], false, $highlight);
        }

        $handleEffect = $this->effect($textEffect, $anchors['Handle'], $x);
        $this->addEvent($dialogue, $handle, 'Handle', 0.0, $duration, $anchors['Handle'], $width, $handleEffect, $palette['handle']['primary']);

        $tracking = max(1, (int) round($height * 0.0012));

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
            $this->style('Handle', $family, $sizes['Handle'], $palette['handle']['primary'], '&H00000000', 1, 1, 0, 0, $width, $palette['handle']['borderStyle'], $palette['handle']['back']),
            // Every style is bold: libass reverses Arabic runs when it uses the
            // regular instance of the bundled variable font.
            $this->style('Headline', $family, $sizes['Headline'], $palette['headline']['primary'], '&H00000000', 1, 3, 1, 0, $width, $palette['headline']['borderStyle'], $palette['headline']['back'], $tracking),
            $this->style('Points', $family, $sizes['Points'], $palette['points']['primary'], '&H00000000', 1, 2, 0, 0, $width, $palette['points']['borderStyle'], $palette['points']['back']),
            $this->style('CTA', $family, $sizes['CTA'], $palette['cta']['primary'], '&H00000000', 1, 3, 1, 0, $width, $palette['cta']['borderStyle'], $palette['cta']['back'], $tracking),
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
            ...$dialogue,
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Trending subtitle palettes. Colours use ASS &HAABBGGRR notation.
     *
     * @return array<string, array{primary:string, back:string, borderStyle:int, size:float}>
     */
    private function preset(string $name): array
    {
        $classic = [
            'handle' => ['primary' => '&H30FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
            'headline' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
            'points' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
            'cta' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
        ];

        return match ($name) {
            // Alex Hormozi style: yellow hook, green body, opaque caption boxes.
            'hormozi' => [
                'handle' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 0.9],
                'headline' => ['primary' => '&H0000FFFF', 'back' => '&HC0000000', 'borderStyle' => 3, 'size' => 1.05],
                'points' => ['primary' => '&H0000FF00', 'back' => '&HC0000000', 'borderStyle' => 3, 'size' => 1.0],
                'cta' => ['primary' => '&H0000FFFF', 'back' => '&HC0000000', 'borderStyle' => 3, 'size' => 1.0],
            ],
            'karaoke' => [
                'handle' => ['primary' => '&H30FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
                'headline' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
                'points' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 3, 'size' => 1.05],
                'cta' => ['primary' => '&H0000FFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
            ],
            'minimal' => [
                'handle' => ['primary' => '&H40FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 0.85],
                'headline' => ['primary' => '&H00F5F5F5', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 0.95],
                'points' => ['primary' => '&H00E0E0E0', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 0.95],
                'cta' => ['primary' => '&H00F5F5F5', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 0.95],
            ],
            'news' => [
                'handle' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 0.9],
                'headline' => ['primary' => '&H00FFFFFF', 'back' => '&H80000000', 'borderStyle' => 1, 'size' => 1.0],
                'points' => ['primary' => '&H00FFFFFF', 'back' => '&HB0000000', 'borderStyle' => 3, 'size' => 0.98],
                'cta' => ['primary' => '&H000000FF', 'back' => '&H00FFFFFF', 'borderStyle' => 3, 'size' => 1.0],
            ],
            default => $classic,
        };
    }

    /** Pick a scene emoji from the caption keywords, falling back to a rotation. */
    private function emojiFor(string $text, int $index): string
    {
        $map = [
            '/\b(money|cash|profit|income|invest|salary|price)\b|ربح|مال|دخل|استثمار|سعر/iu' => '💰',
            '/\b(time|morning|schedule|minute|hour)\b|وقت|صباح|جدول|دقيقة|ساعة/iu' => '⏰',
            '/\b(habit|routine|repeat|daily)\b|عادة|روتين|يومي/iu' => '🔁',
            '/\b(focus|goal|target|plan)\b|تركيز|هدف|خطة/iu' => '🎯',
            '/\b(brain|think|learn|mind)\b|عقل|تفكير|تعلم|ذهن/iu' => '🧠',
            '/\b(grow|growth|scale|result)\b|نمو|نتيجة|تطور/iu' => '📈',
            '/\b(phone|app|screen|digital)\b|هاتف|تطبيق|شاشة/iu' => '📱',
            '/\b(sleep|rest|night)\b|نوم|راحة|ليل/iu' => '😴',
            '/\b(food|eat|water|healthy)\b|أكل|طعام|ماء|صحة/iu' => '🍎',
            '/\b(sport|train|workout|move)\b|تمرين|رياضة|حركة/iu' => '💪',
            '/\b(step|start|begin|first)\b|خطوة|ابدأ|أول/iu' => '👣',
        ];

        foreach ($map as $pattern => $emoji) {
            if (preg_match($pattern, $text) === 1) {
                return $emoji;
            }
        }

        $cycle = ['🔥', '💡', '⚡', '✅', '🎯', '📈', '🧠', '🚀'];

        return $cycle[$index % count($cycle)];
    }

    /**
     * @return list<array{kind:string,text:string,start:float,end:float}>
     */
    private function normaliseTimeline(mixed $timeline, float $duration): array
    {
        if (! is_array($timeline)) {
            return [];
        }

        $segments = [];
        foreach ($timeline as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->normalise((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $kind = in_array($item['kind'] ?? '', ['headline', 'point', 'cta'], true) ? (string) $item['kind'] : 'point';
            $start = max(0.0, min(max(0.0, $duration - 0.1), (float) ($item['start'] ?? 0)));
            $end = max($start + 0.1, min($duration, (float) ($item['end'] ?? $start + 2)));
            $segments[] = ['kind' => $kind, 'text' => $text, 'start' => $start, 'end' => $end];
        }

        usort($segments, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $segments;
    }

    /**
     * @param  list<string>  $dialogue
     */
    private function addEvent(array &$dialogue, string $text, string $style, float $start, float $end, int $anchorY, int $width, string $effect = '', string $primary = '&H00FFFFFF', bool $wordByWord = false, ?string $highlight = null): void
    {
        if ($text === '') {
            return;
        }

        $formattedText = $this->decorate($text, $primary, $wordByWord, $highlight);

        $dialogue[] = sprintf(
            'Dialogue: 0,%s,%s,%s,,0,0,0,,%s{\an5\pos(%d,%d)}%s',
            $this->timestamp($start),
            $this->timestamp(max($start + 0.1, $end)),
            $style,
            $effect,
            (int) round($width / 2),
            $anchorY,
            $formattedText,
        );
    }

    /**
     * Build ASS effect tags for a text block.
     *
     * Supported effects:
     *   - fade_in   : \fad(500,0)          fade in over 0.5s
     *   - slide_up  : \move(x,anchorY+120,x,anchorY,0,400) slide up from below
     *   - pop_up    : \t(0,250,\fscx150\fscy150)\t(250,500,\fscx100\fscy100)
     */
    private function effect(string $name, int $anchorY, int $x): string
    {
        return match ($name) {
            'fade_in' => '{\fad(500,0)}',
            'slide_up' => '{\move('.$x.','.($anchorY + 120).','.$x.','.$anchorY.',0,400)}',
            'pop_up' => '{\t(0,250,\fscx150\fscy150)\\t(250,500,\fscx100\fscy100)}',
            default => '',
        };
    }

    private function style(string $name, string $family, int $fontSize, string $primary, string $outline, int $bold, int $outlineWidth, int $shadow, int $encoding, int $width, int $borderStyle = 1, string $back = '&H80000000', int $spacing = 0): string
    {
        $margin = (int) round($width * 0.07);

        return sprintf(
            'Style: %s,%s,%d,%s,&H000000FF,%s,%s,%d,0,0,0,100,100,%d,0,%d,%d,%d,5,%d,%d,0,%d',
            $name,
            $family,
            $fontSize,
            $primary,
            $outline,
            $back,
            $bold ? -1 : 0,
            $spacing,
            $borderStyle,
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

    /**
     * Highlight the opening hook word and any high-signal token (numbers,
     * percentages, urgency words) in the accent colour.
     */
    private function decorate(string $text, string $primary, bool $wordByWord, ?string $highlight): string
    {
        if (! $wordByWord && $highlight === null) {
            return $this->escape($text);
        }

        $words = preg_split('/\s+/u', trim($text)) ?: [];
        if (count($words) <= 1) {
            return $this->escape($text);
        }

        $restore = '{\\1c'.$primary.'}';
        $decorated = [];
        foreach ($words as $index => $word) {
            $open = '';
            if ($index === 0 && $wordByWord) {
                $open = '{\\1c&H0070FF&}';
            }
            if ($highlight !== null && $this->isImportant($word)) {
                $open = '{\\1c'.$highlight.'}';
            }
            $escaped = $this->escape($word);
            $decorated[] = $open === '' ? $escaped : $open.$escaped.$restore;
        }

        return implode(' ', $decorated);
    }

    private function isImportant(string $word): bool
    {
        $clean = trim($word, " \t\n\r\0\x0B.,!?؟،:;؛\"'()[]{}«»…-");
        if ($clean === '') {
            return false;
        }
        if (preg_match('/^\p{N}+(?:[.,٫:]\p{N}+)?%?$/u', $clean) === 1) {
            return true;
        }

        return preg_match('/^(now|today|never|always|free|stop|start|كيف|لماذا|أفضل|مجاناً|مجانا|الآن|توقف|ابدأ|دائماً|أبداً)$/iu', $clean) === 1;
    }

    private function accentAssColor(string $hex): ?string
    {
        if (preg_match('/^#([0-9a-f]{6})$/i', $hex, $matches) !== 1) {
            return null;
        }
        [$red, $green, $blue] = array_map('hexdec', str_split($matches[1], 2));

        return sprintf('&H00%02X%02X%02X&', $blue, $green, $red);
    }
}
