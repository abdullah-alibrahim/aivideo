<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderApiKey extends Model
{
    protected $fillable = [
        'provider',
        'label',
        'secret',
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

    public function getMaskedSecretAttribute(): string
    {
        $secret = (string) $this->secret;

        return $secret === '' ? '' : '••••••••'.substr($secret, -4);
    }

    public function getIsUsableAttribute(): bool
    {
        return $this->is_active && (! $this->cooldown_until || $this->cooldown_until->lessThanOrEqualTo(now()));
    }
}
