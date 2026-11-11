<!DOCTYPE html>
@php
    $isAr = ($lang ?? 'ar') === 'ar';
    $dir = $isAr ? 'rtl' : 'ltr';
    $stateStyles = [
        'ok' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
        'fail' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
        'connected' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30',
        'missing_key' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        'invalid_key' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
        'network' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        'rate_limited' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        'error' => 'bg-rose-500/10 text-rose-300 border-rose-500/30',
    ];
@endphp
<html lang="{{ $isAr ? 'ar' : 'en' }}" dir="{{ $dir }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $isAr ? 'AI Reels Studio — الإعداد الأول' : 'AI Reels Studio — First Run' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: '{{ $isAr ? "Cairo" : "Plus Jakarta Sans" }}', sans-serif; background: #070b12; color: #f1f5f9; min-height: 100vh; }
        .setup-card { background: linear-gradient(180deg, #111827 0%, #0d131f 100%); border: 1px solid rgba(255,255,255,0.08); border-radius: 1.25rem; }
        .check-row { border: 1px solid #1e293b; border-radius: 0.9rem; background: #0b1120; }
    </style>
</head>
<body class="antialiased">
<main class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
    <header class="mb-8 text-center">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/15 text-3xl">🎬</div>
        <h1 class="text-2xl font-extrabold sm:text-3xl">{{ $isAr ? 'AI Reels Studio — الإعداد الأول' : 'AI Reels Studio — First Run' }}</h1>
        <p class="mt-2 text-sm text-slate-400">{{ $isAr ? 'تأكد من جاهزية البيئة وأدخل مفاتيحك قبل فتح الاستوديو.' : 'Verify your environment and add your API keys before opening the studio.' }}</p>
    </header>

    <section class="setup-card p-5 sm:p-6">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-sm font-bold text-slate-200">{{ $isAr ? 'حالة كل خطوة' : 'Step status' }}</h2>
            <button id="recheckButton" type="button" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs font-bold text-slate-200 hover:border-amber-500/40">
                {{ $isAr ? 'إعادة الفحص' : 'Re-check' }}
            </button>
        </div>
        <div id="checksList" class="flex flex-col gap-2">
            @foreach ($report['checks'] as $check)
                <div class="check-row flex flex-wrap items-center justify-between gap-2 px-3 py-2.5" data-check-name="{{ $check['name'] }}">
                    <div class="min-w-0">
                        <strong class="block text-xs uppercase tracking-wide text-slate-300">{{ $check['name'] }}</strong>
                        <span class="block truncate text-[11px] text-slate-500">{{ $check['detail'] }}</span>
                    </div>
                    <span class="check-state rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $stateStyles[$check['state']] ?? ($check['ok'] ? $stateStyles['ok'] : $stateStyles['fail']) }}">
                        {{ $isAr ? ($check['ok'] ? 'جاهز' : 'غير جاهز') : ($check['ok'] ? 'Ready' : 'Not ready') }}
                    </span>
                </div>
            @endforeach
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
            <button id="fixButton" type="button" class="rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-extrabold text-slate-950 hover:bg-amber-400">
                {{ $isAr ? 'تثبيت / إصلاح تلقائي' : 'Install / Fix Automatically' }}
            </button>
            <a href="{{ route('reels.wizard') }}" class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2.5 text-sm font-bold text-slate-200 hover:border-amber-500/40">
                {{ $isAr ? 'فتح الاستوديو' : 'Open Studio' }}
            </a>
        </div>
        <p id="fixStatus" class="mt-3 hidden text-xs text-slate-400"></p>
        <div id="fixSteps" class="mt-3 hidden flex-col gap-2"></div>
    </section>

    <section class="setup-card mt-6 p-5 sm:p-6">
        <h2 class="text-sm font-bold text-slate-200">{{ $isAr ? 'مفاتيح API' : 'API keys' }}</h2>
        <p class="mt-1 text-xs text-slate-500">{{ $isAr ? 'تُحفظ المفاتيح مشفرة على هذا الجهاز، ويُفحص الاتصال تلقائياً بعد الحفظ.' : 'Keys are stored encrypted on this device; connectivity is tested automatically after saving.' }}</p>

        <form id="keysForm" class="mt-4 flex flex-col gap-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs font-bold text-slate-300">{{ $isAr ? 'مفتاح Gemini (مطلوب للتوليد)' : 'Gemini key (required for generation)' }}</span>
                <input type="password" name="gemini_key" autocomplete="off" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm" placeholder="AIza...">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-bold text-slate-300">{{ $isAr ? 'مفتاح ElevenLabs (اختياري — تعليق صوتي)' : 'ElevenLabs key (optional — voiceover)' }}</span>
                <input type="password" name="elevenlabs_key" autocomplete="off" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm" placeholder="sk_...">
            </label>
            <button type="submit" class="rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-extrabold text-slate-950 hover:bg-amber-400">
                {{ $isAr ? 'حفظ المفاتيح واختبار الاتصال' : 'Save keys and test connection' }}
            </button>
        </form>
        <p id="keysStatus" class="mt-3 hidden text-xs"></p>
    </section>
</main>

<script>
    (function () {
        const isAr = {{ $isAr ? 'true' : 'false' }};
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const checksList = document.getElementById('checksList');
        const fixButton = document.getElementById('fixButton');
        const fixStatus = document.getElementById('fixStatus');
        const fixSteps = document.getElementById('fixSteps');
        const recheckButton = document.getElementById('recheckButton');
        const keysForm = document.getElementById('keysForm');
        const keysStatus = document.getElementById('keysStatus');
        const stateStyles = {{ json_encode($stateStyles) }};

        const labels = {
            php: isAr ? 'PHP' : 'PHP',
            app_key: isAr ? 'مفتاح التطبيق APP_KEY' : 'APP_KEY',
            database: isAr ? 'قاعدة البيانات' : 'Database',
            python: isAr ? 'بايثون' : 'Python',
            ffmpeg: isAr ? 'FFmpeg' : 'FFmpeg',
            storage: isAr ? 'مساحة القرص' : 'Disk space',
            permissions: isAr ? 'الصلاحيات' : 'Permissions',
            gemini: isAr ? 'اتصال Gemini' : 'Gemini connection',
            elevenlabs: isAr ? 'اتصال ElevenLabs' : 'ElevenLabs connection',
        };

        async function postJson(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(payload || {})
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || (isAr ? 'تعذر إكمال الطلب.' : 'Request failed.'));
            return data;
        }

        function renderChecks(report) {
            checksList.replaceChildren();
            (report.checks || []).forEach((check) => {
                const row = document.createElement('div');
                row.className = 'check-row flex flex-wrap items-center justify-between gap-2 px-3 py-2.5';
                row.dataset.checkName = check.name;
                const info = document.createElement('div');
                const name = document.createElement('strong');
                name.className = 'block text-xs uppercase tracking-wide text-slate-300';
                name.textContent = labels[check.name] || check.name;
                const detail = document.createElement('span');
                detail.className = 'block truncate text-[11px] text-slate-500';
                detail.textContent = check.detail;
                info.append(name, detail);
                const state = document.createElement('span');
                state.className = 'check-state rounded-full border px-2.5 py-1 text-[10px] font-bold ' +
                    (stateStyles[check.state] || (check.ok ? stateStyles.ok : stateStyles.fail));
                state.textContent = check.ok ? (isAr ? 'جاهز' : 'Ready') : (isAr ? 'غير جاهز' : 'Not ready');
                row.append(info, state);
                checksList.appendChild(row);
            });
        }

        async function recheck() {
            recheckButton.disabled = true;
            try {
                const response = await fetch('{{ route('first-run.check') }}', { headers: { 'Accept': 'application/json' } });
                const data = await response.json();
                renderChecks(data);
            } finally {
                recheckButton.disabled = false;
            }
        }

        recheckButton?.addEventListener('click', recheck);

        fixButton?.addEventListener('click', async () => {
            fixButton.disabled = true;
            fixStatus.classList.remove('hidden');
            fixStatus.textContent = isAr ? 'جارٍ تشغيل الإصلاح التلقائي...' : 'Running the automatic fix...';
            try {
                const data = await postJson('{{ route('first-run.fix') }}');
                fixSteps.classList.remove('hidden');
                fixSteps.classList.add('flex');
                fixSteps.replaceChildren();
                (data.steps || []).forEach((step) => {
                    const row = document.createElement('div');
                    row.className = 'check-row flex items-center justify-between gap-2 px-3 py-2';
                    const label = document.createElement('span');
                    label.className = 'text-xs text-slate-300';
                    label.textContent = step.name + ' — ' + step.detail;
                    const badge = document.createElement('span');
                    badge.className = 'rounded-full px-2 py-0.5 text-[10px] font-bold ' + (step.ok ? 'bg-emerald-500/10 text-emerald-300' : 'bg-rose-500/10 text-rose-300');
                    badge.textContent = step.ok ? 'OK' : 'FAIL';
                    row.append(label, badge);
                    fixSteps.appendChild(row);
                });
                renderChecks(data.report || {});
                fixStatus.textContent = data.all_passed
                    ? (isAr ? 'اكتمل الإصلاح بنجاح.' : 'Fix completed successfully.')
                    : (isAr ? 'اكتمل الإصلاح مع تنبيهات؛ راجع الخطوات أدناه.' : 'Fix finished with warnings; review the steps below.');
            } catch (error) {
                fixStatus.textContent = error.message;
            } finally {
                fixButton.disabled = false;
            }
        });

        keysForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            keysStatus.classList.remove('hidden');
            keysStatus.className = 'mt-3 text-xs text-slate-400';
            keysStatus.textContent = isAr ? 'جارٍ الحفظ واختبار الاتصال...' : 'Saving and testing connection...';

            const payload = {};
            const gemini = keysForm.elements.gemini_key.value.trim();
            const elevenlabs = keysForm.elements.elevenlabs_key.value.trim();
            if (gemini) payload.gemini = gemini;
            if (elevenlabs) payload.elevenlabs = elevenlabs;
            if (!payload.gemini && !payload.elevenlabs) {
                keysStatus.textContent = isAr ? 'أدخل مفتاحاً واحداً على الأقل.' : 'Enter at least one key.';
                return;
            }

            try {
                const data = await postJson('{{ url('/api/first-run/keys') }}', payload);
                const rows = Object.entries(data.results || {});
                const allOk = rows.every(([, result]) => result.ok);
                keysStatus.textContent = rows.map(([provider, result]) => provider + ': ' + (result.ok ? (isAr ? 'متصل ✓' : 'connected ✓') : result.detail)).join(' | ');
                keysStatus.className = 'mt-3 text-xs ' + (allOk ? 'text-emerald-300' : 'text-rose-300');
                recheck();
                if (allOk && data.report && data.report.all_passed) {
                    window.location.href = '{{ route('reels.wizard') }}';
                }
            } catch (error) {
                keysStatus.textContent = error.message;
                keysStatus.className = 'mt-3 text-xs text-rose-300';
            }
        });
    })();
</script>
</body>
</html>
