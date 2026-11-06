<?php

namespace App\Http\Controllers;

use App\Models\ProviderApiKey;
use App\Models\ProviderSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProviderApiKeyController extends Controller
{
    public function index(Request $request): View
    {
        $requestedLanguage = $request->query('lang');
        $lang = in_array($requestedLanguage, ['ar', 'en'], true)
            ? $requestedLanguage
            : (in_array(session('app_locale'), ['ar', 'en'], true) ? session('app_locale') : 'ar');
        session(['app_locale' => $lang]);

        $keys = ProviderApiKey::query()
            ->orderBy('provider')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $settings = ProviderSetting::query()
            ->whereIn('name', ['elevenlabs.voice_id.ar', 'elevenlabs.voice_id.en'])
            ->get()
            ->keyBy('name');
        $voiceIds = [
            'ar' => (string) ($settings->get('elevenlabs.voice_id.ar')?->value ?: config('services.elevenlabs.voice_ids.ar', '')),
            'en' => (string) ($settings->get('elevenlabs.voice_id.en')?->value ?: config('services.elevenlabs.voice_ids.en', '')),
        ];

        return view('settings.provider-keys', compact('keys', 'lang', 'voiceIds'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => ['required', Rule::in(['gemini', 'elevenlabs'])],
            'label' => ['nullable', 'string', 'max:100'],
            'secret' => ['required', 'string', 'max:512'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('settings.provider-keys', ['lang' => $this->language()])
                ->withErrors($validator)
                ->withInput($request->except('secret'));
        }

        $validated = $validator->validated();
        $secret = trim($validated['secret']);
        if ($secret === '') {
            return redirect()->route('settings.provider-keys', ['lang' => $this->language()])
                ->withErrors(['secret' => $this->language() === 'ar' ? 'أدخل مفتاحاً صالحاً.' : 'Enter a valid key.']);
        }

        $duplicate = ProviderApiKey::query()
            ->where('provider', $validated['provider'])
            ->get()
            ->contains(fn (ProviderApiKey $key): bool => hash_equals((string) $key->secret, $secret));

        if ($duplicate) {
            return redirect()->route('settings.provider-keys', ['lang' => $this->language()])
                ->withErrors(['secret' => $this->language() === 'ar' ? 'هذا المفتاح مضاف مسبقاً لهذا المزود.' : 'This key is already saved for this provider.']);
        }

        $nextOrder = ((int) ProviderApiKey::query()->where('provider', $validated['provider'])->max('sort_order')) + 1;
        ProviderApiKey::query()->create([
            'provider' => $validated['provider'],
            'label' => trim((string) ($validated['label'] ?? '')) ?: null,
            'secret' => $secret,
            'is_active' => true,
            'sort_order' => $nextOrder,
        ]);

        $providerName = $validated['provider'] === 'gemini' ? 'Gemini' : 'ElevenLabs';
        $message = $this->language() === 'ar'
            ? 'تمت إضافة مفتاح '.$providerName.' وتخزينه مشفراً.'
            : $providerName.' key added and encrypted at rest.';

        return redirect()->route('settings.provider-keys', ['lang' => $this->language()])->with('success', $message);
    }

    public function update(Request $request, ProviderApiKey $providerApiKey): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $providerApiKey->forceFill([
            'label' => trim((string) ($validated['label'] ?? '')) ?: null,
            'sort_order' => (int) $validated['sort_order'],
        ])->save();

        $message = $this->language() === 'ar' ? 'تم تحديث الاسم والترتيب.' : 'Key label and order updated.';

        return redirect()->route('settings.provider-keys', ['lang' => $this->language()])->with('success', $message);
    }

    public function test(ProviderApiKey $providerApiKey): RedirectResponse
    {
        try {
            $response = match ($providerApiKey->provider) {
                'gemini' => Http::acceptJson()
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->get('https://generativelanguage.googleapis.com/v1beta/models', [
                        'key' => (string) $providerApiKey->secret,
                        'pageSize' => 1,
                    ]),
                'elevenlabs' => Http::acceptJson()
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->withHeaders(['xi-api-key' => (string) $providerApiKey->secret])
                    ->get('https://api.elevenlabs.io/v1/user'),
                default => null,
            };
        } catch (ConnectionException) {
            $providerApiKey->forceFill([
                'health_status' => 'unreachable',
                'last_tested_at' => now(),
                'last_error' => 'The provider could not be reached.',
                'cooldown_until' => now()->addMinutes(2),
            ])->save();

            return redirect()->route('settings.provider-keys', ['lang' => $this->language()])
                ->with('warning', $this->language() === 'ar' ? 'تعذّر الوصول للمزوّد؛ وُضع المفتاح في مهلة دقيقتين.' : 'Provider unreachable; key paused for two minutes.');
        }

        if ($response === null) {
            abort(404);
        }

        $status = $response->status();
        $now = now();
        if ($response->successful()) {
            $providerApiKey->forceFill([
                'health_status' => 'healthy',
                'last_tested_at' => $now,
                'last_success_at' => $now,
                'last_error' => null,
                'cooldown_until' => null,
            ])->save();

            return redirect()->route('settings.provider-keys', ['lang' => $this->language()])
                ->with('success', $this->language() === 'ar' ? 'نجح اختبار المفتاح.' : 'Key test succeeded.');
        }

        $attributes = [
            'health_status' => 'failed',
            'last_tested_at' => $now,
            'last_error' => $this->safeFailureReason($status),
            'cooldown_until' => null,
        ];
        $message = $this->safeFailureReason($status);

        if ($status === 401) {
            $attributes['is_active'] = false;
        } elseif ($status === 403) {
            $attributes['health_status'] = 'restricted';
            $message = $this->language() === 'ar'
                ? 'المفتاح لا يملك صلاحية لهذا الفحص أو أن الوصول مقيّد؛ لم يتم تعطيله.'
                : 'The key may be restricted from this check; it was not disabled.';
        } elseif ($status === 429) {
            $attributes['health_status'] = 'cooldown';
            $attributes['cooldown_until'] = $now->copy()->addMinutes(15);
        } elseif ($status >= 500) {
            $attributes['health_status'] = 'cooldown';
            $attributes['cooldown_until'] = $now->copy()->addMinutes(2);
        }

        $providerApiKey->forceFill($attributes)->save();

        return redirect()->route('settings.provider-keys', ['lang' => $this->language()])
            ->with('warning', $message);
    }

    public function updateVoiceIds(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'voice_id_ar' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'voice_id_en' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        foreach (['ar', 'en'] as $language) {
            $name = 'elevenlabs.voice_id.'.$language;
            $value = trim((string) ($validated['voice_id_'.$language] ?? ''));

            if ($value === '') {
                ProviderSetting::query()->where('name', $name)->delete();

                continue;
            }

            ProviderSetting::query()->updateOrCreate(['name' => $name], ['value' => $value]);
        }

        $message = $this->language() === 'ar'
            ? 'تم حفظ Voice IDs. الحقول الفارغة ترجع لاستخدام إعدادات الخادم.'
            : 'Voice IDs saved. Blank fields fall back to server settings.';

        return redirect()->route('settings.provider-keys', ['lang' => $this->language()])->with('success', $message);
    }

    public function toggle(ProviderApiKey $providerApiKey): RedirectResponse
    {
        $providerApiKey->forceFill(['is_active' => ! $providerApiKey->is_active])->save();

        $message = $this->language() === 'ar'
            ? ($providerApiKey->is_active ? 'تم تفعيل المفتاح.' : 'تم إيقاف المفتاح.')
            : ($providerApiKey->is_active ? 'Key enabled.' : 'Key disabled.');

        return redirect()->route('settings.provider-keys', ['lang' => $this->language()])->with('success', $message);
    }

    public function destroy(ProviderApiKey $providerApiKey): RedirectResponse
    {
        $providerApiKey->delete();

        $message = $this->language() === 'ar' ? 'تم حذف المفتاح.' : 'Key deleted.';

        return redirect()->route('settings.provider-keys', ['lang' => $this->language()])->with('success', $message);
    }

    private function safeFailureReason(int $status): string
    {
        return match (true) {
            $status === 401 => $this->language() === 'ar' ? 'رفض المزوّد المفتاح (401).' : 'Provider rejected the key (401).',
            $status === 403 => $this->language() === 'ar' ? 'المفتاح أو نقطة الفحص مقيّدة (403).' : 'Key or check endpoint is restricted (403).',
            $status === 429 => $this->language() === 'ar' ? 'تم بلوغ حد الاستخدام (429)؛ مهلة 15 دقيقة.' : 'Rate limit reached (429); cooling down for 15 minutes.',
            $status >= 500 => $this->language() === 'ar' ? 'المزوّد يواجه عطلاً مؤقتاً؛ مهلة دقيقتين.' : 'Provider is temporarily unavailable; cooling down for two minutes.',
            default => $this->language() === 'ar' ? 'فشل الفحص (HTTP '.$status.').' : 'Key check failed (HTTP '.$status.').',
        };
    }

    private function language(): string
    {
        return in_array(session('app_locale'), ['ar', 'en'], true) ? session('app_locale') : 'ar';
    }
}
