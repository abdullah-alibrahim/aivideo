<?php

namespace Tests\Feature;

use App\Exceptions\RenderFailedException;
use App\Models\ProviderApiKey;
use App\Models\ProviderSetting;
use App\Services\ElevenLabsVoiceoverService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsVoiceoverTest extends TestCase
{
    public function test_voiceover_writes_provider_audio_and_records_key_health(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $key = $this->createKey('elevenlabs-primary');
        $this->setVoiceId();
        $outputPath = tempnam(sys_get_temp_dir(), 'elevenlabs-') ?: throw new \RuntimeException('Unable to allocate test output.');
        unlink($outputPath);

        try {
            app(ElevenLabsVoiceoverService::class)->generate('A short narration.', 'en', 'warm', $outputPath);

            $this->assertSame('audio-bytes', file_get_contents($outputPath));
            $this->assertSame('healthy', $key->fresh()->health_status);
            Http::assertSent(static fn (HttpRequest $request): bool => $request->method() === 'POST'
                && str_contains($request->url(), '/v1/text-to-speech/voice_123?output_format=mp3_44100_128')
                && $request->hasHeader('xi-api-key', 'elevenlabs-primary')
                && data_get($request->data(), 'text') === 'A short narration.'
                && data_get($request->data(), 'voice_settings.style') === 0.22);
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_voiceover_requires_an_active_server_key_and_voice_id(): void
    {
        try {
            app(ElevenLabsVoiceoverService::class)->generate('A short narration.', 'en', 'neutral', '/tmp/unused-voice.mp3');
            $this->fail('Expected missing provider configuration to fail.');
        } catch (RenderFailedException $exception) {
            $this->assertSame('voiceover_required', $exception->stage);
            $this->assertFalse($exception->retryable);
        }
    }

    public function test_rate_limited_key_is_cooled_down_before_a_backup_key_succeeds(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::sequence()
                ->push([], 429)
                ->push('backup-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $primary = $this->createKey('elevenlabs-primary', 0);
        $backup = $this->createKey('elevenlabs-backup', 1);
        $this->setVoiceId();
        $outputPath = tempnam(sys_get_temp_dir(), 'elevenlabs-') ?: throw new \RuntimeException('Unable to allocate test output.');
        unlink($outputPath);

        try {
            app(ElevenLabsVoiceoverService::class)->generate('A short narration.', 'en', 'neutral', $outputPath);

            $this->assertSame('backup-audio', file_get_contents($outputPath));
            $this->assertSame('cooldown', $primary->fresh()->health_status);
            $this->assertNotNull($primary->fresh()->cooldown_until);
            $this->assertSame('healthy', $backup->fresh()->health_status);
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_non_retryable_provider_error_is_reported_without_publishing_audio(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response([], 400),
        ]);
        $key = $this->createKey('elevenlabs-primary');
        $this->setVoiceId();
        $outputPath = tempnam(sys_get_temp_dir(), 'elevenlabs-') ?: throw new \RuntimeException('Unable to allocate test output.');
        unlink($outputPath);

        try {
            try {
                app(ElevenLabsVoiceoverService::class)->generate('A short narration.', 'en', 'neutral', $outputPath);
                $this->fail('Expected provider validation failure.');
            } catch (RenderFailedException $exception) {
                $this->assertSame('voiceover_failed', $exception->stage);
                $this->assertFalse($exception->retryable);
            }

            $this->assertFileDoesNotExist($outputPath);
            $this->assertSame('failed', $key->fresh()->health_status);
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_timestamps_request_returns_word_timings_and_writes_the_audio(): void
    {
        Http::preventStrayRequests();
        $text = 'Hello brave world';
        $characters = str_split($text);
        $starts = [];
        $ends = [];
        foreach ($characters as $index => $character) {
            $starts[] = $index * 0.1;
            $ends[] = ($index + 1) * 0.1;
        }
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*/with-timestamps*' => Http::response([
                'audio_base64' => base64_encode('timestamped-audio'),
                'normalized_alignment' => [
                    'characters' => $characters,
                    'character_start_times_seconds' => $starts,
                    'character_end_times_seconds' => $ends,
                ],
            ], 200),
        ]);
        $this->createKey('elevenlabs-primary');
        $this->setVoiceId();
        $outputPath = tempnam(sys_get_temp_dir(), 'elevenlabs-') ?: throw new \RuntimeException('Unable to allocate test output.');
        unlink($outputPath);

        try {
            $words = app(ElevenLabsVoiceoverService::class)
                ->generateWithTimestamps($text, 'en', 'neutral', $outputPath);

            $this->assertSame('timestamped-audio', file_get_contents($outputPath));
            $this->assertCount(3, $words);
            $this->assertSame('Hello', $words[0]['text']);
            $this->assertEqualsWithDelta(0.0, $words[0]['start'], 0.001);
            $this->assertEqualsWithDelta(0.5, $words[0]['end'], 0.001);
            $this->assertSame('world', $words[2]['text']);
            $this->assertEqualsWithDelta(1.2, $words[2]['start'], 0.001);
            $this->assertEqualsWithDelta(1.7, $words[2]['end'], 0.001);
            Http::assertSent(static fn (HttpRequest $request): bool => str_contains($request->url(), '/with-timestamps?output_format=mp3_44100_128'));
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_selected_voice_and_advanced_settings_are_forwarded_to_the_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $this->createKey('elevenlabs-primary');
        $outputPath = tempnam(sys_get_temp_dir(), 'elevenlabs-') ?: throw new \RuntimeException('Unable to allocate test output.');
        unlink($outputPath);

        try {
            app(ElevenLabsVoiceoverService::class)->generate('مرحباً بكم', 'ar', 'energetic', $outputPath, 'voice_arabic_01', [
                'stability' => 0.9,
                'similarity' => 0.6,
                'style' => 0.1,
                'speed' => 1.1,
                'speaker_boost' => false,
            ]);

            $this->assertSame('audio-bytes', file_get_contents($outputPath));
            Http::assertSent(static fn (HttpRequest $request): bool => str_contains($request->url(), '/v1/text-to-speech/voice_arabic_01')
                && data_get($request->data(), 'voice_settings.stability') === 0.9
                && data_get($request->data(), 'voice_settings.similarity_boost') === 0.6
                && data_get($request->data(), 'voice_settings.style') === 0.1
                && data_get($request->data(), 'voice_settings.speed') === 1.1
                && data_get($request->data(), 'voice_settings.use_speaker_boost') === false);
        } finally {
            @unlink($outputPath);
        }
    }

    public function test_voice_catalog_is_fetched_once_and_mapped_with_languages(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    ['voice_id' => 'voice_salma', 'name' => 'Salma', 'labels' => ['language' => 'ar', 'accent' => 'egyptian'], 'preview_url' => 'https://example.test/salma.mp3'],
                    ['voice_id' => 'voice_adam', 'name' => 'Adam', 'labels' => ['accent' => 'american'], 'preview_url' => null],
                    ['voice_id' => 'voice_other', 'name' => 'Multilingual', 'labels' => [], 'preview_url' => null],
                ],
            ], 200),
        ]);
        $this->createKey('elevenlabs-primary');
        $service = app(ElevenLabsVoiceoverService::class);

        $voices = $service->voices();
        $service->voices();

        $this->assertCount(3, $voices);
        $this->assertSame('ar', $voices[0]['language']);
        $this->assertSame('en', $voices[1]['language']);
        $this->assertNull($voices[2]['language']);
        $this->assertSame('https://example.test/salma.mp3', $voices[0]['preview_url']);
        Http::assertSentCount(1);
    }

    public function test_voice_catalog_returns_no_voices_without_configuration(): void
    {
        Http::preventStrayRequests();

        $this->assertSame([], app(ElevenLabsVoiceoverService::class)->voices());
    }

    private function createKey(string $secret, int $sortOrder = 0): ProviderApiKey
    {
        return ProviderApiKey::query()->create([
            'provider' => 'elevenlabs',
            'label' => $secret,
            'secret' => $secret,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private function setVoiceId(): void
    {
        ProviderSetting::query()->create([
            'name' => 'elevenlabs.voice_id.en',
            'value' => 'voice_123',
        ]);
    }
}
