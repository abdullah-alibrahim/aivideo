<?php

namespace App\Services;

class CaptionTimeline
{
    public const HOOK_RATIO = 0.16;

    public const CTA_RATIO = 0.12;

    public const MINIMUM_READING_SECONDS = 1.2;

    /**
     * @param  list<string>  $captions
     * @return list<array{text:string,start:float,end:float}>
     */
    public function allocate(array $captions, float $start, float $end): array
    {
        $captions = array_values(array_filter(
            array_map(static fn (string $caption): string => trim($caption), $captions),
            static fn (string $caption): bool => $caption !== '',
        ));
        if ($captions === []) {
            return [];
        }

        $weights = array_map(static function (string $caption): int {
            preg_match_all('/[\p{L}\p{N}]+/u', $caption, $matches);

            return max(1, count($matches[0]));
        }, $captions);
        $totalWeight = array_sum($weights);
        $span = max(0.0, $end - $start);
        $elapsedWeight = 0;
        $segments = [];

        foreach ($captions as $index => $caption) {
            $segmentStart = $start + ($span * $elapsedWeight / $totalWeight);
            $elapsedWeight += $weights[$index];
            $segmentEnd = $index === array_key_last($captions)
                ? $end
                : $start + ($span * $elapsedWeight / $totalWeight);
            $segments[] = ['text' => $caption, 'start' => $segmentStart, 'end' => $segmentEnd];
        }

        return $segments;
    }

    /**
     * Allocate hook, body and call-to-action segments across the full runtime.
     *
     * The hook owns the opening beat, the CTA owns the closing beat, and the
     * remaining body is shared by the scenes proportionally to their spoken
     * word count. Every scene keeps a minimum reading window so long scripts
     * never collapse into unreadable flashes.
     *
     * @param  list<string>  $hook
     * @param  list<string>  $points
     * @param  list<string>  $cta
     * @return array{hook:array{text:string,start:float,end:float}|null,body:list<array{text:string,start:float,end:float}>,cta:array{text:string,start:float,end:float}|null}
     */
    public function allocateScenes(array $hook, array $points, array $cta, float $duration, string $language = 'ar'): array
    {
        $duration = max(3.0, $duration);
        $hookText = $this->firstLine($hook);
        $ctaText = $this->firstLine($cta);
        $points = array_values(array_filter(
            array_map(static fn (string $point): string => trim($point), $points),
            static fn (string $point): bool => $point !== '',
        ));

        $hookSeconds = $this->segmentSeconds($duration, self::HOOK_RATIO, 2.4, $hookText !== '' ? 1.5 : 0.0);
        $ctaSeconds = $this->segmentSeconds($duration, self::CTA_RATIO, 2.0, $ctaText !== '' ? 1.5 : 0.0);
        $bodyStart = $hookText !== '' ? $hookSeconds : 0.0;
        $bodyEnd = $ctaText !== '' ? $duration - $ctaSeconds : $duration;
        $body = $this->allocateBody($points, $bodyStart, $bodyEnd, $language);

        $hookSegment = $hookText !== ''
            ? ['text' => $hookText, 'start' => 0.0, 'end' => $hookSeconds]
            : null;
        $ctaSegment = $ctaText !== ''
            ? ['text' => $ctaText, 'start' => max($bodyEnd, $duration - $ctaSeconds), 'end' => $duration]
            : null;

        return ['hook' => $hookSegment, 'body' => $body, 'cta' => $ctaSegment];
    }

