<?php

namespace App\Services;

use App\Support\StudioPaths;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Fallback store used on Linux and in tests: one encrypted file per ref under
 * the keys/ folder inside the user-data root. Backups exclude that folder.
 */
class EncryptedFileStore implements SecretStore
{
    private const FILE_SUFFIX = '.secret';

    public function store(string $ref, string $secret): void
    {
        $path = $this->pathFor($ref);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, Crypt::encryptString($secret));
        @chmod($path, 0600);
    }

    public function read(string $ref): ?string
    {
        $path = $this->pathFor($ref);

        if (! is_file($path)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) file_get_contents($path));
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string $ref): bool
    {
        $path = $this->pathFor($ref);

        if (! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    private function pathFor(string $ref): string
    {
        $name = str_replace(['/', '\\', ':'], '__', $ref);

        return StudioPaths::keys().'/'.$name.self::FILE_SUFFIX;
    }
}
