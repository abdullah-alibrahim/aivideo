<?php

namespace App\Services;

use App\Models\ProviderApiKey;
use Illuminate\Support\Facades\DB;

class ProviderKeyRing
{
    public function activeValues(string $provider): array
    {
        return $this->usableKeys($provider)
            ->orderBy('use_count')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'secret'])
            ->map(static fn (ProviderApiKey $key): string => trim((string) $key->secret))
            ->filter()
            ->values()
            ->all();
    }

    public function rotatedValues(string $provider): array
    {
        return DB::transaction(function () use ($provider): array {
            $keys = $this->usableKeys($provider)
                ->orderBy('use_count')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'secret', 'use_count']);

            if ($keys->isEmpty()) {
                return [];
            }

            $next = $keys->first();
            $next->forceFill([
                'use_count' => $next->use_count + 1,
                'last_used_at' => now(),
            ])->save();

            return $keys
                ->map(static fn (ProviderApiKey $key): string => trim((string) $key->secret))
                ->filter()
                ->values()
                ->all();
        });
    }

    private function usableKeys(string $provider)
    {
        return ProviderApiKey::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', now());
            });
    }
}
