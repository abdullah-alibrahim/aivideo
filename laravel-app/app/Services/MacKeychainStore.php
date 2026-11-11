<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Stores secrets in the macOS login Keychain via the /usr/bin/security CLI.
 * Entry: service "AI Reels Studio", account "<ref>".
 */
class MacKeychainStore implements SecretStore
{
    private const SERVICE_NAME = 'AI Reels Studio';

    public function store(string $ref, string $secret): void
    {
        // -U updates the entry when it already exists.
        $this->run(['/usr/bin/security', 'add-generic-password', '-s', self::SERVICE_NAME, '-a', $ref, '-w', $secret, '-U']);
    }

    public function read(string $ref): ?string
    {
        $output = $this->run(['/usr/bin/security', 'find-generic-password', '-s', self::SERVICE_NAME, '-a', $ref, '-w'], false);

        if ($output === null) {
            return null;
        }

        $secret = trim($output);

        return $secret === '' ? null : $secret;
    }

    public function delete(string $ref): bool
    {
        return $this->run(['/usr/bin/security', 'delete-generic-password', '-s', self::SERVICE_NAME, '-a', $ref], false) !== null;
    }

    private function run(array $command, bool $mustSucceed = true): ?string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(10);
            $process->run();

            if ($mustSucceed && ! $process->isSuccessful()) {
                throw new \RuntimeException('Keychain operation failed: '.trim($process->getErrorOutput()));
            }

            return $process->getOutput();
        } catch (Throwable $exception) {
            if ($mustSucceed) {
                throw $exception;
            }

            return null;
        }
    }
}
