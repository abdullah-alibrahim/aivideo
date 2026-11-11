<?php

namespace App\Services;

/**
 * A single secret slot (one provider API key) addressed by a stable ref such
 * as "gemini/<uuid>". Implementations keep the plaintext out of the database.
 */
interface SecretStore
{
    /** Persist the secret for the given ref, replacing any existing value. */
    public function store(string $ref, string $secret): void;

    /** Read the secret or null when the ref is unknown. */
    public function read(string $ref): ?string;

    /** Remove the secret. Returns true when something was deleted. */
    public function delete(string $ref): bool;
}
