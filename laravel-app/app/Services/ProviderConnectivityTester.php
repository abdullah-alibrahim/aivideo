<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Performs real connectivity checks against Gemini and ElevenLabs APIs.
 */
class ProviderConnectivityTester
{
    private const GEMINI_TIMEOUT = 10;

    private const ELEVENLABS_TIMEOUT = 10;

    /** @return array{ok: bool, state: string, detail: string} */
    public function testGemini(string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return ['ok' => false, 'state' => 'missing_key', 'detail' => 'No Gemini key configured.'];
        }

        try {
            $response = Http::timeout(self::GEMINI_TIMEOUT)
                ->acceptJson()
                ->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $apiKey]);
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'state' => 'network', 'detail' => 'Could not reach Gemini: '.$exception->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'state' => 'connected', 'detail' => 'Gemini accepted this key.'];
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            $message = (string) data_get($response->json(), 'error.message', 'The key was rejected.');

            return ['ok' => false, 'state' => 'invalid_key', 'detail' => $message];
        }

        if ($response->status() === 429) {
            return ['ok' => false, 'state' => 'rate_limited', 'detail' => 'Gemini rate limit reached for this key.'];
        }

        return ['ok' => false, 'state' => 'error', 'detail' => 'Gemini responded with HTTP '.$response->status().'.'];
    }

    /** @return array{ok: bool, state: string, detail: string} */
    public function testElevenLabs(string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return ['ok' => false, 'state' => 'missing_key', 'detail' => 'No ElevenLabs key configured.'];
        }

        try {
            $response = Http::timeout(self::ELEVENLABS_TIMEOUT)
                ->acceptJson()
                ->withHeaders(['xi-api-key' => $apiKey])
                ->get('https://api.elevenlabs.io/v1/user');
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'state' => 'network', 'detail' => 'Could not reach ElevenLabs: '.$exception->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'state' => 'connected', 'detail' => 'ElevenLabs accepted this key.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            $message = (string) data_get($response->json(), 'detail.message', data_get($response->json(), 'detail.status', 'The key was rejected.'));

            return ['ok' => false, 'state' => 'invalid_key', 'detail' => $message];
        }

        if ($response->status() === 429) {
            return ['ok' => false, 'state' => 'rate_limited', 'detail' => 'ElevenLabs rate limit reached for this key.'];
        }

        return ['ok' => false, 'state' => 'error', 'detail' => 'ElevenLabs responded with HTTP '.$response->status().'.'];
    }
}