    /**
     * Stretch or compress scene boundaries to match a narration of a known length.
     *
     * Voiceover from ElevenLabs rarely matches the theoretical word-rate plan.
     * The CTA keeps its fixed closing slot; the hook and body segments share a
     * budget derived from the narration length, always keeping a minimum
     * reading window so captions never collapse into unreadable flashes.
     *
     * @param  array{hook:array{text:string,start:float,end:float}|null,body:list<array{text:string,start:float,end:float}>,cta:array{text:string,start:float,end:float}|null}  $plan
     * @return array{hook:array{text:string,start:float,end:float}|null,body:list<array{text:string,start:float,end:float}>,cta:array{text:string,start:float,end:float}|null}
     */
    public function fitToNarration(array $plan, float $duration, float $narrationSeconds): array
    {
        $duration = max(3.0, $duration);
        $narrationSeconds = max(0.0, $narrationSeconds);
        $finalDuration = max($duration, $narrationSeconds);

        $hook = $plan['hook'];
        if ($hook !== null) {
            $hook['end'] = min($finalDuration, max($hook['start'] + 0.1, $hook['start'] + ($hook['end'] - $hook['start']) * ($finalDuration / max(0.1, $duration))));
        }

        $cta = $plan['cta'];
        $ctaLength = $cta !== null ? max(self::MINIMUM_READING_SECONDS, $cta['end'] - $cta['start']) : 2.0;
        $ctaStart = max(0.0, $finalDuration - $ctaLength);

        $body = [];
        $points = $plan['body'];
        if ($points !== []) {
            $bodyStart = $hook !== null ? $hook['end'] : 0.0;
            $bodySpan = max(0.1, $ctaStart - $bodyStart);
            $origBodyStart = $points[0]['start'];
            $origBodyEnd = $cta !== null ? $cta['start'] : $duration;
            $origBodySpan = max(0.1, $origBodyEnd - $origBodyStart);

            foreach ($points as $index => $point) {
                $isLast = $index === count($points) - 1;
                $origLength = max(0.1, $point['end'] - $point['start']);
                $length = $origLength * ($bodySpan / max(0.1, $origBodySpan));
                $start = $bodyStart;
                $end = $isLast ? $ctaStart : min($ctaStart, $start + max(self::MINIMUM_READING_SECONDS, $length));
                $body[] = ['text' => $point['text'], 'start' => $start, 'end' => $end];
                $bodyStart = $end;
            }
        }

        if ($body !== [] && $cta !== null) {
            $body[count($body) - 1]['end'] = $ctaStart;
        }

        $ctaStart = max(0.0, $finalDuration - $ctaLength);
        $ctaSegment = $cta !== null
            ? ['text' => $cta['text'], 'start' => $ctaStart, 'end' => $finalDuration]
            : null;

        return [
            'duration' => $finalDuration,
            'hook' => $hook,
            'body' => $body,
            'cta' => $ctaSegment,
        ];
    }

