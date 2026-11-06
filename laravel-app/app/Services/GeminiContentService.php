<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiContentService
{
    public function __construct(private readonly ProviderKeyRing $keyRing)
    {
    }

    /** @return array{data: array, model: string} */
    public function generate(string $prompt): array
    {
        $configured = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $configured = is_array($configured) ? $configured : [];
        $keys = array_values(array_unique(array_filter(array_merge(
            $this->keyRing->rotatedValues('gemini'),
            $configured,
            [(string) env('GEMINI_API_KEY', ''), (string) env('GOOGLE_API_KEY', '')],
        ), static fn ($key): bool => is_string($key) && trim($key) !== '')));

        if ($keys === []) {
            throw new RuntimeException('Gemini API key is required.');
        }

        $lastError = null;
        foreach ($keys as $key) {
            foreach (['gemini-2.5-flash', 'gemini-3-flash-preview', 'gemini-3.5-flash'] as $model) {
                try {
                    $response = Http::acceptJson()
                        ->connectTimeout(5)
                        ->timeout(45)
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".urlencode($key), [
                            'contents' => [['parts' => [['text' => $prompt]]]],
                            'generationConfig' => [
                                'temperature' => 0.7,
                                'responseMimeType' => 'application/json',
                            ],
                        ]);

                    if (! $response->successful()) {
                        throw new RuntimeException('Gemini HTTP '.$response->status());
                    }

                    $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
                    $data = json_decode(trim($text), true);
                    if (is_array($data)) {
                        return ['data' => $data, 'model' => $model];
                    }
                    throw new RuntimeException('Gemini returned invalid JSON.');
                } catch (\Throwable $exception) {
                    $lastError = $exception;
                }
            }
        }

        throw new RuntimeException('Gemini generation failed for all configured keys.'.($lastError ? ' '.$lastError->getMessage() : ''));
    }
}
