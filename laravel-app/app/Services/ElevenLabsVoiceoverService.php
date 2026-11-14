<?php

namespace App\Services;

use App\Exceptions\RenderFailedException;
use App\Models\ProviderApiKey;
use App\Models\ProviderSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ElevenLabsVoiceoverService
{
    public function __construct(private readonly ProviderKeyRing $keyRing) {}

    public function generate(string $text, string $language, string $tone, string $outputPath): void
    {
        $voiceSetting = ProviderSetting::query()
            ->where('name', 'elevenlabs.voice_id.'.$language)
            ->first();
        $voiceId = trim((string) ($voiceSetting?->value ?: config("services.elevenlabs.voice_ids.$language", '')));
        $fallbackKey = trim((string) config('services.elevenlabs.api_key', ''));
        $keys = array_values(array_unique(array_filter(
            array_merge($this->keyRing->rotatedValues('elevenlabs'), [$fallbackKey]),
            static fn (string $key): bool => $key !== '',
        )));

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

        $settings = match ($tone) {
            'calm' => ['stability' => 0.62, 'style' => 0.06],
            'warm' => ['stability' => 0.5, 'style' => 0.22],
            'energetic' => ['stability' => 0.38, 'style' => 0.36],
            default => ['stability' => 0.52, 'style' => 0.14],
        };
        $url = 'https://api.elevenlabs.io/v1/text-to-speech/'.rawurlencode($voiceId).'?output_format=mp3_44100_128';
        $lastStatus = null;
        $lastFailureMessage = '';

        foreach ($keys as $key) {
            try {
                $response = Http::withHeaders(['xi-api-key' => $key])
                    ->accept('audio/mpeg')
                    ->connectTimeout(5)
                    ->timeout(45)
                    ->post($url, [
                        'text' => trim($text),
                        'model_id' => 'eleven_multilingual_v2',
                        'voice_settings' => [
                            'stability' => $settings['stability'],
                            'similarity_boost' => 0.78,
                            'style' => $settings['style'],
                            'use_speaker_boost' => true,
                        ],
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

            $contentType = strtolower((string) $response->header('Content-Type'));
            $audio = $response->body();
            if ($audio === '' || (! str_starts_with($contentType, 'audio/') && ! str_contains($contentType, 'application/octet-stream'))) {
                $this->keyRing->recordFailure('elevenlabs', $key, 'invalid provider response');
                $lastFailureMessage = 'invalid provider response';

                continue;
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

            return;
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