    /**
     * Replace estimated scene timings with timings measured from the spoken
     * narration. Segments that cannot be found in the narration keep their
     * estimated window, so alignment never drops a caption.
     *
     * @param  array{hook:array{text:string,start:float,end:float}|null,body:list<array{text:string,start:float,end:float}>,cta:array{text:string,start:float,end:float}|null}  $plan
     * @param  list<array{text:string,start:float,end:float}>  $words
     * @return array{hook:array{text:string,start:float,end:float}|null,body:list<array{text:string,start:float,end:float}>,cta:array{text:string,start:float,end:float}|null}
     */
    public function alignToWords(array $plan, array $words, float $duration): array
    {
        if ($words === []) {
            return $plan;
        }

        $duration = max(3.0, $duration);
        $tokens = array_map(fn (array $word): array => $this->tokens($word['text']), $words);
        $cursor = 0;

        $assign = function (array $segment) use (&$cursor, $words, $tokens): array {
            $match = $this->matchSegment((string) $segment['text'], $tokens, $cursor);
            if ($match === null) {
                return $segment;
            }

            [$first, $last] = $match;
            $cursor = $last + 1;
            $start = (float) $words[$first]['start'];
            $end = (float) $words[$last]['end'];

            return ['text' => $segment['text'], 'start' => $start, 'end' => max($start + 0.35, $end)];
        };

        $hook = ! empty($plan['hook']) ? $assign($plan['hook']) : null;
        $body = [];
        foreach ($plan['body'] as $point) {
            $body[] = $assign($point);
        }
        $cta = ! empty($plan['cta']) ? $assign($plan['cta']) : null;

        $flat = [];
        if ($hook !== null) {
            $flat[] = ['target' => 'hook', 'index' => null, 'segment' => $hook];
        }
        foreach ($body as $index => $point) {
            $flat[] = ['target' => 'body', 'index' => $index, 'segment' => $point];
        }
        if ($cta !== null) {
            $flat[] = ['target' => 'cta', 'index' => null, 'segment' => $cta];
        }

        $previousEnd = 0.0;
        foreach ($flat as $position => $item) {
            $start = max($previousEnd, (float) $item['segment']['start']);
            $end = max($start + 0.35, (float) $item['segment']['end']);
            $flat[$position]['segment']['start'] = $start;
            $flat[$position]['segment']['end'] = $end;
            $previousEnd = $end;
        }

        if ($previousEnd > $duration) {
            $scale = $duration / $previousEnd;
            foreach ($flat as $position => $item) {
                $flat[$position]['segment']['start'] = (float) $item['segment']['start'] * $scale;
                $flat[$position]['segment']['end'] = min($duration, (float) $item['segment']['end'] * $scale);
            }
        } elseif ($cta !== null) {
            foreach ($flat as $position => $item) {
                if ($item['target'] === 'cta') {
                    $flat[$position]['segment']['end'] = $duration;
                }
            }
        }

        $hook = null;
        $body = [];
        $cta = null;
        foreach ($flat as $item) {
            if ($item['target'] === 'hook') {
                $hook = $item['segment'];
            } elseif ($item['target'] === 'cta') {
                $cta = $item['segment'];
            } else {
                $body[(int) $item['index']] = $item['segment'];
            }
        }
        ksort($body);

        return ['hook' => $hook, 'body' => array_values($body), 'cta' => $cta];
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  list<list<string>>  $tokens
     * @return array{int, int}|null first and last matched word index
     */
    private function matchSegment(string $text, array $tokens, int $cursor): ?array
    {
        $textTokens = $this->tokens($text);
        $needle = array_values(array_unique($textTokens));
        $total = count($tokens);
        if ($needle === [] || $total === 0) {
            return null;
        }

        $required = max(1, (int) ceil(count($needle) * 0.6));
        $length = count($textTokens);
        $bestScore = 0;
        $bestStart = null;
        $bestLength = $length;

        for ($start = max(0, $cursor); $start < $total; $start++) {
            for ($size = max(1, $length - 2); $size <= $length + 2; $size++) {
                if ($start + $size > $total) {
                    break;
                }
                $remaining = array_flip($needle);
                $score = 0;
                for ($offset = 0; $offset < $size; $offset++) {
                    foreach ($tokens[$start + $offset] as $token) {
                        if (isset($remaining[$token])) {
                            unset($remaining[$token]);
                            $score++;
                        }
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestStart = $start;
                    $bestLength = $size;
                }
            }
        }

        if ($bestStart === null || $bestScore < $required) {
            return null;
        }

        return [$bestStart, min($total - 1, $bestStart + $bestLength - 1)];
    }

    /**
     * @param  array{hook:array{text:string,start:float,end:float}|null,body:list<array{text:string,start:float,end:float}>,cta:array{text:string,start:float,end:float}|null}  $plan
     * @return list<array{kind:string,text:string,start:float,end:float}>
     */
    public function segments(array $plan): array
    {
        $list = [];
        if (! empty($plan['hook'])) {
            $list[] = [
                'kind' => 'headline',
                'text' => $plan['hook']['text'],
                'start' => $plan['hook']['start'],
                'end' => $plan['hook']['end'],
            ];
        }
        foreach ($plan['body'] as $point) {
            $list[] = ['kind' => 'point', 'text' => $point['text'], 'start' => $point['start'], 'end' => $point['end']];
        }
        if (! empty($plan['cta'])) {
            $list[] = ['kind' => 'cta', 'text' => $plan['cta']['text'], 'start' => $plan['cta']['start'], 'end' => $plan['cta']['end']];
        }

        return $list;
    }

    /** @param  list<string>  $lines */
    private function firstLine(array $lines): string
    {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function segmentSeconds(float $duration, float $ratio, float $cap, float $floor): float
    {
        $seconds = $duration * $ratio;

        return max($floor, min($cap, $seconds));
    }

    /**
     * @param  list<string>  $points
     * @return list<array{text:string,start:float,end:float}>
     */
    private function allocateBody(array $points, float $start, float $end, string $language): array
    {
        if ($points === [] || $end <= $start) {
            return [];
        }

        $wordsPerMinute = $language === 'en' ? 155.0 : 135.0;
        $wordRate = max(1.0, $wordsPerMinute / 60.0);
        $span = $end - $start;
        $weights = [];
        $totalWeight = 0.0;

        foreach ($points as $point) {
            preg_match_all('/[\p{L}\p{N}]+/u', $point, $matches);
            $wordCount = max(1, count($matches[0] ?? []));
            $readingSeconds = $wordCount / $wordRate;
            $weight = max(1.0, $readingSeconds);
            $weights[] = $weight;
            $totalWeight += $weight;
        }

        $segments = [];
        foreach ($points as $index => $point) {
            $proportional = $span * ($weights[$index] / max(0.0001, $totalWeight));
            $length = max(self::MINIMUM_READING_SECONDS, $proportional);
            $segmentStart = $index === 0 ? $start : max($start, $segments[$index - 1]['end']);
            $segmentEnd = min($end, $segmentStart + $length);
            if ($segmentEnd - $segmentStart < self::MINIMUM_READING_SECONDS) {
                $segmentEnd = min($end, $segmentStart + self::MINIMUM_READING_SECONDS);
            }
            $segments[] = ['text' => $point, 'start' => $segmentStart, 'end' => $segmentEnd];
        }

        return $segments;
    }
}
