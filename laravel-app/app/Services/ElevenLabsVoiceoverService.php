<?php

namespace App\Services;

use App\Exceptions\RenderFailedException;
use App\Models\ProviderApiKey;
use App\Models\ProviderSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ElevenLabsVoiceoverService
{
    private const VOICES_CACHE_KEY = 'studio.elevenlabs.voices';

    public function __construct(private readonly ProviderKeyRing $keyRing) {}

    /**
     * @param  array<string, mixed>  $voiceSettings
     */
    public function generate(string $text, string $language, string $tone, string $outputPath, ?string $voiceId = null, array $voiceSettings = []): void
    {
        $this->request($text, $language, $tone, $outputPath, false, $voiceId, $voiceSettings);
    }

    /**
     * Generate the narration and return per-word timings so captions can be
     * synchronised with what the voice actually says.
     *
     * @param  array<string, mixed>  $voiceSettings
     * @return list<array{text:string,start:float,end:float}>
     */
    public function generateWithTimestamps(string $text, string $language, string $tone, string $outputPath, ?string $voiceId = null, array $voiceSettings = []): array
    {
        $alignment = $this->request($text, $language, $tone, $outputPath, true, $voiceId, $voiceSettings);

        return $this->wordsFromAlignment($alignment);
    }

    /**
     * Fetch the account's voices so the studio can offer a picker. Successful
     * results are cached; failures are retried on the next request.
     *
     * @return list<array{id:string,name:string,language:?string,labels:array<string,string>,preview_url:?string}>
     */
    public function voices(): array
    {
        $cached = Cache::get(self::VOICES_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $keys = $this->keys();
        if ($keys === []) {
            return [];
        }

        foreach ($keys as $key) {
            try {
                $response = Http::withHeaders(['xi-api-key' => $key])
                    ->accept('application/json')
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->get('https://api.elevenlabs.io/v1/voices');
            } catch (ConnectionException) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $raw = $response->json('voices');
            if (! is_array($raw)) {
                continue;
            }

            $voices = array_values(array_filter(array_map(function (mixed $voice): ?array {
                if (! is_array($voice) || ! is_string($voice['voice_id'] ?? null) || trim($voice['voice_id']) === '') {
                    return null;
                }

                $labels = [];
                foreach ((array) ($voice['labels'] ?? []) as $label => $value) {
                    if (is_string($label) && is_scalar($value) && trim((string) $value) !== '') {
                        $labels[$label] = (string) $value;
                    }
                }

                return [
                    'id' => trim($voice['voice_id']),
                    'name' => trim((string) ($voice['name'] ?? '')) ?: 'Voice',
                    'language' => $this->voiceLanguage($labels),
                    'labels' => $labels,
                    'preview_url' => is_string($voice['preview_url'] ?? null) ? $voice['preview_url'] : null,
                ];
            }, $raw)));

            $this->keyRing->recordSuccess('elevenlabs', $key);
            if ($voices !== []) {
                Cache::put(self::VOICES_CACHE_KEY, $voices, now()->addHours(6));
            }

            return $voices;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $voiceSettings
     * @return array{characters?:array<int, string>, character_start_times_seconds?:array<int, float|int>, character_end_times_seconds?:array<int, float|int>}|null
     */
    private function request(string $text, string $language, string $tone, string $outputPath, bool $withTimestamps, ?string $voiceId = null, array $voiceSettings = []): ?array
    {
        $voiceSetting = ProviderSetting::query()
            ->where('name', 'elevenlabs.voice_id.'.$language)
            ->first();
        $configuredVoiceId = trim((string) ($voiceSetting?->value ?: config("services.elevenlabs.voice_ids.$language", '')));
        $voiceId = trim((string) $voiceId) !== '' ? trim((string) $voiceId) : $configuredVoiceId;
        $keys = $this->keys();

        if (trim($text) === '' || $voiceId === '') {
            throw $this->failure('voiceover_required', false, $language);
        }
        if ($keys === []) {
            $configuredKeysInCooldown = ProviderApiKey::query()
                ->where('provider', 'elevenlabs')
                ->where('is_active', true)
                ->exists();
            $stage = $configuredKeysInCooldown ? 'voiceover_unavailable' : 'voiceover_required';
            throw $this->failure($stage, $configuredKeysInCooldown, $language);
        }

        $presets = match ($tone) {
            'calm' => ['stability' => 0.62, 'style' => 0.06],
            'warm' => ['stability' => 0.5, 'style' => 0.22],
            'energetic' => ['stability' => 0.38, 'style' => 0.36],
            default => ['stability' => 0.52, 'style' => 0.14],
        };
        $overrides = $voiceSettings;
        $voiceSettings = [
            'stability' => $this->ratio($overrides['stability'] ?? null, $presets['stability']),
            'similarity_boost' => $this->ratio($overrides['similarity'] ?? null, 0.78),
            'style' => $this->ratio($overrides['style'] ?? null, $presets['style']),
            'use_speaker_boost' => array_key_exists('speaker_boost', $overrides) ? (bool) $overrides['speaker_boost'] : true,
        ];
        if (is_numeric($overrides['speed'] ?? null) && abs((float) $overrides['speed'] - 1.0) > 0.001) {
            $voiceSettings['speed'] = round(max(0.7, min(1.2, (float) $overrides['speed'])), 2);
        }

        $endpoint = $withTimestamps ? '/with-timestamps' : '';
        $url = 'https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($voiceId).$endpoint.'?output_format=mp3_44100_128';
        $lastStatus = null;
        $lastFailureMessage = '';

        foreach ($keys as $key) {
            try {
                $response = Http::withHeaders(['xi-api-key' => $key])
                    ->accept($withTimestamps ? 'application/json' : 'audio/mpeg')
                    ->connectTimeout(5)
                    ->timeout(45)
                    ->post($url, [
                        'text' => trim($text),
                        'model_id' => 'eleven_multilingual_v2',
                        'voice_settings' => $voiceSettings,
                    ]);
            } catch (ConnectionException) {
                $this->keyRing->recordFailure('elevenlabs', $key, 'network unavailable');
                $lastFailureMessage = 'network unavailable';

                continue;
            }

            if (! $response->successful()) {
                $lastStatus = $response->status();
                $lastFailureMessage = 'HTTP '.$lastStatus;
                $this->keyRing->recordFailure('elevenlabs', $key, $lastFailureMessage);

                if (! in_array($lastStatus, [401, 402, 403, 429, 500, 502, 503, 504], true)) {
                    throw $this->failure('voiceover_failed', false, $language, $lastFailureMessage);
                }

                continue;
            }

            $alignment = null;
            if ($withTimestamps) {
                $payload = $response->json();
                $audio = base64_decode((string) ($payload['audio_base64'] ?? ''), true);
                $alignment = $payload['normalized_alignment'] ?? $payload['alignment'] ?? null;
                if (! is_string($audio) || $audio === '') {
                    $this->keyRing->recordFailure('elevenlabs', $key, 'invalid provider response');
                    $lastFailureMessage = 'invalid provider response';

                    continue;
                }
                $alignment = is_array($alignment) ? $alignment : null;
            } else {
                $contentType = strtolower((string) $response->header('Content-Type'));
                $audio = $response->body();
                if ($audio === '' || (! str_starts_with($contentType, 'audio/') && ! str_contains($contentType, 'application/octet-stream'))) {
                    $this->keyRing->recordFailure('elevenlabs', $key, 'invalid provider response');
                    $lastFailureMessage = 'invalid provider response';

                    continue;
                }
            }

            $directory = dirname($outputPath);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw $this->failure('voiceover_failed', false, $language, 'Unable to create narration output directory.');
            }

            $bytesWritten = file_put_contents($outputPath, $audio);
            if ($bytesWritten !== strlen($audio)) {
                @unlink($outputPath);
                throw $this->failure('voiceover_failed', false, $language, 'Unable to write the narration audio.');
            }

            $this->keyRing->recordSuccess('elevenlabs', $key);

            return $alignment;
        }

        $configuredKeysInCooldown = ProviderApiKey::query()
            ->where('provider', 'elevenlabs')
            ->where('is_active', true)
            ->exists();
        $retryable = $lastStatus === 429
            || ($lastStatus !== null && $lastStatus >= 500)
            || $lastFailureMessage === 'network unavailable'
            || $lastFailureMessage === 'invalid provider response'
            || ($keys === [] && $configuredKeysInCooldown);

        $stage = $retryable ? 'voiceover_unavailable' : 'voiceover_failed';
        throw $this->failure($stage, $retryable, $language, $lastFailureMessage);
    }

    /**
     * Convert ElevenLabs character alignment into word timings.
     *
     * @param  array<string, mixed>|null  $alignment
     * @return list<array{text:string,start:float,end:float}>
     */
    private function wordsFromAlignment(?array $alignment): array
    {
        if ($alignment === null) {
            return [];
        }

        $characters = $alignment['characters'] ?? null;
        $starts = $alignment['character_start_times_seconds'] ?? null;
        $ends = $alignment['character_end_times_seconds'] ?? null;
        if (! is_array($characters) || ! is_array($starts) || ! is_array($ends)) {
            return [];
        }

        $count = min(count($characters), count($starts), count($ends));
        $words = [];
        $buffer = '';
        $wordStart = null;
        $wordEnd = null;

        $flush = static function () use (&$words, &$buffer, &$wordStart, &$wordEnd): void {
            $text = trim($buffer);
            if ($text !== '' && $wordStart !== null && $wordEnd !== null && $wordEnd > $wordStart) {
                $words[] = ['text' => $text, 'start' => (float) $wordStart, 'end' => (float) $wordEnd];
            }
            $buffer = '';
            $wordStart = null;
            $wordEnd = null;
        };

        for ($index = 0; $index < $count; $index++) {
            $character = (string) $characters[$index];
            if (trim($character) === '') {
                $flush();

                continue;
            }
            if ($wordStart === null) {
                $wordStart = (float) $starts[$index];
            }
            $wordEnd = (float) $ends[$index];
            $buffer .= $character;
        }
        $flush();

        return $words;
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $fallbackKey = trim((string) config('services.elevenlabs.api_key', ''));

        return array_values(array_unique(array_filter(
            array_merge($this->keyRing->rotatedValues('elevenlabs'), [$fallbackKey]),
            static fn (string $key): bool => $key !== '',
        )));
    }

    private function ratio(mixed $value, float $default): float
    {
        return is_numeric($value) ? round(max(0.0, min(1.0, (float) $value)), 2) : $default;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function voiceLanguage(array $labels): ?string
    {
        $text = mb_strtolower(implode(' ', $labels));
        if (preg_match('/\b(ar|arabic|arab)\b/u', $text) === 1) {
            return 'ar';
        }
        if (preg_match('/\b(en|english|american|british|australian|canadian|irish|scottish)\b/u', $text) === 1) {
            return 'en';
        }

        return null;
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
