<?php

namespace Tests\Feature;

use App\Models\ProviderApiKey;
use App\Services\EncryptedFileStore;
use App\Services\SecretManager;
use App\Support\StudioPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecretStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'studio.data_dir' => sys_get_temp_dir().'/secret-store-test-'.uniqid(),
            'studio.secrets.store' => 'encrypted_file',
        ]);
        StudioPaths::ensure();
    }

    public function test_encrypted_file_store_roundtrips_and_never_stores_plaintext(): void
    {
        $store = new EncryptedFileStore;

        $store->store('gemini/test-ref', 'AIzaSecretValue123');

        $file = StudioPaths::keys().'/gemini__test-ref.secret';
        $this->assertFileExists($file);
        $this->assertStringNotContainsString('AIzaSecretValue123', (string) File::get($file));
        $this->assertSame('AIzaSecretValue123', $store->read('gemini/test-ref'));

        $store->delete('gemini/test-ref');
        $this->assertFileDoesNotExist($file);
        $this->assertNull($store->read('gemini/test-ref'));
    }

    public function test_secret_manager_uses_the_configured_store(): void
    {
        config(['studio.secrets.store' => 'encrypted_file']);

        $manager = app(SecretManager::class);
        $this->assertInstanceOf(EncryptedFileStore::class, $manager->store());

        $manager->store()->store('gemini/abc', 'SecretABC');
        $this->assertSame('SecretABC', $manager->read('gemini/abc'));

        $this->assertSame(1, $manager->deleteAll(['gemini/abc']));
        $this->assertNull($manager->read('gemini/abc'));
    }

    public function test_provider_keys_use_the_secret_store_and_the_db_stays_secret_free(): void
    {
        $key = ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'label' => 'Stored via ref',
            'secret_ref' => 'gemini/'.uniqid(),
            'is_active' => true,
        ]);

        app(SecretManager::class)->store()->store($key->secret_ref, 'AIzaRefStoredKey999');
        $key->refresh();

        $this->assertSame('AIzaRefStoredKey999', $key->resolveSecret());
        $this->assertSame('••••••••y999', $key->masked_secret);
        $this->assertSame(null, $key->getRawOriginal('secret'));
    }

    public function test_legacy_plaintext_db_secrets_migrate_into_the_secret_store_on_first_read(): void
    {
        $key = ProviderApiKey::query()->create([
            'provider' => 'gemini',
            'label' => 'Legacy',
            'secret' => 'AIzaLegacyKeyFromDb777',
            'is_active' => true,
        ]);

        $this->assertSame('AIzaLegacyKeyFromDb777', $key->resolveSecret());

        $key->refresh();
        $this->assertNotNull($key->secret_ref);
        $this->assertSame('AIzaLegacyKeyFromDb777', app(SecretManager::class)->read((string) $key->secret_ref));
        $this->assertSame('', (string) $key->getRawOriginal('secret'));
    }

    public function test_purge_removes_every_secret_and_clears_key_records(): void
    {
        $key = ProviderApiKey::query()->create([
            'provider' => 'elevenlabs',
            'label' => 'Purge me',
            'secret_ref' => 'elevenlabs/purge-ref',
            'is_active' => true,
        ]);
        app(SecretManager::class)->store()->store((string) $key->secret_ref, 'eleven_purge_secret');

        $this->post(route('settings.provider-keys.purge', ['lang' => 'ar']))
            ->assertRedirect(route('settings.provider-keys', ['lang' => 'ar']));

        $this->assertDatabaseCount('provider_api_keys', 0);
        $this->assertNull(app(SecretManager::class)->read('elevenlabs/purge-ref'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(StudioPaths::root());

        parent::tearDown();
    }
}
