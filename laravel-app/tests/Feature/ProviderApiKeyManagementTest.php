<?php

namespace Tests\Feature;

use App\Models\ProviderApiKey;
use App\Models\ProviderSetting;
use App\Services\ProviderKeyRing;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderApiKeyManagementTest extends TestCase
{
    public function test_multiple_gemini_and_elevenlabs_keys_are_encrypted_and_only_masked_in_the_screen(): void
    {
        $secrets = [
            ['gemini', 'Gemini work', 'AIzaKeyForWorkAccount01'],
            ['gemini', 'Gemini backup', 'AIzaKeyForBackupAccount02'],
            ['elevenlabs', 'Arabic voice', 'eleven_key_arabic_001'],
            ['elevenlabs', 'Backup voice', 'eleven_key_backup_002'],
        ];

        foreach ($secrets as [$provider, $label, $secret]) {
            $this->post(route('settings.provider-keys.store', ['lang' => 'ar']), [
                'provider' => $provider,
                'label' => $label,
                'secret' => $secret,
            ])->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']));
        }

        $this->assertDatabaseCount('provider_api_keys', 4);
        $firstKey = ProviderApiKey::query()->where('label', 'Gemini work')->firstOrFail();
        $storedSecret = DB::table('provider_api_keys')->where('id', $firstKey->id)->value('secret');

        $this->assertNotSame('AIzaKeyForWorkAccount01', $storedSecret);
        $this->assertSame('AIzaKeyForWorkAccount01', $firstKey->secret);

        $this->get(route('settings.provider-keys', ['lang' => 'ar']))
            ->assertOk()
            ->assertSee('Gemini work')
            ->assertSee('••••••••nt01')
            ->assertSee('••••••••_002')
            ->assertDontSee('AIzaKeyForWorkAccount01')
            ->assertDontSee('eleven_key_arabic_001');
    }

    public function test_duplicate_key_for_same_provider_is_rejected_without_flashing_the_secret(): void
    {
        ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'label' => 'Existing',
            'secret' => 'AIzaDuplicateKeyForTest',
            'is_active' => true,
        ]);

        $response = $this->from(route('settings.provider-keys', ['lang' => 'ar']))
            ->post(route('settings.provider-keys.store', ['lang' => 'ar']), [
                'provider' => 'gemini',
                'label' => 'Duplicate',
                'secret' => 'AIzaDuplicateKeyForTest',
            ]);

        $response->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']))
            ->assertSessionHasErrors('secret');
        $this->assertDatabaseCount('provider_api_keys', 1);
        $response->assertSessionHas('_old_input', static fn ($oldInput): bool => ! is_array($oldInput) || ! array_key_exists('secret', $oldInput));
    }

    public function test_key_can_be_disabled_and_deleted(): void
    {
        $key = ProviderApiKey::query()->create([
            'provider' => 'elevenlabs',
            'label' => 'Narration',
            'secret' => 'eleven_key_for_tests',
            'is_active' => true,
        ]);

        $this->patch(route('settings.provider-keys.toggle', ['providerApiKey' => $key->id, 'lang' => 'ar']))
            ->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']));
        $this->assertFalse($key->fresh()->is_active);

        $this->delete(route('settings.provider-keys.destroy', ['providerApiKey' => $key->id, 'lang' => 'ar']))
            ->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']));
        $this->assertDatabaseMissing('provider_api_keys', ['id' => $key->id]);
    }

    public function test_gemini_check_uses_model_listing_and_records_a_success_without_generation(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response(['models' => []], 200),
        ]);

        $key = ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'label' => 'Gemini test',
            'secret' => 'AIzaTestKeyForGeminiModels001',
            'is_active' => true,
        ]);

        $this->post(route('settings.provider-keys.test', ['providerApiKey' => $key->id, 'lang' => 'ar']))
            ->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']))
            ->assertSessionHas('success');

        $this->assertTrue($key->fresh()->is_active);
        $this->assertSame('healthy', $key->fresh()->health_status);
        $this->assertNotNull($key->fresh()->last_tested_at);
        $this->assertNotNull($key->fresh()->last_success_at);
        $this->assertNull($key->fresh()->last_error);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1beta/models'));
    }

    public function test_unauthorized_key_is_disabled_rate_limit_is_cooled_and_forbidden_scope_is_not_disabled(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models*' => Http::response([], 401),
            'api.elevenlabs.io/v1/user' => Http::sequence()
                ->push([], 429)
                ->push([], 403),
        ]);

        $invalid = ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'secret' => 'AIzaInvalidKeyExample00000001',
            'is_active' => true,
        ]);
        $rateLimited = ProviderApiKey::query()->create([
            'provider' => 'elevenlabs',
            'secret' => 'eleven_rate_limit_key',
            'is_active' => true,
        ]);
        $restricted = ProviderApiKey::query()->create([
            'provider' => 'elevenlabs',
            'secret' => 'eleven_scope_limited_key',
            'is_active' => true,
        ]);

        $this->post(route('settings.provider-keys.test', ['providerApiKey' => $invalid->id, 'lang' => 'ar']));
        $this->post(route('settings.provider-keys.test', ['providerApiKey' => $rateLimited->id, 'lang' => 'ar']));
        $this->post(route('settings.provider-keys.test', ['providerApiKey' => $restricted->id, 'lang' => 'ar']));

        $this->assertFalse($invalid->fresh()->is_active);
        $this->assertSame('failed', $invalid->fresh()->health_status);
        $this->assertStringNotContainsString('AIzaInvalidKeyExample00000001', $invalid->fresh()->last_error);
        $this->assertTrue($rateLimited->fresh()->is_active);
        $this->assertSame('cooldown', $rateLimited->fresh()->health_status);
        $this->assertNotNull($rateLimited->fresh()->cooldown_until);
        $this->assertTrue($restricted->fresh()->is_active);
        $this->assertSame('restricted', $restricted->fresh()->health_status);
        $this->assertNull($restricted->fresh()->cooldown_until);
    }

    public function test_voice_ids_can_be_saved_encrypted_and_key_label_and_order_can_be_changed(): void
    {
        config([
            'services.elevenlabs.api_key' => null,
            'services.elevenlabs.voice_ids.ar' => null,
            'services.elevenlabs.voice_ids.en' => null,
        ]);

        $key = ProviderApiKey::query()->create([
            'provider' => 'elevenlabs',
            'label' => 'Original label',
            'secret' => 'eleven_key_voice_configuration',
            'is_active' => true,
        ]);

        $this->patch(route('settings.provider-keys.update', ['providerApiKey' => $key->id, 'lang' => 'ar']), [
            'label' => 'Narration primary',
            'sort_order' => 7,
        ])->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']));

        $this->patch(route('settings.provider-keys.voice-ids', ['lang' => 'ar']), [
            'voice_id_ar' => 'voiceArabic_123',
            'voice_id_en' => 'voiceEnglish_456',
        ])->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']));

        $this->assertDatabaseHas('provider_api_keys', [
            'id' => $key->id,
            'label' => 'Narration primary',
            'sort_order' => 7,
        ]);
        $arabicVoice = ProviderSetting::query()->where('name', 'elevenlabs.voice_id.ar')->firstOrFail();
        $storedValue = DB::table('provider_settings')->where('name', 'elevenlabs.voice_id.ar')->value('value');
        $this->assertSame('voiceArabic_123', $arabicVoice->value);
        $this->assertNotSame('voiceArabic_123', $storedValue);

        $this->get(route('reels.wizard', ['lang' => 'ar']))
            ->assertOk()
            ->assertViewHas('voiceoverAvailable', ['ar' => true, 'en' => true]);
    }

    public function test_key_ring_rotates_least_used_keys_in_user_defined_order_and_skips_disabled_or_cooled_keys(): void
    {
        $first = ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'secret' => 'AIzaRotationFirstKey0000000001',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $second = ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'secret' => 'AIzaRotationSecondKey00000002',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'secret' => 'AIzaRotationDisabledKey0000003',
            'is_active' => false,
            'sort_order' => 2,
        ]);
        ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'secret' => 'AIzaRotationCoolingKey000000004',
            'is_active' => true,
            'cooldown_until' => now()->addHour(),
            'sort_order' => 3,
        ]);

        $ring = app(ProviderKeyRing::class);
        $this->assertSame($first->secret, $ring->rotatedValues('gemini')[0]);
        $this->assertSame($second->secret, $ring->rotatedValues('gemini')[0]);
        $this->assertSame($first->secret, $ring->rotatedValues('gemini')[0]);
        $this->assertSame(2, $first->fresh()->use_count);
        $this->assertSame(1, $second->fresh()->use_count);
    }
}
