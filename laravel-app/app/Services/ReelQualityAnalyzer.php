<?php

namespace App\Services;

class ReelQualityAnalyzer
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function analyze(array $input): array
    {
        $language = in_array($input['language'] ?? null, ['ar', 'en'], true) ? $input['language'] : 'ar';
        $hook = trim((string) ($input['headline'] ?? $input['hook_headline'] ?? ''));
        $prompt = trim((string) ($input['user_prompt'] ?? ''));
        $topic = trim((string) ($input['topic'] ?? ''));
        $manual = (bool) ($input['manual_content'] ?? false);
        $lines = array_values(array_filter(array_map(
            static fn (mixed $line): string => is_string($line) ? trim($line) : '',
            (array) ($input['custom_points'] ?? $input['lines'] ?? []),
        ), static fn (string $line): bool => $line !== ''));
        $script = implode(' ', $lines);
        $duration = max(3.0, (float) ($input['duration'] ?? 10));
        preg_match_all('/[\p{L}\p{N}]+/u', $script, $matches);
        $wordCount = count($matches[0] ?? []);
        $chars = mb_strlen($hook, 'UTF-8');
        $ratio = (string) ($input['aspect_ratio'] ?? '9:16');
        $hasBrief = $prompt !== '' || $topic !== '';

        $checks = [
            [
                'key' => 'hook',
                'status' => $hook === '' ? ($manual ? 'warning' : 'info') : (($chars >= 8 && $chars <= 90) ? 'pass' : 'warning'),
                'value' => $hook === '' ? null : $chars,
            ],
            [
                'key' => 'script',
                'status' => $lines !== [] ? 'pass' : (($hasBrief && ! $manual) ? 'info' : 'warning'),
                'value' => $lines === [] ? null : count($lines),
            ],
            [
                'key' => 'pacing',
                'status' => $wordCount === 0
                    ? (($hasBrief && ! $manual) ? 'info' : 'warning')
                    : (($wordCount >= max(1, (int) floor($duration * 0.75)) && $wordCount <= (int) ceil($duration * 3.0)) ? 'pass' : 'warning'),
                'value' => $wordCount === 0 ? null : $wordCount,
            ],
            [
                'key' => 'format',
                'status' => in_array($ratio, ['9:16', '1:1', '16:9'], true) ? 'pass' : 'warning',
                'value' => $ratio,
            ],
        ];

        $weights = ['hook' => 20, 'script' => 30, 'pacing' => 25, 'format' => 25];
        $score = 0;
        foreach ($checks as $check) {
            $weight = $weights[$check['key']] ?? 0;
            $score += match ($check['status']) {
                'pass' => $weight,
                'info' => (int) round($weight * 0.75),
                default => 0,
            };
        }

        return [
            'version' => 1,
            'stage' => 'pre_render',
            'status' => collect($checks)->contains(fn (array $check): bool => $check['status'] === 'warning') ? 'review' : 'ready',
            'readiness_score' => $score,
            'duration_seconds' => $duration,
            'aspect_ratio' => $ratio,
            'word_count' => $wordCount,
            'checks' => $checks,
            'disclaimer_key' => 'heuristic_disclaimer',
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
