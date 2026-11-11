<?php

namespace App\Services;

use App\Models\ProviderApiKey;
use Illuminate\Support\Facades\DB;

class ProviderKeyRing
{
    public function activeValues(string $provider): array
    {
        return $this->usableKeys($provider)
            ->orderBy('error_count')
            ->orderBy('use_count')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (ProviderApiKey $key): string => trim($key->resolveSecret()))
            ->filter()
            ->values()
            ->all();
    }

    public function rotatedValues(string $provider): array
    {
        return DB::transaction(function () use ($provider): array {
            $keys = $this->usableKeys($provider)
                ->orderBy('error_count')
                ->orderBy('use_count')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($keys->isEmpty()) {
                return [];
            }

            $next = $keys->first();
            $next->forceFill([
                'use_count' => $next->use_count + 1,
                'last_used_at' => now(),
            ])->save();

            return $keys
                ->map(static fn (ProviderApiKey $key): string => trim($key->resolveSecret()))
                ->filter()
                ->values()
                ->all();
        });
    }

    public function recordFailure(string $provider, string $secret, string $reason): void
    {
        $key = ProviderApiKey::query()
            ->where('provider', $provider)
            ->get()
            ->first(static fn (ProviderApiKey $candidate): bool => hash_equals($candidate->resolveSecret(), $secret));

        if (! $key) {
            return;
        }

        $status = null;
        if (preg_match('/\\b(401|403|429|5\\d\\d)\\b/', $reason, $matches)) {
            $status = (int) $matches[1];
        }

        $attributes = [
            'error_count' => $key->error_count + 1,
            'health_status' => 'failed',
            'last_error' => match ($status) {
                401 => 'Provider rejected this key (401).',
                403 => 'Provider access is restricted (403).',
                429 => 'Provider rate limit reached (429).',
                $status !== null && $status >= 500 => "Provider temporarily unavailable ({$status}).",
                str_contains(strtolower($reason), 'network') => 'Provider network connection failed.',
                default => 'The provider request failed.',
            },
        ];

        if ($status === 401) {
            $attributes['is_active'] = false;
        } elseif ($status === 403) {
            $attributes['health_status'] = 'restricted';
        } elseif ($status === 429) {
            $attributes['health_status'] = 'cooldown';
            $attributes['cooldown_until'] = now()->addMinutes(15);
        } elseif ($status !== null && $status >= 500) {
            $attributes['health_status'] = 'cooldown';
            $attributes['cooldown_until'] = now()->addMinutes(2);
        }

        $key->forceFill($attributes)->save();
    }

    public function recordSuccess(string $provider, string $secret): void
    {
        $key = ProviderApiKey::query()
            ->where('provider', $provider)
            ->get()
            ->first(static fn (ProviderApiKey $candidate): bool => hash_equals($candidate->resolveSecret(), $secret));

        $key?->forceFill([
            'health_status' => 'healthy',
            'last_success_at' => now(),
            'last_error' => null,
            'cooldown_until' => null,
        ])->save();
    }

    /** @param array<int, string|array{fingerprint:string,status?:int|null,reason?:string}> $failedFingerprints */
    public function recordRenderOutcomes(array $failedFingerprints, ?string $successFingerprint): void
    {
        $keys = ProviderApiKey::query()->where('provider', 'gemini')->get();
        $failed = [];
        foreach ($failedFingerprints as $failure) {
            if (is_string($failure)) {
                $failed[$failure] = ['status' => null, 'reason' => null];
            } elseif (is_array($failure) && is_string($failure['fingerprint'] ?? null)) {
                $failed[$failure['fingerprint']] = [
                    'status' => isset($failure['status']) ? (int) $failure['status'] : null,
                    'reason' => is_string($failure['reason'] ?? null) ? $failure['reason'] : null,
                ];
            }
        }

        foreach ($keys as $key) {
            $fingerprint = hash('sha256', $key->resolveSecret());

            if (array_key_exists($fingerprint, $failed)) {
                $failure = $failed[$fingerprint];
                $reason = $failure['reason'] ?? ($failure['status'] ? "HTTP {$failure['status']}" : 'Gemini render attempt failed.');
                $this->recordFailure('gemini', $key->resolveSecret(), $reason);
            } elseif ($successFingerprint !== null && hash_equals($fingerprint, $successFingerprint)) {
                $this->recordSuccess('gemini', $key->resolveSecret());
            }
        }
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
