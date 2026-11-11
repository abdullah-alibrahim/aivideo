<?php

namespace App\Models;

use App\Services\SecretManager;
use Illuminate\Database\Eloquent\Model;

class ProviderApiKey extends Model
{
    protected $fillable = [
        'provider',
        'label',
        'secret',
        'secret_ref',
        'is_active',
        'sort_order',
        'use_count',
        'error_count',
        'health_status',
        'last_tested_at',
        'last_success_at',
        'last_used_at',
        'last_error',
        'cooldown_until',
    ];

    protected $hidden = [
        'secret',
        'secret_ref',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'use_count' => 'integer',
        'error_count' => 'integer',
        'last_tested_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_used_at' => 'datetime',
        'cooldown_until' => 'datetime',
    ];

    /**
     * The usable secret: legacy encrypted DB value when present, otherwise the
     * OS secret store entry behind secret_ref. Legacy values migrate lazily so
     * the database stops holding provider secrets.
     */
    public function resolveSecret(): string
    {
        $legacy = (string) $this->getRawOriginal('secret');

        if ($legacy !== '') {
            $migrated = app(SecretManager::class)->migrateLegacySecret($this);

            if ($migrated !== null) {
                $this->refresh();

                return (string) app(SecretManager::class)->read((string) $this->secret_ref);
            }

            return trim($this->secret);
        }

        return (string) app(SecretManager::class)->read((string) $this->secret_ref);
    }

    public function getMaskedSecretAttribute(): string
    {
        $secret = $this->resolveSecret();

        return $secret === '' ? '' : '••••••••'.substr($secret, -4);
    }

    public function getIsUsableAttribute(): bool
    {
        return $this->is_active && (! $this->cooldown_until || $this->cooldown_until->lessThanOrEqualTo(now()));
    }
}
