<!DOCTYPE html>
@php
    $isAr = ($lang ?? 'ar') === 'ar';
    $dir = $isAr ? 'rtl' : 'ltr';
    $healthLabels = [
        'untested' => $isAr ? 'غير مختبر' : 'Not tested',
        'healthy' => $isAr ? 'سليم' : 'Healthy',
        'failed' => $isAr ? 'فشل الفحص' : 'Check failed',
        'restricted' => $isAr ? 'صلاحية مقيّدة' : 'Restricted',
        'cooldown' => $isAr ? 'مهلة مؤقتة' : 'Cooling down',
        'cooldown_expired' => $isAr ? 'انتهت المهلة' : 'Cooldown ended',
        'unreachable' => $isAr ? 'تعذّر الاتصال' : 'Unreachable',
    ];
    $healthClasses = [
        'untested' => 'border-slate-700 bg-slate-800 text-slate-300',
        'healthy' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
        'failed' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',
        'restricted' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        'cooldown' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        'cooldown_expired' => 'border-slate-700 bg-slate-800 text-slate-300',
        'unreachable' => 'border-orange-500/30 bg-orange-500/10 text-orange-300',
    ];
@endphp
<html lang="{{ $isAr ? 'ar' : 'en' }}" dir="{{ $dir }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isAr ? 'إدارة مفاتيح الخدمات' : 'Provider Key Manager' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: '{{ $isAr ? "Cairo" : "Plus Jakarta Sans" }}', sans-serif; background: #070b12; color: #f1f5f9; min-height: 100vh; }
        .panel { background: linear-gradient(180deg, #111827 0%, #0d131f 100%); border: 1px solid rgba(255,255,255,.08); border-radius: 1.5rem; }
    </style>
</head>
<body class="antialiased">
<header class="sticky top-0 z-20 border-b border-slate-800 bg-[#0b111c] shadow-xl">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl border border-amber-500/40 bg-amber-500/15 text-amber-400"><i class="fa-solid fa-key"></i></div>
            <div>
                <h1 class="font-black text-white">{{ $isAr ? 'إدارة مفاتيح الخدمات' : 'Provider key manager' }}</h1>
            <p class="text-[11px] text-slate-400">{{ $isAr ? 'Gemini و ElevenLabs' : 'Gemini and ElevenLabs' }}</p>
            </div>
        </div>
        <a href="{{ route('reels.index', ['lang' => $lang]) }}" class="rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-xs font-bold text-slate-200 transition hover:bg-slate-700">
            <i class="fa-solid fa-arrow-right {{ $isAr ? '' : 'rotate-180' }}"></i>
            {{ $isAr ? 'العودة للاستوديو' : 'Back to studio' }}
        </a>
    </div>
</header>

<main class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6">
    @if(session('success'))
        <div role="status" class="rounded-2xl border border-emerald-500/30 bg-emerald-950/50 p-4 text-sm font-bold text-emerald-300"><i class="fa-solid fa-circle-check me-2"></i>{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div role="alert" class="rounded-2xl border border-amber-500/30 bg-amber-950/50 p-4 text-sm font-bold text-amber-200"><i class="fa-solid fa-triangle-exclamation me-2"></i>{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-2xl border border-rose-500/30 bg-rose-950/50 p-4 text-sm font-bold text-rose-300"><i class="fa-solid fa-circle-exclamation me-2"></i>{{ $errors->first() }}</div>
    @endif

    <section class="panel overflow-hidden p-5 sm:p-7">
        <div class="flex items-start gap-3">
            <div class="mt-0.5 flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sky-300"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
                <h2 class="font-extrabold text-white">{{ $isAr ? 'المفاتيح محمية' : 'Your keys stay protected' }}</h2>
                <p class="mt-1 max-w-3xl text-xs leading-6 text-slate-400">{{ $isAr ? 'تُشفّر المفاتيح عند حفظها باستخدام مفتاح تشفير التطبيق. لن تظهر القيمة كاملة بعد الحفظ؛ ستشاهد الاسم وآخر أربعة أحرف فقط. اختبار الاتصال لا يولّد نصاً أو صوتاً. 401 يعطّل المفتاح، و429 أو العطل المؤقت يضعه في مهلة؛ أما 403 فيُعرض كقيد صلاحية ولا يعطّل المفتاح.' : 'Keys are encrypted at rest with the application encryption key. Full values are never shown after saving. Connection tests do not generate text or audio. A 401 disables a key; rate limits and temporary outages trigger a cooldown. A 403 is treated as a possible permission restriction, not an invalid key.' }}</p>
            </div>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach(['gemini' => ['name' => 'Gemini', 'icon' => 'fa-sparkles', 'accent' => 'sky', 'placeholder' => 'AIza...'], 'elevenlabs' => ['name' => 'ElevenLabs', 'icon' => 'fa-wave-square', 'accent' => 'violet', 'placeholder' => '...']] as $provider => $meta)
            @php
                $providerKeys = $keys->where('provider', $provider);
                $usableCount = $providerKeys->filter(fn ($key) => $key->is_usable)->count();
            @endphp
            <section class="panel p-5 sm:p-6">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-{{ $meta['accent'] }}-500/10 text-{{ $meta['accent'] }}-300"><i class="fa-solid {{ $meta['icon'] }}"></i></div>
                        <div>
                            <h2 class="font-black text-white">{{ $meta['name'] }}</h2>
                            <p class="text-[11px] text-slate-400">{{ $providerKeys->count() }} {{ $isAr ? 'محفوظ' : 'saved' }} · {{ $usableCount }} {{ $isAr ? 'جاهز' : 'ready' }}</p>
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.provider-keys.store', ['lang' => $lang]) }}" class="space-y-3 rounded-2xl border border-slate-800 bg-slate-950/50 p-4">
                    @csrf
                    <input type="hidden" name="provider" value="{{ $provider }}">
                    <label class="block text-xs font-bold text-slate-300" for="{{ $provider }}-label">{{ $isAr ? 'اسم اختياري للتعرّف على المفتاح' : 'Optional label' }}</label>
                    <input id="{{ $provider }}-label" name="label" value="{{ old('provider') === $provider ? old('label') : '' }}" maxlength="100" autocomplete="off" placeholder="{{ $provider === 'gemini' ? ($isAr ? 'مثال: حساب العمل' : 'e.g. Work account') : ($isAr ? 'مثال: حساب التعليق' : 'e.g. Narration account') }}" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-xs text-white placeholder:text-slate-600 focus:border-amber-500 focus:outline-none">
                    <label class="block text-xs font-bold text-slate-300" for="{{ $provider }}-secret">{{ $isAr ? 'مفتاح API' : 'API key' }}</label>
                    <div class="flex gap-2">
                        <input id="{{ $provider }}-secret" name="secret" type="password" required maxlength="512" autocomplete="new-password" dir="ltr" placeholder="{{ $meta['placeholder'] }}" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-left text-xs font-mono text-white placeholder:text-slate-600 focus:border-amber-500 focus:outline-none">
                        <button type="submit" class="shrink-0 rounded-xl bg-amber-500 px-4 py-2.5 text-xs font-black text-slate-950 transition hover:bg-amber-400"><i class="fa-solid fa-plus me-1"></i>{{ $isAr ? 'إضافة' : 'Add' }}</button>
                    </div>
                </form>

                <div class="mt-4 space-y-3">
                    @forelse($providerKeys as $key)
                        <article class="rounded-xl border border-slate-800 bg-slate-900/60 p-3">
                            @php
                                $displayHealth = $key->health_status;
                                if ($displayHealth === 'cooldown' && $key->cooldown_until && $key->cooldown_until->lessThanOrEqualTo(now())) {
                                    $displayHealth = 'cooldown_expired';
                                }
                            @endphp
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-bold text-white">{{ $key->label ?: ($isAr ? 'مفتاح '.$meta['name'] : $meta['name'].' key') }}</p>
                                    <p dir="ltr" class="mt-1 text-left font-mono text-[11px] text-slate-400">{{ $key->masked_secret }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $healthClasses[$displayHealth] ?? $healthClasses['untested'] }}">{{ $healthLabels[$displayHealth] ?? $healthLabels['untested'] }}</span>
                                    <span class="rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $key->is_active ? 'border-sky-500/30 bg-sky-500/10 text-sky-300' : 'border-slate-700 bg-slate-800 text-slate-400' }}">{{ $key->is_active ? ($isAr ? 'مفعّل' : 'Enabled') : ($isAr ? 'متوقف' : 'Disabled') }}</span>
                                </div>
                            </div>

                            <div class="mt-2 space-y-1 text-[10px] leading-5 text-slate-400">
                                @if($key->last_tested_at)
                                    <p>{{ $isAr ? 'آخر فحص:' : 'Last check:' }} {{ $key->last_tested_at->format('Y-m-d H:i') }}
                                        @if($key->last_success_at) · {{ $isAr ? 'آخر نجاح:' : 'Last success:' }} {{ $key->last_success_at->format('Y-m-d H:i') }} @endif
                                    </p>
                                @endif
                                @if($key->last_used_at)
                                    <p>{{ $isAr ? 'آخر تدوير:' : 'Last selected:' }} {{ $key->last_used_at->format('Y-m-d H:i') }} · {{ $isAr ? 'مرات الاختيار:' : 'Selections:' }} {{ $key->use_count }}</p>
                                @endif
                                @if($key->last_error)
                                    <p class="text-amber-300">{{ $key->last_error }}</p>
                                @endif
                                @if($key->cooldown_until && $key->cooldown_until->isFuture())
                                    <p>{{ $isAr ? 'تنتهي المهلة:' : 'Cooldown ends:' }} {{ $key->cooldown_until->format('Y-m-d H:i') }}</p>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('settings.provider-keys.update', ['providerApiKey' => $key->id, 'lang' => $lang]) }}" class="mt-3 grid grid-cols-[minmax(0,1fr)_5rem_auto] gap-2">
                                @csrf
                                @method('PATCH')
                                <label class="sr-only" for="key-label-{{ $key->id }}">{{ $isAr ? 'اسم المفتاح' : 'Key label' }}</label>
                                <input id="key-label-{{ $key->id }}" name="label" value="{{ $key->label }}" maxlength="100" placeholder="{{ $isAr ? 'اسم المفتاح' : 'Key label' }}" class="min-w-0 rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-2 text-[11px] text-white placeholder:text-slate-600 focus:border-amber-500 focus:outline-none">
                                <label class="sr-only" for="key-order-{{ $key->id }}">{{ $isAr ? 'الترتيب' : 'Order' }}</label>
                                <input id="key-order-{{ $key->id }}" type="number" name="sort_order" value="{{ $key->sort_order }}" min="0" max="9999" required class="w-20 rounded-lg border border-slate-700 bg-slate-950 px-2 py-2 text-center text-[11px] text-white focus:border-amber-500 focus:outline-none">
                                <button type="submit" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-[10px] font-bold text-slate-200 hover:bg-slate-700">{{ $isAr ? 'حفظ' : 'Save' }}</button>
                            </form>

                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <form method="POST" action="{{ route('settings.provider-keys.test', ['providerApiKey' => $key->id, 'lang' => $lang]) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-[10px] font-bold text-sky-200 transition hover:bg-sky-500/20"><i class="fa-solid fa-plug-circle-check me-1"></i>{{ $isAr ? 'اختبار الاتصال' : 'Test connection' }}</button>
                                </form>
                                <form method="POST" action="{{ route('settings.provider-keys.toggle', ['providerApiKey' => $key->id, 'lang' => $lang]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="rounded-lg border px-3 py-2 text-[10px] font-bold transition {{ $key->is_active ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20' : 'border-slate-700 bg-slate-800 text-slate-400 hover:text-white' }}">{{ $key->is_active ? ($isAr ? 'إيقاف' : 'Disable') : ($isAr ? 'تفعيل' : 'Enable') }}</button>
                                </form>
                                <form method="POST" action="{{ route('settings.provider-keys.destroy', ['providerApiKey' => $key->id, 'lang' => $lang]) }}" onsubmit="return confirm('{{ $isAr ? 'حذف هذا المفتاح نهائياً؟' : 'Delete this key permanently?' }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg border border-rose-500/20 bg-rose-500/5 px-3 py-2 text-[10px] font-bold text-rose-300 transition hover:bg-rose-500/15"><i class="fa-solid fa-trash-can me-1"></i>{{ $isAr ? 'حذف' : 'Delete' }}</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-800 p-4 text-center text-xs text-slate-400">{{ $isAr ? 'لا توجد مفاتيح محفوظة لهذا المزود.' : 'No saved keys for this provider yet.' }}</p>
                    @endforelse
                </div>

                @if($provider === 'elevenlabs')
                    <div class="mt-5 rounded-2xl border border-violet-500/20 bg-violet-500/5 p-4">
                        <h3 class="text-xs font-black text-violet-200"><i class="fa-solid fa-microphone-lines me-1"></i>{{ $isAr ? 'معرّفات الصوت Voice IDs' : 'Voice IDs' }}</h3>
                        <p class="mt-1 text-[10px] leading-5 text-slate-400">{{ $isAr ? 'أدخل معرّفاً مستقلاً للعربية والإنجليزية. الحقل الفارغ يعود إلى إعدادات الخادم القديمة.' : 'Set separate IDs for Arabic and English. A blank field falls back to legacy server settings.' }}</p>
                        <form method="POST" action="{{ route('settings.provider-keys.voice-ids', ['lang' => $lang]) }}" class="mt-3 space-y-3">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold text-slate-300" for="voice-id-ar">{{ $isAr ? 'Voice ID عربي' : 'Arabic Voice ID' }}</label>
                                    <input id="voice-id-ar" name="voice_id_ar" value="{{ old('voice_id_ar', $voiceIds['ar']) }}" maxlength="128" dir="ltr" placeholder="{{ $isAr ? 'مثال: voice-id-ar' : 'e.g. arabic-voice-id' }}" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-left text-xs font-mono text-white placeholder:text-slate-600 focus:border-violet-400 focus:outline-none">
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold text-slate-300" for="voice-id-en">{{ $isAr ? 'Voice ID إنجليزي' : 'English Voice ID' }}</label>
                                    <input id="voice-id-en" name="voice_id_en" value="{{ old('voice_id_en', $voiceIds['en']) }}" maxlength="128" dir="ltr" placeholder="{{ $isAr ? 'مثال: voice-id-en' : 'e.g. english-voice-id' }}" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-left text-xs font-mono text-white placeholder:text-slate-600 focus:border-violet-400 focus:outline-none">
                                </div>
                            </div>
                            <button type="submit" class="rounded-lg bg-violet-500 px-4 py-2 text-[10px] font-black text-white transition hover:bg-violet-400">{{ $isAr ? 'حفظ Voice IDs' : 'Save Voice IDs' }}</button>
                        </form>
                    </div>
                    <p class="mt-3 rounded-xl bg-slate-950/50 p-3 text-[10px] leading-5 text-slate-400">{{ $isAr ? 'تُجرّب المفاتيح بالتناوب، ويُستخدم التالي عند فشل الحالي. المفتاح القديم ELEVENLABS_API_KEY يبقى احتياطياً ولا يظهر هنا.' : 'Keys rotate by least use, with the next key as fallback. The legacy ELEVENLABS_API_KEY remains a fallback and is not listed here.' }}</p>
                @elseif($provider === 'gemini')
                    <p class="mt-3 rounded-xl bg-sky-500/5 p-3 text-[10px] leading-5 text-slate-400">{{ $isAr ? 'تُدمج المفاتيح المحفوظة مع مفاتيح Gemini المؤقتة أثناء التوليد، ثم تُجرّب بالتتابع.' : 'Saved keys are combined with any one-time Gemini keys entered during generation, then tried in sequence.' }}</p>
                @endif
            </section>
        @endforeach
    </div>
</main>
</body>
</html>
