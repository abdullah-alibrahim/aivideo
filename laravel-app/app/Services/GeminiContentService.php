<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiContentService
{
    public function __construct(private readonly ProviderKeyRing $keyRing) {}

    /** @return array{data: array, model: string} */
    public function generate(string $prompt): array
    {
        return $this->request([['text' => $prompt]], 0.7);
    }

    /**
     * Ask Gemini to read an image (screenshot or reference frame) and return
     * structured JSON. The image travels as inline base64 data so no public
     * URL is needed and CSP rules stay untouched.
     *
     * @return array{data: array, model: string}
     */
    public function generateFromImage(string $prompt, string $absolutePath, ?string $mimeType = null): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Gemini reference image is missing or unreadable.');
        }
        $contents = file_get_contents($absolutePath);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Gemini reference image could not be read.');
        }
        $mimeType = $mimeType ?: (mime_content_type($absolutePath) ?: 'image/jpeg');

        return $this->request([
            ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($contents)]],
            ['text' => $prompt],
        ], 0.4);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return array{data: array, model: string}
     */
    private function request(array $parts, float $temperature): array
    {
        $configured = json_decode((string) config('services.gemini.keys_json', '[]'), true);
        $configured = is_array($configured) ? $configured : [];
        $keys = array_values(array_unique(array_filter(array_merge(
            $this->keyRing->rotatedValues('gemini'),
            $configured,
            [(string) config('services.gemini.api_key', ''), (string) config('services.gemini.google_api_key', '')],
        ), static fn ($key): bool => is_string($key) && trim($key) !== '')));

        if ($keys === []) {
            throw new RuntimeException('Gemini API key is required.');
        }

        $lastError = null;
        foreach ($keys as $key) {
            foreach ((array) config('services.gemini.models', ['gemini-2.5-flash']) as $model) {
                try {
                    $response = Http::acceptJson()
                        ->connectTimeout(5)
                        ->timeout(45)
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".urlencode($key), [
                            'contents' => [['parts' => $parts]],
                            'generationConfig' => [
                                'temperature' => $temperature,
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
