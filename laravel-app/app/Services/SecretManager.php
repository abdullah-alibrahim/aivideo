<?php

namespace App\Services;

use App\Models\ProviderApiKey;
use App\Support\StudioPaths;
use Illuminate\Support\Str;

/**
 * Picks the OS secret store and owns cross-store operations such as migrating
 * legacy database secrets and purging every secret on the machine.
 */
class SecretManager
{
    public function __construct(private readonly SecretStore $store) {}

    /** The SecretStore implementation chosen by config / OS family. */
    public static function defaultStoreClass(): string
    {
        return match (config('studio.secrets.store') ?: self::defaultStoreName()) {
            'keychain' => MacKeychainStore::class,
            'credential_manager' => WindowsCredentialStore::class,
            default => EncryptedFileStore::class,
        };
    }

    public function store(): SecretStore
    {
        return $this->store;
    }

    public function read(string $ref): ?string
    {
        if (trim($ref) === '') {
            return null;
        }

        return $this->store->read($ref);
    }

    public function write(string $ref, string $secret): void
    {
        $this->store->store($ref, $secret);
    }

    public function delete(string $ref): void
    {
        $this->store->delete($ref);
    }

    /**
     * Remove every known secret: all refs recorded in the database plus any
     * orphaned legacy entries. Returns how many secrets were deleted.
     */
    public function deleteAll(): int
    {
        $deleted = 0;

        ProviderApiKey::query()->get()->each(function (ProviderApiKey $key) use (&$deleted): void {
            if (filled($key->secret_ref) && $this->store->delete((string) $key->secret_ref)) {
                $deleted++;
            }
        });

        // Also sweep the fallback store folder so orphaned secrets go too.
        if ($this->store instanceof EncryptedFileStore) {
            $keysDirectory = StudioPaths::keys();
            foreach (glob($keysDirectory.'/*.secret') ?: [] as $file) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Move a legacy database secret into the OS secret store. Returns the new
     * ref, or null when there was nothing to migrate or the store failed.
     */
    public function migrateLegacySecret(ProviderApiKey $key): ?string
    {
        $legacy = (string) $key->secret;

        if ($legacy === '' || filled($key->secret_ref)) {
            return null;
        }

        $ref = $key->secret_ref ?: $key->provider.'/'.(string) Str::uuid();

        try {
            $this->store->store($ref, $legacy);
        } catch (Throwable) {
            return null;
        }

        $key->forceFill(['secret_ref' => $ref, 'secret' => null])->save();

        return $ref;
    }

    private static function defaultStoreName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'keychain',
            'Windows' => 'credential_manager',
            default => 'encrypted_file',
        };
    }
}
