<?php

namespace App\Services;

use App\Exceptions\RenderFailedException;
use App\Models\Reel;
use RuntimeException;

class ReelContentGenerator
{
    public function __construct(private readonly GeminiContentService $gemini) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{script:string,headline:string,points:list<string>,cta:string,caption:string,hashtags:string,content_source:string,ai_model:string|null}
     */
    public function generate(Reel $reel, array $options): array
    {
        $language = (string) ($reel->language ?: 'en');
        $customHeadline = trim((string) ($options['custom_headline'] ?? ''));
        $customPoints = $this->normalizeStrings((array) ($options['custom_points'] ?? []));
        $customCta = trim((string) ($options['cta'] ?? ''));
        $caption = trim((string) ($options['caption'] ?? ''));
        $hashtags = trim((string) ($options['hashtags'] ?? ''));
        $manual = (bool) ($options['manual_content'] ?? false);

        if ($manual) {
            if ($customHeadline === '' || $customPoints === []) {
                throw $this->failure('invalid_result', false, $language);
            }

            return $this->result(
                $this->script($customHeadline, $customPoints, [], $language, $customCta !== '' ? $customCta : null),
                $customHeadline,
                $customPoints,
                $customCta !== '' ? $customCta : ($language === 'en' ? 'Save this for later' : 'احفظ هذا وارجع له لاحقاً'),
                $caption,
                $hashtags,
                'manual',
                null,
            );
        }

        try {
            $response = $this->gemini->generate($this->prompt($reel, $options));
        } catch (RuntimeException $exception) {
            throw $this->geminiFailure($exception, $language);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $duration = max(3.0, (float) ($reel->duration ?: 10));
        $sceneCount = (new VideoScriptPacing)->sceneCount($duration);
        $scenes = $this->normalizeScenes($data['scenes'] ?? null, $sceneCount);
        $headline = $this->firstString($data, ['headline', 'title', 'hook']);
        if ($headline === '' && $scenes !== []) {
            $headline = $scenes[0]['text'];
        }
        if ($headline === '') {
            throw $this->failure('gemini_failed', true, $language);
        }

        $hook = $this->firstString($data, ['hook', 'opening']) ?: $headline;
        if ($customHeadline !== '') {
            $headline = $customHeadline;
            $hook = $customHeadline;
        }

        if ($scenes !== []) {
            $points = array_column($scenes, 'text');
        } else {
            $points = $this->normalizeStrings(is_array($data['points'] ?? null) ? $data['points'] : []);
            if ($points === []) {
                $pointValues = [];
                for ($index = 1; $index <= $sceneCount; $index++) {
                    $pointValues[] = $this->firstString($data, [
                        'point_'.$index, 'point'.$index, 'step_'.$index, 'step'.$index,
                    ]);
                }
                $points = $this->normalizeStrings($pointValues);
            }
        }
        if ($points === []) {
            throw $this->failure('gemini_failed', true, $language);
        }

        if ($scenes === []) {
            foreach ($points as $point) {
                $scenes[] = [
                    'text' => $point,
                    'caption' => $point,
                ];
            }
        }

        $cta = $this->firstString($data, ['cta', 'call_to_action'])
            ?: ($language === 'en' ? 'Save this for later' : 'احفظ هذا وارجع له لاحقاً');
        $caption = $caption !== '' ? $caption : $this->firstString($data, ['caption', 'description', 'post_caption']);
        $hashtags = $hashtags !== '' ? $hashtags : $this->firstString($data, ['hashtags', 'hash_tags', 'tags']);

        return $this->result(
            $this->script($hook, $points, $scenes, $language, $cta),
            $headline,
            $points,
            $cta,
            $caption,
            $hashtags,
            'gemini',
            is_string($response['model'] ?? null) ? $response['model'] : null,
            $scenes,
        );
    }

    private function prompt(Reel $reel, array $options): string
    {
        $language = $reel->language === 'ar' ? 'Arabic' : 'English';
        $duration = max(3.0, (float) ($reel->duration ?: 10));
        $pacing = new VideoScriptPacing;
        $customPoints = $this->normalizeStrings((array) ($options['custom_points'] ?? []));
        $customHeadline = trim((string) ($options['custom_headline'] ?? ''));
        $context = [
            'topic' => $reel->topic,
            'brief' => $reel->user_prompt ?: $reel->topic,
            'opening_hook' => $customHeadline,
            'niche' => $reel->niche,
            'goal' => $reel->goal,
            'target_country' => $reel->target_country,
            'style' => $reel->style,
            'format' => $reel->format_type,
            'language' => $language,
            'duration_seconds' => (int) round($duration),
            'scene_count' => $pacing->sceneCount($duration),
            'target_spoken_words' => $pacing->targetWordCount($duration),
            'scene_guidance' => $customPoints,
        ];

        return 'You are a careful short-form video script editor. Do not promise virality or invent facts. '
            .'Generate a JSON object with headline (max 8 words), hook (max 10 spoken words), exactly '.$context['scene_count'].' scenes (each object has text, caption, emphasis, duration), '
            .'cta (short imperative), caption, and hashtags (4-7 general tags). The spoken script should be about '.$context['target_spoken_words'].' words in total and fit the requested duration at a natural pace. '
            .'Each scene caption must exactly match its spoken text so overlays and narration stay synchronized. Use scene_guidance as topic direction, not as a replacement for the requested scenes. '
            .'When opening_hook is provided, use it verbatim as the opening hook. Each scene teaches one concrete idea and does not repeat the hook. '
            .'Keep every sentence accurate and specific. Output only valid JSON. Context: '
            .json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return list<array{text:string,caption:string}> */
    private function normalizeScenes(mixed $rawScenes, int $limit): array
    {
        if (! is_array($rawScenes)) {
            return [];
        }

        $scenes = [];
        foreach ($rawScenes as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $kind = strtolower($this->firstString($scene, ['kind', 'type']));
            if (in_array($kind, ['hook', 'intro', 'cta', 'outro'], true)) {
                continue;
            }

            $text = $this->firstString($scene, ['text', 'line', 'headline']);
            if ($text === '') {
                continue;
            }

            $scenes[] = [
                'text' => $text,
                'caption' => $this->firstString($scene, ['caption', 'subtitle']) ?: $text,
            ];
            if (count($scenes) >= $limit) {
                break;
            }
        }

        return $scenes;
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_array($value)) {
                $value = implode(' ', $this->normalizeStrings($value));
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /** @param array<int, mixed> $values @return list<string> */
    private function normalizeStrings(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /** @param list<string> $points @param list<array{text:string,caption:string}> $scenes */
    private function script(string $hook, array $points, array $scenes, string $language, ?string $cta = null): string
    {
        $parts = [trim($hook)];
        foreach ($scenes as $scene) {
            $parts[] = trim($scene['caption'] ?: $scene['text']);
        }
        if ($scenes === []) {
            array_push($parts, ...$points);
        }
        $parts[] = $cta ?? ($language === 'en' ? 'Save this for later' : 'احفظ هذا وارجع له لاحقاً');

        return implode(' ', array_values(array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    /**
     * @param  list<string>  $points
     * @return array{script:string,headline:string,points:list<string>,cta:string,caption:string,hashtags:string,content_source:string,ai_model:string|null}
     */
    private function result(string $script, string $headline, array $points, string $cta, string $caption, string $hashtags, string $source, ?string $model): array
    {
        return [
            'script' => $script,
            'headline' => $headline,
            'points' => $points,
            'cta' => $cta,
            'caption' => $caption,
            'hashtags' => $hashtags,
            'content_source' => $source,
            'ai_model' => $model,
        ];
    }

    private function geminiFailure(RuntimeException $exception, string $language): RenderFailedException
    {
        $message = $exception->getMessage();
        [$stage, $retryable] = match (true) {
            str_contains($message, 'API key is required') => ['gemini_required', false],
            str_contains($message, 'HTTP 401') => ['gemini_key_invalid', false],
            str_contains($message, 'HTTP 403') => ['gemini_restricted', false],
            str_contains($message, 'HTTP 429') => ['gemini_quota', true],
            preg_match('/HTTP 5\d{2}/', $message) === 1 => ['gemini_unavailable', true],
            str_contains(strtolower($message), 'network') => ['provider_unreachable', true],
            default => ['gemini_failed', true],
        };

        return $this->failure($stage, $retryable, $language, $message);
    }

    private function failure(string $stage, bool $retryable, string $language, string $logMessage = ''): RenderFailedException
    {
        return new RenderFailedException(
            stage: $stage,
            retryable: $retryable,
            userMessage: trans('studio.errors.'.$stage, [], $language),
            logMessage: $logMessage,
        );
    }
}
