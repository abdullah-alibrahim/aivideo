<?php

namespace App\Services;

class FactCheckService
{
    public function __construct(private readonly GeminiContentService $gemini) {}

    /** @param array<string, mixed> $input */
    public function check(array $input): array
    {
        $language = $input['language'] ?? 'ar';
        $prompt = 'You are a careful pre-publication fact-checking assistant for short videos. '
            .'Do not invent sources or claim you browsed the web. Analyze the supplied text only. '
            .'Separate factual claims from opinions, flag claims that require external verification, '
            .'and be conservative. Return only JSON with exactly these keys: status, confidence, summary, '
            .'claims, risk_flags, disclaimer. status must be one of ready, review, high_risk. '
            .'confidence is an integer 0-100. claims is an array of objects with claim, verdict, reason, '
            .'needs_source, recommendation. verdict must be supported_by_text, opinion, uncertain, misleading, or false. '
            .'risk_flags is an array of short strings. The disclaimer must say this is an AI screening, not a verified source review. '
            .'Use the requested language. Context: '.json_encode([
                'language' => $language,
                'topic' => $input['topic'] ?? '',
                'script' => $input['script'] ?? '',
                'headline' => $input['headline'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = $this->gemini->generate($prompt);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return [
            'status' => in_array($data['status'] ?? null, ['ready', 'review', 'high_risk'], true) ? $data['status'] : 'review',
            'confidence' => max(0, min(100, (int) ($data['confidence'] ?? 0))),
            'summary' => trim((string) ($data['summary'] ?? '')),
            'claims' => $this->normalizeClaims($data['claims'] ?? []),
            'risk_flags' => array_values(array_filter(array_map('strval', is_array($data['risk_flags'] ?? null) ? $data['risk_flags'] : []))),
            'disclaimer' => trim((string) ($data['disclaimer'] ?? '')),
            'ai_model' => $result['model'] ?? null,
        ];
    }

    private function normalizeClaims(mixed $claims): array
    {
        if (! is_array($claims)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($claim): ?array {
            if (! is_array($claim) || trim((string) ($claim['claim'] ?? '')) === '') {
                return null;
            }

            return [
                'claim' => trim((string) $claim['claim']),
                'verdict' => in_array($claim['verdict'] ?? null, ['supported_by_text', 'opinion', 'uncertain', 'misleading', 'false'], true)
                    ? $claim['verdict']
                    : 'uncertain',
                'reason' => trim((string) ($claim['reason'] ?? '')),
                'needs_source' => (bool) ($claim['needs_source'] ?? true),
                'recommendation' => trim((string) ($claim['recommendation'] ?? '')),
            ];
        }, $claims)));
    }
}
