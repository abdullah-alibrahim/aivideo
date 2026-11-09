<!DOCTYPE html>
@php
    $isAr = ($lang ?? 'ar') === 'ar';
    $dir = $isAr ? 'rtl' : 'ltr';
    $qualityCopy = [
        'ready' => trans('studio.quality.ready', [], $lang),
        'review' => trans('studio.quality.review', [], $lang),
        'score' => trans('studio.quality.score', [], $lang),
        'checks' => trans('studio.quality.checks', [], $lang),
        'states' => trans('studio.quality.states', [], $lang),
        'details' => trans('studio.quality.details', [], $lang),
        'checking' => $isAr ? 'جارٍ فحص الجاهزية...' : 'Checking readiness…',
        'failed' => $isAr ? 'تعذّر إجراء الفحص. تحقق من الاتصال ثم أعد المحاولة.' : 'The check failed. Check the connection and try again.',
    ];
@endphp
<html lang="{{ $isAr ? 'ar' : 'en' }}" dir="{{ $dir }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ماكينة المحتوى - استوديو تركيب الفيديوهات القصيرة</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Cairo', 'Plus Jakarta Sans', sans-serif;
            background: #070b12;
            color: #f1f5f9;
            min-height: 100vh;
        }
        .step-tab-btn {
            padding: 0.45rem 0.95rem;
            border-radius: 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            color: #94a3b8;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            white-space: nowrap;
        }
        .step-tab-btn:hover {
            color: #f1f5f9;
            background: #1e293b;
            border-color: rgba(245, 158, 11, 0.2);
        }
        .step-tab-btn.active {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #070b12;
            border-color: #f59e0b;
            box-shadow: 0 4px 20px rgba(245, 158, 11, 0.35);
        }
        .studio-card {
            background: linear-gradient(180deg, #111827 0%, #0d131f 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1.5rem;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .studio-card:hover {
            border-color: rgba(255, 255, 255, 0.12);
        }
        @keyframes stepEnter {
            from { opacity: 0; transform: translateY(14px) scale(0.992); filter: blur(2px); }
            to { opacity: 1; transform: translateY(0) scale(1); filter: blur(0); }
        }
        .step-enter { animation: stepEnter 0.42s cubic-bezier(0.22, 1, 0.36, 1) both; }
        @keyframes timelineCardPop {
            0% { opacity: 0.72; transform: scale(0.985) translateY(4px); }
            100% { opacity: 1; transform: scale(1) translateY(0); }
        }
        .timeline-card-pop { animation: timelineCardPop 0.32s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .option-pill {
            background: #111a2e;
            border: 1px solid #1e2c47;
            border-radius: 1rem;
            padding: 0.85rem;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .option-pill:hover {
            border-color: #f59e0b;
            background: #17243d;
            transform: translateY(-2px);
        }
        .option-pill:focus-within,
        button:focus-visible,
        input:focus-visible,
        select:focus-visible,
        textarea:focus-visible {
            outline: 2px solid #fbbf24;
            outline-offset: 3px;
        }
        .option-pill.active {
            border-color: #f59e0b;
            background: #1a2947;
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.15);
        }
        .reel-preview-frame {
            aspect-ratio: 9 / 16;
            max-height: 580px;
            background: radial-gradient(circle at 50% 35%, #1e293b 0%, #070b12 85%);
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.9), 0 0 40px rgba(245, 158, 11, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.1);
        }
        .color-dot {
            width: 1.85rem;
            height: 1.85rem;
            border-radius: 9999px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.25s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
        }
        .color-dot:hover, .color-dot.active {
            transform: scale(1.2);
            border-color: #f59e0b;
            box-shadow: 0 0 12px rgba(245, 158, 11, 0.5);
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; scroll-behavior: auto !important; }
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #1e293b;
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px; width: 18px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #f59e0b;
        }
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
        }
        #accountModal .space-y-2 > div { display: none; }
        .caption-tab-btn {
            padding: 0.5rem 1.1rem;
            border-radius: 0.75rem;
            font-size: 0.75rem;
            font-weight: 700;
            transition: all 0.2s;
            color: #94a3b8;
            background: #111827;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .caption-tab-btn:hover {
            color: #ffffff;
            background: #1e293b;
        }
        .caption-tab-btn.active {
            background: #f59e0b;
            color: #070b12;
            border-color: #f59e0b;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.25);
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #070b12; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #334155; }
    </style>
</head>
<body class="antialiased pb-24">

    <!-- Top Sticky Header -->
    <header class="bg-[#0b111c] border-b border-slate-800 sticky top-0 z-50 shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 min-h-20 flex flex-wrap items-center justify-between gap-3 py-3">
            <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                <!-- Hamburger menu that opens side account modal (Image 1) -->
                <button type="button" onclick="openAccountModal()" class="w-10 h-10 rounded-xl bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-300 transition border border-slate-700">
                    <i class="fa-solid fa-bars text-base"></i>
                </button>
                <a href="{{ route('reels.index', ['lang' => $lang ?? 'ar']) }}" class="w-10 h-10 rounded-xl bg-slate-800 hover:bg-slate-700 flex items-center justify-center text-slate-300 transition border border-slate-700">
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <div class="w-9 h-9 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-sm font-bold">
                    <i class="fa-solid fa-play"></i>
                </div>
                <div class="min-w-0">
                    <h1 class="text-sm sm:text-base font-black text-white flex items-center gap-2 whitespace-nowrap">
                        ماكينة المحتوى الأجنبي
                        <span class="hidden sm:inline text-[10px] bg-amber-500/20 text-amber-400 px-2 py-0.5 rounded-full font-bold">محلي</span>
                    </h1>
                </div>
            </div>

            <!-- Steps Breadcrumb (1 معلومات الفيديو -> 2 الفكرة -> 3 الهوك -> 4 النص -> 5 التصميم -> 6 تقييم الفيديو -> 7 المعاينة والتصدير) -->
            <div class="hidden lg:flex items-center gap-1.5 text-xs overflow-x-auto py-2">
                <button type="button" onclick="goToStep(1)" id="stepTab1" class="step-tab-btn active">1 معلومات الفيديو</button>
                <span class="text-slate-600">&larr;</span>
                <button type="button" onclick="goToStep(2)" id="stepTab2" class="step-tab-btn">2 الفكرة</button>
                <span class="text-slate-600">&larr;</span>
                <button type="button" onclick="goToStep(3)" id="stepTab3" class="step-tab-btn">3 الهوك</button>
                <span class="text-slate-600">&larr;</span>
                <button type="button" onclick="goToStep(4)" id="stepTab4" class="step-tab-btn">4 النص</button>
                <span class="text-slate-600">&larr;</span>
                <button type="button" onclick="goToStep(5)" id="stepTab5" class="step-tab-btn">5 التصميم</button>
                <span class="text-slate-600">&larr;</span>
                <button type="button" onclick="goToStep(6)" id="stepTab6" class="step-tab-btn">6 تقييم الفيديو</button>
                <span class="text-slate-600">&larr;</span>
                <button type="button" onclick="goToStep(7)" id="stepTab7" class="step-tab-btn">7 المعاينة والتصدير</button>
            </div>

            <div class="flex items-center gap-2 sm:gap-3">
                <a href="{{ route('settings.provider-keys', ['lang' => $lang ?? 'ar']) }}" aria-label="إدارة المفاتيح" class="flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-800 px-2.5 sm:px-3 py-2 text-[11px] font-bold text-slate-200 transition hover:bg-slate-700">
                    <i class="fa-solid fa-key text-amber-400"></i>
                    <span class="hidden sm:inline">إدارة المفاتيح</span>
                </a>
                <a href="{{ route('analytics.dashboard', ['lang' => $lang ?? 'ar']) }}" aria-label="{{ $isAr ? 'لوحة التحليلات' : 'Analytics dashboard' }}" class="flex items-center gap-2 rounded-xl border border-sky-500/30 bg-slate-800 px-2.5 sm:px-3 py-2 text-[11px] font-bold text-sky-200 transition hover:bg-slate-700">
                    <i class="fa-solid fa-chart-line"></i>
                    <span class="hidden sm:inline">{{ $isAr ? 'التحليلات' : 'Analytics' }}</span>
                </a>
                <div class="hidden sm:flex items-center gap-2 bg-slate-800 px-3 py-1.5 rounded-xl border border-slate-700 text-xs font-bold text-amber-400">
                    <i class="fa-solid fa-coins"></i>
                    <span>تجميع محلي</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Wizard Screen Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <div id="wizardStatus" class="hidden mb-5 rounded-xl border border-rose-500/40 bg-rose-950/40 px-4 py-3 text-sm text-rose-200" role="status" aria-live="polite"></div>

        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-900/60 px-4 py-3 text-[11px] text-slate-400">
            <p id="wizardDraftStatus" role="status" aria-live="polite">{{ $isAr ? 'تُحفظ تغييرات الويزارد محلياً في هذا المتصفح. لا تُحفظ الملفات أو مفاتيح API المؤقتة.' : 'Wizard changes are saved locally in this browser. Uploaded files and temporary API keys are not saved.' }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <select id="wizardPresetSelect" class="rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-[11px] text-slate-300" aria-label="{{ $isAr ? 'اختيار إعداد محفوظ' : 'Choose saved preset' }}">
                    <option value="">{{ $isAr ? 'الإعدادات المحفوظة' : 'Saved presets' }}</option>
                </select>
                <button type="button" id="saveWizardPreset" class="rounded-lg border border-amber-400/40 px-3 py-1.5 font-bold text-amber-200 hover:bg-amber-500/10">{{ $isAr ? 'حفظ إعداد' : 'Save preset' }}</button>
                <button type="button" id="loadWizardPreset" class="rounded-lg border border-slate-700 px-3 py-1.5 font-bold text-slate-300 hover:border-amber-400 hover:text-white">{{ $isAr ? 'استعادة' : 'Load' }}</button>
                <button type="button" id="clearWizardDraft" class="rounded-lg border border-slate-700 px-3 py-1.5 font-bold text-slate-300 hover:border-amber-400 hover:text-white">{{ $isAr ? 'مسح المسودة' : 'Clear draft' }}</button>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-5 rounded-xl border border-rose-500/40 bg-rose-950/40 px-4 py-3 text-sm text-rose-100" role="alert" tabindex="-1" id="serverValidationErrors">
                <strong>{{ $isAr ? 'راجع الحقول التالية:' : 'Please review the following:' }}</strong>
                <ul class="mt-2 list-disc ps-5 space-y-1 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            <script>document.getElementById('serverValidationErrors')?.focus();</script>
        @endif

        <form action="{{ route('reels.store') }}" method="POST" enctype="multipart/form-data" id="wizardMultiStepForm">
            @csrf

            <!-- Hidden Inputs to carry dynamic values -->
            <input type="hidden" name="hook_headline" id="hiddenHookHeadline" value="">
            <input type="hidden" name="template" id="hiddenTemplate" value="classic">
            <input type="hidden" name="accent_color" id="hiddenAccentColor" value="#fb7185">

            <!-- ================= STEP 1: معلومات الفيديو ================= -->
            <div id="stepContainer1" class="space-y-6">
                <div class="studio-card p-6 sm:p-8 space-y-4">
                    <h2 class="text-xl font-black text-white">شو الفيديو اللي بدك تعمله؟</h2>
                    <p class="text-xs text-slate-400">اكتب وصف الفيديو والموضوع. يرسل المحرك الطلب إلى Gemini ويتطلب مفتاحاً مفعّلاً؛ من دون مفتاح صالح يتوقف التوليد ولا يُنشأ نص محلي بديل.</p>

                    <textarea id="inputUserPrompt" name="user_prompt" rows="3" required
                        placeholder="مثلاً: فيديو قصير يشرح ثلاث طرق لتنظيم الوقت بأسلوب عملي ومباشر"
                        class="w-full p-4 rounded-2xl bg-slate-900 border border-slate-700 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm leading-relaxed"></textarea>

                    <div class="pt-2">
                        <label for="inputTopic" class="block text-xs font-bold text-slate-300 mb-1.5">الموضوع أو الفكرة اللي ببالك (اختياري)</label>
                        <input type="text" id="inputTopic" name="topic" value="" placeholder="مثلاً: تنظيم الوقت"
                            class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm">
                    </div>

                    <div class="rounded-2xl border border-slate-800 bg-slate-950/50 p-4 space-y-3">
                        <label for="generationMode" class="block text-xs font-bold text-slate-200">{{ $isAr ? 'طريقة التوليد' : 'Generation mode' }}</label>
                        <select id="generationMode" name="generation_mode" onchange="toggleGenerationOptions(this.value)" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            <option value="single" {{ old('generation_mode', 'single') === 'single' ? 'selected' : '' }}>{{ $isAr ? 'فيديو واحد' : 'One video' }}</option>
                            <option value="variants" {{ old('generation_mode') === 'variants' ? 'selected' : '' }}>{{ $isAr ? 'نسخ متعددة لنفس الفكرة' : 'Multiple versions of one idea' }}</option>
                            <option value="batch" {{ old('generation_mode') === 'batch' ? 'selected' : '' }}>{{ $isAr ? 'دفعة مواضيع مختلفة' : 'Batch of different topics' }}</option>
                        </select>
                        <div id="variantOptions" class="hidden space-y-2">
                            <label for="variantCount" class="block text-[11px] font-bold text-slate-300">{{ $isAr ? 'عدد النسخ' : 'Number of versions' }}</label>
                            <select id="variantCount" name="variant_count" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                                @foreach ([3, 4, 5] as $variantCount)
                                    <option value="{{ $variantCount }}" {{ (int) old('variant_count', 3) === $variantCount ? 'selected' : '' }}>{{ $variantCount }}</option>
                                @endforeach
                            </select>
                            <p class="text-[10px] text-slate-400">{{ $isAr ? 'تختلف النسخ في الهوك والنبرة والدعوة للتفاعل، مع فروق بسيطة في المدة.' : 'Versions vary their hook, tone and call to action, with modest duration changes.' }}</p>
                        </div>
                        <div id="batchOptions" class="hidden space-y-2">
                            <label for="batchTopics" class="block text-[11px] font-bold text-slate-300">{{ $isAr ? 'موضوع واحد في كل سطر (2–5)' : 'One topic per line (2–5)' }}</label>
                            <textarea id="batchTopics" name="batch_topics" rows="4" placeholder="{{ $isAr ? "تنظيم الوقت\nعادات القراءة\nالتركيز في العمل" : "Time management\nReading habits\nFocus at work" }}" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs leading-relaxed">{{ old('batch_topics') }}</textarea>
                            <p class="text-[10px] text-slate-400">{{ $isAr ? 'تُنشأ مهمة منفصلة لكل موضوع، وبحد أقصى خمسة فيديوهات في الطلب.' : 'A separate render is queued for each topic, up to five videos per request.' }}</p>
                        </div>
                    </div>
                </div>

                <!-- مدة الفيديو -->
                <div class="studio-card p-6 sm:p-8 space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-black text-white">مدة الفيديو</h2>
                        <span class="text-xs text-amber-400 font-bold">اختر المدة التي تناسب كمية المعلومات</span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-3">
                        <label class="option-pill text-center block" onclick="selectDuration(5, this)"><input type="radio" name="duration" value="5" class="sr-only"><span class="text-xl font-black text-white block">5</span><span class="text-[11px] text-slate-400 block">ثواني</span><span class="text-[9px] text-amber-400 block mt-1">سريع</span></label>
                        <label class="option-pill text-center block active border-amber-500" onclick="selectDuration(10, this)"><input type="radio" name="duration" value="10" checked class="sr-only"><span class="text-xl font-black text-white block">10</span><span class="text-[11px] text-slate-400 block">ثانية</span><span class="text-[9px] text-emerald-400 block mt-1">مباشر</span></label>
                        <label class="option-pill text-center block" onclick="selectDuration(15, this)"><input type="radio" name="duration" value="15" class="sr-only"><span class="text-xl font-black text-white block">15</span><span class="text-[11px] text-slate-400 block">ثانية</span><span class="text-[9px] text-slate-400 block mt-1">قائمة قصيرة</span></label>
                        <label class="option-pill text-center block" onclick="selectDuration(20, this)"><input type="radio" name="duration" value="20" class="sr-only"><span class="text-xl font-black text-white block">20</span><span class="text-[11px] text-slate-400 block">ثانية</span><span class="text-[9px] text-indigo-400 block mt-1">الأنسب</span></label>
                        <label class="option-pill text-center block" onclick="selectDuration(30, this)"><input type="radio" name="duration" value="30" class="sr-only"><span class="text-xl font-black text-white block">30</span><span class="text-[11px] text-slate-400 block">ثانية</span><span class="text-[9px] text-purple-400 block mt-1">تفاصيل</span></label>
                        <label class="option-pill text-center block" onclick="selectDuration(40, this)"><input type="radio" name="duration" value="40" class="sr-only"><span class="text-xl font-black text-white block">40</span><span class="text-[11px] text-slate-400 block">ثانية</span><span class="text-[9px] text-pink-400 block mt-1">كاملة</span></label>
                        <label class="option-pill text-center block" onclick="selectDuration(60, this)"><input type="radio" name="duration" value="60" class="sr-only"><span class="text-xl font-black text-white block">60</span><span class="text-[11px] text-slate-400 block">ثانية</span><span class="text-[9px] text-rose-400 block mt-1">مفصلة</span></label>
                    </div>
                </div>

                <div class="studio-card p-6 sm:p-8 space-y-3">
                    <label for="wizardAspectRatio" class="block text-xs font-bold text-slate-300">نسبة أبعاد التصدير • Aspect ratio</label>
                    <select id="wizardAspectRatio" name="aspect_ratio" onchange="updateAspectRatioPreview()" class="w-full sm:max-w-sm px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                        <option value="9:16" selected>عمودي 9:16 — Reels / Shorts</option>
                        <option value="1:1">مربع 1:1</option>
                        <option value="16:9">أفقي 16:9</option>
                    </select>
                    <p class="text-[10px] leading-relaxed text-slate-400">تتغير أبعاد المعاينة والتصدير معاً؛ محتوى المصدر يُقصّ لملاءمة النسبة.</p>
                </div>

                <div class="studio-card p-6 sm:p-8 space-y-3">
                    <label for="wizardVideoUpload" class="block text-xs font-bold text-slate-200">فيديو المصدر (اختياري)</label>
                    <p class="text-[11px] leading-relaxed text-slate-400">اختر مقطعك الخاص لاستخدامه خلف بطاقات النص. عند رفعه يتقدّم على الخلفية المتحركة المختارة.</p>
                    <input type="file" id="wizardVideoUpload" name="videos[]" multiple accept="video/mp4,video/quicktime,video/webm" onchange="showWizardVideoName(this)" class="w-full text-xs text-slate-300 file:ml-3 file:rounded-lg file:border-0 file:bg-amber-500 file:px-3 file:py-2 file:font-bold file:text-slate-950">
                    <p id="wizardVideoFileName" role="status" aria-live="polite" class="hidden text-[11px] text-emerald-300"></p>
                    <video id="wizardSourcePreview" class="hidden max-h-48 w-full rounded-xl border border-slate-700 bg-slate-950 object-contain" controls muted playsinline></video>
                    <p id="wizardSourceOverrideNotice" class="hidden text-[10px] text-amber-300">سيُستخدم الفيديو المرفوع بدلاً من الخلفية المختارة.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-slate-800">
                    <div>
                        <label for="wizardLanguage" class="block text-xs font-bold text-slate-300 mb-1.5">اللغة • Language</label>
                        <select id="wizardLanguage" name="language" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            <option value="en" {{ $lang === 'en' ? 'selected' : '' }}>🇺🇸 English</option>
                            <option value="ar" {{ $lang === 'ar' ? 'selected' : '' }}>🇸🇦 العربية</option>
                        </select>
                    </div>
                    <div>
                        <label for="wizardNiche" class="block text-xs font-bold text-slate-300 mb-1.5">النيش • Niche</label>
                        <select id="wizardNiche" name="niche" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            <option value="productivity" selected>⚙️ إنتاجية</option>
                            <option value="money">💰 مال وثراء</option>
                            <option value="habits">☀️ عادات</option>
                            <option value="psychology">🧠 علم نفس</option>
                            <option value="tech">⚡ تكنولوجيا</option>
                            <option value="business">📈 أعمال</option>
                        </select>
                    </div>
                    <div>
                        <label for="wizardTemplate" class="block text-xs font-bold text-slate-300 mb-1.5">القالب • Template</label>
                        <select id="wizardTemplate" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs" onchange="const h=document.getElementById('hiddenTemplate'); if (h) h.value=this.value; selectTemplate(this.value, document.querySelector(`#subPanelTemplates button[onclick*=\"${this.value}\"]`));">
                            <option value="classic" selected>Classic</option>
                            <option value="clean_news">Clean News</option>
                            <option value="cinematic">Cinematic Glass</option>
                            <option value="quote_card">Quote Card</option>
                            <option value="bold_stripe">Bold Stripe</option>
                        </select>
                    </div>
                    <div>
                        <label for="wizardGoal" class="block text-xs font-bold text-slate-300 mb-1.5">الهدف • Goal</label>
                        <select id="wizardGoal" name="goal" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            <option value="followers">👥 متابعين</option>
                            <option value="saves">🔖 حفظ</option>
                            <option value="shares">🚀 مشاركات</option>
                            <option value="comments">💬 تعليقات</option>
                        </select>
                    </div>
                    <div>
                        <label for="targetCountry" class="block text-xs font-bold text-slate-300 mb-1.5">السوق المستهدف • Target market</label>
                        <select id="targetCountry" name="target_country" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            <option value="Global" selected>Global / عالمي</option>
                            <option value="US">United States</option>
                            <option value="UK">United Kingdom</option>
                            <option value="Canada">Canada</option>
                            <option value="Australia">Australia</option>
                        </select>
                    </div>
                    <div>
                        <label for="formatType" class="block text-xs font-bold text-slate-300 mb-1.5">صيغة الفيديو • Format</label>
                        <select id="formatType" name="format_type" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            <option value="full_idea" selected>فكرة كاملة</option>
                            <option value="quick_tip">نصيحة سريعة</option>
                            <option value="list">قائمة</option>
                            <option value="story">قصة</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-3 pt-2 border-t border-slate-800">
                    <label class="block text-xs font-bold text-slate-300 mb-1">مفاتيح Gemini لهذه العملية (مطلوبة إذا لم تحفظ مفتاحاً)</label>
                    <textarea name="gemini_keys" rows="2" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs font-mono"></textarea>
                    <p class="text-[10px] leading-relaxed text-slate-400">تُستخدم مفاتيح Gemini النشطة المحفوظة تلقائياً؛ يمكنك إضافة مفاتيح مؤقتة لهذه العملية. لإدارتها اضغط «إدارة المفاتيح» بالأعلى. قد تُحتسب الطلبات لدى المزود.</p>
                </div>

                <button type="button" onclick="triggerAiIdea()" class="w-full py-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-base transition shadow-xl shadow-amber-500/20 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>{{ $isAr ? 'اقترح فكرة عبر Gemini' : 'Generate an idea with Gemini' }}</span>
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
            </div>

            <!-- ================= STEP 2: فكرة الفيديو ================= -->
            <div id="stepContainer2" class="hidden space-y-6">
                <div class="text-center space-y-1 mb-4">
                    <h2 class="text-2xl font-black text-white">فكرة الفيديو</h2>
                    <p class="text-xs text-slate-400">فكرة وحدة مبنية على معلوماتك. اعتمدها أو اطلب فكرة مختلفة.</p>
                </div>

                <div class="studio-card p-6 sm:p-8 space-y-5 border-2 border-slate-800">
                    <div class="flex items-center justify-between">
                        <span id="ideaBadge" class="text-xs bg-amber-500/20 text-amber-300 px-3 py-1 rounded-full font-bold">قالب اقتراح</span>
                        <span class="text-xs text-slate-400">اقتراح محلي قابل للتعديل</span>
                    </div>

                    <h3 id="ideaTitle" class="text-2xl sm:text-3xl font-black text-white tracking-tight leading-snug">
                        3 Practical Steps for Your Topic
                    </h3>

                    <div class="space-y-3 pt-2 border-t border-slate-800/80 text-xs text-slate-300 leading-relaxed">
                        <p id="ideaDesc">حوّل الموضوع الذي أدخلته إلى ثلاث خطوات واضحة يمكن شرحها في فيديو قصير.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-slate-400 pt-2 border-t border-slate-800/60 text-[11px]">
                            <div><strong class="text-slate-300">ليش هالفكرة:</strong> <span id="ideaWhy">تعتمد على الموضوع المكتوب، ولا تستخدم بيانات بحث أو انتشار.</span></div>
                            <div><strong class="text-slate-300">الزاوية:</strong> <span id="ideaAngle">قائمة موجزة من فكرة واحدة وثلاث خطوات قابلة للتخصيص.</span></div>
                        </div>
                    </div>

                    <div class="pt-4 flex flex-col sm:flex-row gap-3">
                        <button type="button" onclick="goToStep(3)" class="flex-1 py-3.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-sm transition shadow-lg shadow-amber-500/20 flex items-center justify-center gap-2">
                            <span>اعتمد الفكرة</span>
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>
                        <button type="button" onclick="triggerAiIdea()" class="px-5 py-3.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs border border-slate-700 transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-shuffle"></i>
                            <span>جرّب فكرة مختلفة</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ================= STEP 3: الهوك ================= -->
            <div id="stepContainer3" class="hidden space-y-6">
                <div class="text-center space-y-1 mb-4">
                    <h2 class="text-2xl font-black text-white">الهوك</h2>
                    <p class="text-xs text-slate-400">الهوك عنوان افتتاحي مختصر قد يساعد على جذب الانتباه؛ لا يضمن التفاعل.</p>
                </div>

                <div class="studio-card p-6 sm:p-8 space-y-5 border-2 border-slate-800">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">الفكرة المعتمدة: <strong class="text-white" id="hookIdeaRef">موضوع الفيديو</strong></span>
                        <span id="hookBadge" class="text-xs bg-amber-500/20 text-amber-300 px-3 py-1 rounded-full font-bold">قالب قابل للتعديل</span>
                    </div>

                    <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 text-center space-y-2">
                        <h3 id="hookHeadline" class="text-2xl sm:text-3xl font-black text-white leading-snug">
                            {{ $isAr ? '٣ خطوات بسيطة للبدء' : '3 Simple Steps to Get Started' }}
                        </h3>
                        <p id="hookDesc" class="text-xs text-slate-400">عنوان تمهيدي قابل للتعديل؛ تأكد أن الفيديو يجيب عنه بدقة.</p>
                    </div>

                    <div class="pt-2 flex flex-col sm:flex-row gap-3">
                        <button type="button" onclick="goToStep(4)" class="flex-1 py-3.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-sm transition shadow-lg shadow-amber-500/20 flex items-center justify-center gap-2">
                            <span>اكتب النص بهالهوك</span>
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>
                        <button type="button" onclick="triggerAiHook()" class="px-5 py-3.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs border border-slate-700 transition flex items-center justify-center gap-2">
                            <i class="fa-solid fa-shuffle"></i>
                            <span>اقترح عنواناً بديلاً</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ================= STEP 4: النص ومحرر السكريبت ================= -->
            <div id="stepContainer4" class="hidden space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    <!-- Live preview card (left 5 cols) -->
                    <div class="lg:col-span-5 studio-card p-6 lg:sticky lg:top-24 space-y-4">
                            <div class="reel-preview-frame rounded-2xl bg-[#030712] bg-cover bg-center flex flex-col justify-between border border-slate-800 relative overflow-hidden" id="step4PreviewBox">
                                <div class="flex items-center justify-between text-[10px] text-slate-400">
                                    <span>{{ old('handle', '') }}</span>
                                    <span class="bg-amber-500/20 text-amber-300 px-1.5 py-0.5 rounded">مساحة النص</span>
                                </div>
                                <div class="my-auto space-y-3 px-2">
                                <div class="text-[11px] text-slate-300 text-center font-bold" id="step4CardHead">{{ $isAr ? 'ثلاث خطوات بسيطة للبدء' : '3 Simple Steps to Get Started' }}</div>
                                <div class="p-3.5 bg-slate-900/90 rounded-2xl border border-slate-700/80 shadow-2xl space-y-2">
                                    <div class="text-sm font-black text-white" id="step4CardLine1"><span class="text-amber-400">1.</span> {{ $isAr ? 'حدّد خطوة واحدة واضحة للبدء.' : 'Choose one clear action to begin.' }}</div>
                                    <div class="text-xs text-slate-300" id="step4CardLine2">{{ $isAr ? 'قسّم الفكرة إلى خطوات صغيرة قابلة للتكرار.' : 'Break the idea into small steps you can repeat.' }}</div>
                                    <div class="text-xs text-amber-300 font-bold pt-1" id="step4CardLine3">{{ $isAr ? 'راجع ما نجح وعدّل خطوتك التالية.' : 'Review what worked and adjust your next step.' }}</div>
                                </div>
                            </div>
                            <div class="text-center"><span class="px-3 py-1 rounded-md bg-white text-slate-950 font-black text-[10px]">{{ $isAr ? 'احفظ هذه القائمة للرجوع إليها.' : 'Save this checklist for later.' }}</span></div>
                        </div>
                    </div>

                    <!-- Right Controls (7 cols) -->
                    <div class="lg:col-span-7 space-y-5">
                        <div class="studio-card p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-bold text-white">{{ $isAr ? 'المشهد 2 (النقطة الأولى)' : 'Scene 2 (script point 1)' }}</h3>
                                <div class="flex items-center gap-1.5 text-xs">
                                    <button type="button" onclick="refineScriptAi('punctuate')" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-amber-400 font-bold text-[11px] border border-slate-700">أضف علامة ترقيم</button>
                                    <button type="button" onclick="refineScriptAi('shorten')" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-[11px] border border-slate-700">قصّ إلى 9 كلمات</button>
                                    <button type="button" onclick="refineScriptAi('simple')" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-[11px] border border-slate-700">أزل علامات التمييز</button>
                                </div>
                            </div>

                            <label for="inputLine1" class="sr-only">النقطة الأولى</label><input type="text" name="line1" id="inputLine1" value="{{ $isAr ? 'حدّد خطوة واحدة واضحة للبدء.' : 'Choose one clear action to begin.' }}" aria-label="النقطة الأولى" oninput="updateScriptPreview()" class="w-full px-3 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs font-semibold">
                            <label for="inputLine2" class="sr-only">النقطة الثانية</label><input type="text" name="line2" id="inputLine2" value="{{ $isAr ? 'قسّم الفكرة إلى خطوات صغيرة قابلة للتكرار.' : 'Break the idea into small steps you can repeat.' }}" aria-label="النقطة الثانية" oninput="updateScriptPreview()" class="w-full px-3 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            <label for="inputLine3" class="sr-only">النقطة الثالثة</label><input type="text" name="line3" id="inputLine3" value="{{ $isAr ? 'راجع ما نجح وعدّل خطوتك التالية.' : 'Review what worked and adjust your next step.' }}" aria-label="النقطة الثالثة" oninput="updateScriptPreview()" class="w-full px-3 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">

                            <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-300 flex items-start gap-2">
                                <i class="fa-solid fa-triangle-exclamation text-amber-400 mt-0.5"></i>
                                <span>تحقق من أي ادعاء مالي أو صحي أو قانوني من مصدر موثوق؛ القوالب لا تُعدّ نصيحة مهنية.</span>
                            </div>

                            <button type="button" onclick="goToStep(5)" class="w-full py-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-sm transition mt-4">
                                المتابعة إلى خطوة التصميم &larr;
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= STEP 5: التصميم ================= -->
            <div id="stepContainer5" class="hidden space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

                    <!-- Text-card preview (not a rendered video) -->
                    <div class="lg:col-span-5 lg:sticky lg:top-24 space-y-4">
                        <div class="studio-card p-4 space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-white text-sm">{{ $isAr ? 'معاينة حقيقية من المحرك' : 'Real engine preview' }}</h3>
                                    <p class="text-[10px] leading-relaxed text-slate-400">{{ $isAr ? 'تُرسم بنفس الخط والتخطيط المستخدم في التصدير، وليست محاكاة HTML.' : 'Rendered with the same font and layout used for export, not an HTML approximation.' }}</p>
                                </div>
                                <button type="button" onclick="requestRealPreview()" id="realPreviewBtn" class="flex-shrink-0 rounded-xl bg-amber-500 px-3 py-2 text-[11px] font-black text-slate-950 transition hover:bg-amber-400 disabled:opacity-60">
                                    {{ $isAr ? 'تحديث المعاينة' : 'Refresh preview' }}
                                </button>
                            </div>
                            <img id="realPreviewImage" alt="" class="hidden mx-auto max-h-[520px] rounded-2xl border border-slate-700 bg-black">
                            <p id="realPreviewStatus" role="status" aria-live="polite" class="text-[11px] text-slate-400"></p>
                        </div>

                        <div class="studio-card p-6 space-y-4">
                        <div class="reel-preview-frame rounded-2xl bg-[#030712] bg-cover bg-center p-4 flex flex-col justify-between border border-slate-800 relative overflow-hidden" id="liveDesignCardBox">
                            <div class="flex items-center justify-between text-[10px] text-slate-400 z-10">
                                <span id="cardLiveHandle" dir="ltr">{{ old('handle', '') }}</span>
                                <span class="bg-amber-500/20 text-amber-300 px-1.5 py-0.5 rounded">مساحة النص</span>
                            </div>

                            <div class="my-auto space-y-3 px-2 z-10">
                                <div class="text-[11px] text-slate-300 text-center font-bold" id="step5CardHead">{{ $isAr ? 'ثلاث خطوات بسيطة للبدء' : '3 Simple Steps to Get Started' }}</div>
                                <div class="p-4 bg-slate-900/90 rounded-2xl border border-slate-700/80 shadow-2xl space-y-2 backdrop-blur-md" id="step5CardInner">
                                    <div class="text-sm font-black text-white" id="step5CardLine1">
                                        <span class="text-amber-400">1.</span> {{ $isAr ? 'حدّد خطوة واحدة واضحة للبدء.' : 'Choose one clear action to begin.' }}
                                    </div>
                                    <div class="text-xs text-slate-300" id="step5CardLine2">
                                        {{ $isAr ? 'قسّم الفكرة إلى خطوات صغيرة قابلة للتكرار.' : 'Break the idea into small steps you can repeat.' }}
                                    </div>
                                    <div class="text-xs text-amber-300 font-bold pt-1" id="step5CardLine3">
                                        {{ $isAr ? 'راجع ما نجح وعدّل خطوتك التالية.' : 'Review what worked and adjust your next step.' }}
                                    </div>
                                </div>
                            </div>

                            <div class="text-center z-10">
                                <span class="px-3 py-1 rounded-md bg-white text-slate-950 font-black text-[10px]">{{ $isAr ? 'احفظ هذه القائمة للرجوع إليها.' : 'Save this checklist for later.' }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-slate-900 border border-slate-800 p-3 text-[11px] leading-relaxed text-slate-400">
                            هذه معاينة لبطاقات النص؛ المعاينة الزمنية التفاعلية تظهر في خطوة التصدير التالية.
                        </div>
                    </div>

                    <!-- Right Controls (7 Cols) -->
                    <div class="lg:col-span-7 studio-card p-6 sm:p-8 space-y-6">

                        @include('reels.partials.background-picker', ['backgrounds' => $backgrounds, 'lang' => $lang])

                        <!-- Sub-Tabs Navigation -->
                        <div class="flex items-center gap-3 border-b border-slate-800 pb-3 overflow-x-auto text-xs font-bold">
                            <button type="button" onclick="switchDesignSubTab('colors')" id="dst_colors" class="text-amber-400 border-b-2 border-amber-400 pb-2 px-1 flex items-center gap-1.5">
                                <i class="fa-solid fa-palette"></i> الألوان والحركة
                            </button>
                            <button type="button" onclick="switchDesignSubTab('music')" id="dst_music" class="text-slate-400 pb-2 px-1 flex items-center gap-1.5 hover:text-white">
                                <i class="fa-solid fa-music"></i> الموسيقى
                            </button>
                            <button type="button" onclick="switchDesignSubTab('scenes')" id="dst_scenes" class="text-slate-400 pb-2 px-1 flex items-center gap-1.5 hover:text-white">
                                <i class="fa-solid fa-layer-group"></i> المشاهد
                            </button>
                            <button type="button" onclick="switchDesignSubTab('templates')" id="dst_templates" class="text-slate-400 pb-2 px-1 flex items-center gap-1.5 hover:text-white">
                                <i class="fa-solid fa-shapes"></i> القالب
                            </button>
                        </div>

                        <!-- PANEL A: الألوان والحركة -->
                        <div id="subPanelColors" class="space-y-5">
                            <div>
                                <label for="motionPreset" class="block text-xs font-bold text-slate-300 mb-1.5">{{ $isAr ? 'مكتبة المشاهد والحركة' : 'Scene & motion library' }}</label>
                                <select id="motionPreset" name="motion_preset" onchange="applyMotionPreset(this.value)" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-amber-500/30 text-white text-xs">
                                    <option value="custom" @selected(old('motion_preset', 'custom') === 'custom')>{{ $isAr ? 'تخصيص يدوي' : 'Custom setup' }}</option>
                                    <option value="subtle_story" @selected(old('motion_preset') === 'subtle_story')>{{ $isAr ? 'قصة هادئة — Zoom + Fade' : 'Subtle story — Zoom + Fade' }}</option>
                                    <option value="cinematic_depth" @selected(old('motion_preset') === 'cinematic_depth')>{{ $isAr ? 'عمق سينمائي — Parallax + انتقال متنوع' : 'Cinematic depth — Parallax + varied cuts' }}</option>
                                    <option value="news_wipe" @selected(old('motion_preset') === 'news_wipe')>{{ $isAr ? 'أخبار — Pan + Wipe' : 'News — Pan + Wipe' }}</option>
                                    <option value="hook_focus" @selected(old('motion_preset') === 'hook_focus')>{{ $isAr ? 'تركيز على الهوك — كلمة بكلمة' : 'Hook focus — word by word' }}</option>
                                    <option value="minimal" @selected(old('motion_preset') === 'minimal')>{{ $isAr ? 'هادئ — بلا حركة خلفية' : 'Minimal — static background' }}</option>
                                </select>
                                <p class="mt-1 text-[10px] leading-relaxed text-slate-400">{{ $isAr ? 'اختر وصفة جاهزة ثم عدّل أي خيار أدناه. الحركات مطبّقة في ملف التصدير.' : 'Choose a ready preset, then adjust any setting below. Motion is applied to the exported video.' }}</p>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-300">ألوان الكلمات المميزة</span>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="color-dot bg-white" aria-label="أبيض" aria-pressed="false" onclick="pickAccentColor('#ffffff', this)"></button>
                                    <button type="button" class="color-dot bg-amber-100" aria-label="كهرماني فاتح" aria-pressed="false" onclick="pickAccentColor('#fef3c7', this)"></button>
                                    <button type="button" class="color-dot bg-sky-400" aria-label="أزرق سماوي" aria-pressed="false" onclick="pickAccentColor('#38bdf8', this)"></button>
                                    <button type="button" class="color-dot bg-emerald-400" aria-label="أخضر" aria-pressed="false" onclick="pickAccentColor('#34d399', this)"></button>
                                    <button type="button" class="color-dot bg-rose-400 active" aria-label="وردي" aria-pressed="true" onclick="pickAccentColor('#fb7185', this)"></button>
                                    <button type="button" class="color-dot bg-amber-400" aria-label="كهرماني" aria-pressed="false" onclick="pickAccentColor('#fbbf24', this)"></button>
                                </div>
                            </div>

                            <!-- Font Size Slider -->
                            <div class="space-y-1.5">
                                <div class="flex justify-between text-xs">
                                    <label for="fontScaleSlider" class="text-slate-300 font-bold">حجم الخط</label>
                                    <span class="text-amber-400 font-bold" id="fontSizeVal">100%</span>
                                </div>
                                <input type="range" id="fontScaleSlider" name="font_scale" min="80" max="130" value="100" aria-label="حجم الخط من 80 إلى 130 بالمئة" class="w-full accent-amber-500 h-1.5 bg-slate-800 rounded-lg cursor-pointer" oninput="changeFontSize(this.value)">
                            </div>

                            <div class="space-y-1.5">
                                <div class="flex justify-between text-xs">
                                    <label for="backgroundDimming" class="text-slate-300 font-bold">تعتيم الخلفية</label>
                                    <span class="text-amber-400 font-bold" id="backgroundDimmingVal">35%</span>
                                </div>
                                <input type="range" id="backgroundDimming" name="background_dimming" min="0" max="80" value="35" aria-label="تعتيم الخلفية من صفر إلى 80 بالمئة" class="w-full accent-amber-500 h-1.5 bg-slate-800 rounded-lg cursor-pointer" oninput="changeBackgroundDimming(this.value)">
                            </div>

                            <div>
                                <label for="cameraMotion" class="block text-xs font-bold text-slate-300 mb-1.5">{{ $isAr ? 'حركة الخلفية' : 'Background motion' }}</label>
                                <select id="cameraMotion" name="camera_motion" onchange="markMotionPresetCustom()" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                                    <option value="zoom_in" @selected(old('camera_motion', 'zoom_in') === 'zoom_in')>{{ $isAr ? 'تقريب هادئ' : 'Gentle zoom in' }}</option>
                                    <option value="pan" @selected(old('camera_motion') === 'pan')>{{ $isAr ? 'تحريك أفقي' : 'Horizontal pan' }}</option>
                                    <option value="parallax" @selected(old('camera_motion') === 'parallax')>{{ $isAr ? 'عمق Parallax خفيف' : 'Subtle parallax-style depth' }}</option>
                                    <option value="static" @selected(old('camera_motion') === 'static')>{{ $isAr ? 'ثابت' : 'Static' }}</option>
                                </select>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="textMotion" class="block text-xs font-bold text-slate-300 mb-1.5">{{ $isAr ? 'دخول النص' : 'Text entrance' }}</label>
                                    <select id="textMotion" name="text_motion" onchange="markMotionPresetCustom()" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                                        <option value="vertical" @selected(old('text_motion', 'vertical') === 'vertical')>{{ $isAr ? 'كشف عمودي هادئ' : 'Soft vertical reveal' }}</option>
                                        <option value="slide_right" @selected(old('text_motion') === 'slide_right')>{{ $isAr ? 'دخول من اليمين' : 'Enter from right' }}</option>
                                        <option value="slide_left" @selected(old('text_motion') === 'slide_left')>{{ $isAr ? 'دخول من اليسار' : 'Enter from left' }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="sceneTransition" class="block text-xs font-bold text-slate-300 mb-1.5">{{ $isAr ? 'الانتقال بين المشاهد' : 'Scene transitions' }}</label>
                                    <select id="sceneTransition" name="scene_transition" onchange="markMotionPresetCustom()" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                                        <option value="template" @selected(old('scene_transition', 'template') === 'template')>{{ $isAr ? 'متوافق مع القالب' : 'Match the selected template' }}</option>
                                        <option value="fade" @selected(old('scene_transition') === 'fade')>Fade</option>
                                        <option value="wipe" @selected(old('scene_transition') === 'wipe')>Wipe</option>
                                        <option value="slide" @selected(old('scene_transition') === 'slide')>{{ $isAr ? 'انزلاق' : 'Slide' }}</option>
                                        <option value="varied" @selected(old('scene_transition') === 'varied')>{{ $isAr ? 'متنوعة بالتناوب بين المشاهد' : 'Alternate transitions by scene' }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="flex items-start gap-2 rounded-xl border border-slate-800 bg-slate-900 p-3 text-[11px] text-slate-300">
                                    <input type="checkbox" id="hookWordByWord" name="hook_word_by_word" value="1" onchange="markMotionPresetCustom()" @checked(old('hook_word_by_word')) class="mt-0.5 h-4 w-4 accent-amber-500">
                                    <span>{{ $isAr ? 'إظهار الهوك كلمةً بكلمة وإبراز الكلمة الحالية' : 'Reveal the hook word by word and highlight the current word' }}</span>
                                </label>
                                <label class="flex items-start gap-2 rounded-xl border border-slate-800 bg-slate-900 p-3 text-[11px] text-slate-300">
                                    <input type="hidden" name="highlight_important" value="0">
                                    <input type="checkbox" id="highlightImportant" name="highlight_important" value="1" onchange="markMotionPresetCustom()" @checked(old('highlight_important', '1')) class="mt-0.5 h-4 w-4 accent-amber-500">
                                    <span>{{ $isAr ? 'تمييز الكلمات المهمة بلون التوكيد' : 'Highlight emphasized words with the accent color' }}</span>
                                </label>
                            </div>

                            <script>
                                window.applyMotionPreset = function (name) {
                                    const presets = {
                                        subtle_story: { camera: 'zoom_in', text: 'vertical', transition: 'fade', hook: false, highlight: true },
                                        cinematic_depth: { camera: 'parallax', text: 'slide_left', transition: 'varied', hook: false, highlight: true },
                                        news_wipe: { camera: 'pan', text: 'slide_right', transition: 'wipe', hook: false, highlight: true },
                                        hook_focus: { camera: 'zoom_in', text: 'vertical', transition: 'slide', hook: true, highlight: true },
                                        minimal: { camera: 'static', text: 'vertical', transition: 'fade', hook: false, highlight: false }
                                    };
                                    const preset = presets[name];
                                    if (!preset) return;
                                    document.getElementById('cameraMotion').value = preset.camera;
                                    document.getElementById('textMotion').value = preset.text;
                                    document.getElementById('sceneTransition').value = preset.transition;
                                    document.getElementById('hookWordByWord').checked = preset.hook;
                                    document.getElementById('highlightImportant').checked = preset.highlight;
                                };
                                window.markMotionPresetCustom = function () {
                                    const preset = document.getElementById('motionPreset');
                                    if (preset) preset.value = 'custom';
                                };
                            </script>

                            <label class="flex items-start gap-3 rounded-xl border border-slate-800 bg-slate-900 p-3 text-xs text-slate-300">
                                <input type="checkbox" name="anti_border" value="1" checked class="mt-0.5 h-4 w-4 accent-amber-500">
                                <span><strong class="block text-white">مراعاة الهوامش الآمنة (تقريبية)</strong><span class="text-[10px] text-slate-400">إزاحة البطاقات عن حواف الفيديو؛ لا تضمن توافقها مع كل واجهة منصة.</span></span>
                            </label>

                            <!-- Page Handle Field -->
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-1.5">اسم الصفحة فوق (اختياري)</label>
                                <input type="text" name="handle" id="inputPageHandle" dir="ltr" value="{{ old('handle', '') }}" placeholder="@اسم_صفحتك" oninput="document.getElementById('cardLiveHandle').innerText = this.value; document.getElementById('s7Handle').innerText = this.value" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                            </div>

                        </div>

                        <!-- PANEL B: الموسيقى -->
                        <div id="subPanelMusic" class="hidden space-y-4">
                            <div class="studio-card p-4 space-y-3">
                                <label for="wizardMusicUpload" class="block text-xs font-bold text-slate-200">ارفع ملفاً صوتياً تملك حق استخدامه</label>
                                <input type="file" id="wizardMusicUpload" name="music" accept=".mp3,.wav,.m4a,audio/mpeg,audio/wav,audio/mp4" onchange="showWizardMusicName(this)" class="w-full text-xs text-slate-300 file:ml-3 file:rounded-lg file:border-0 file:bg-amber-500 file:px-3 file:py-2 file:font-bold file:text-slate-950">
                                <p id="wizardMusicFileName" role="status" aria-live="polite" class="hidden text-[11px] text-emerald-300"></p>
                                <audio id="wizardMusicPreview" class="hidden w-full" controls preload="metadata" aria-label="معاينة الملف الصوتي"></audio>
                                <p class="text-[10px] leading-relaxed text-slate-400">عند وجود تعليق صوتي، تُخفّض الموسيقى تلقائياً لتبقى الكلمات واضحة. ومن دون تعليق أو موسيقى، يحتفظ التصدير بصوت المقطع الأصلي إن وُجد.</p>
                            </div>

                            <div class="studio-card p-4 space-y-3">
                                <label class="flex items-start gap-3 text-xs text-slate-200">
                                    <input type="checkbox" name="voiceover_enabled" value="1" @checked(old('voiceover_enabled')) class="mt-0.5 h-4 w-4 accent-amber-500">
                                    <span><strong class="block text-white">إضافة تعليق صوتي طبيعي (ElevenLabs)</strong><span class="text-[10px] leading-relaxed text-slate-400">يقرأ العنوان والنقاط بالتسلسل، وتطول مدة الفيديو تلقائياً إذا احتاج الكلام وقتاً إضافياً.</span></span>
                                </label>
                                @if ($errors->has('voiceover_enabled'))
                                    <p class="rounded-lg border border-rose-500/30 bg-rose-950/30 p-2 text-[10px] leading-relaxed text-rose-200" role="alert">{{ $errors->first('voiceover_enabled') }}</p>
                                @else
                                    <p class="text-[10px] leading-relaxed text-slate-400">الجاهزية حسب إعدادات الخادم: العربية {{ ($voiceoverAvailable['ar'] ?? false) ? 'جاهزة' : 'تحتاج مفتاح ElevenLabs وVoice ID عربي' }}؛ الإنجليزية {{ ($voiceoverAvailable['en'] ?? false) ? 'جاهزة' : 'تحتاج مفتاح ElevenLabs وVoice ID إنجليزي' }}.</p>
                                @endif
                                <div>
                                    <label for="voiceoverTone" class="mb-1.5 block text-[11px] font-bold text-slate-300">نبرة التعليق</label>
                                    <select id="voiceoverTone" name="voiceover_tone" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-xs text-white">
                                        <option value="neutral" @selected(old('voiceover_tone', 'neutral') === 'neutral')>طبيعية وواضحة</option>
                                        <option value="calm" @selected(old('voiceover_tone') === 'calm')>هادئة</option>
                                        <option value="warm" @selected(old('voiceover_tone') === 'warm')>دافئة وواثقة</option>
                                        <option value="energetic" @selected(old('voiceover_tone') === 'energetic')>حيوية</option>
                                    </select>
                                </div>
                            </div>

                            <div class="rounded-xl border border-slate-800 bg-slate-900 p-3 text-[11px] leading-relaxed text-slate-400">
                                خدمة ElevenLabs اختيارية. لا يظهر مفتاح API في الصفحة؛ يضبطه مالك المنصة في بيئة الخادم مع Voice ID لكل لغة.
                            </div>
                        </div>

                        <!-- PANEL C: المشاهد -->
                        <div id="subPanelScenes" class="hidden space-y-4">
                            <div class="space-y-2">
                                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between text-xs"><span class="font-bold text-white">01</span><span class="text-slate-400">هوك افتتاحي</span></div>
                                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between text-xs"><span class="font-bold text-white">02–04</span><span class="text-slate-400">نقطة واحدة في كل مشهد</span></div>
                                <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between text-xs"><span class="font-bold text-white">04</span><span class="text-slate-400">خاتمة وCTA على المشهد الأخير</span></div>
                                <p class="text-[10px] leading-relaxed text-slate-400">تظهر الفكرة الحالية وحدها بدلاً من إبقاء القائمة كاملة على الشاشة؛ يوزّع التوقيت حسب طول النص والكلام.</p>
                            </div>
                        </div>

                        <!-- PANEL D: القوالب -->
                        <div id="subPanelTemplates" class="hidden space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <button type="button" aria-pressed="false" class="p-4 rounded-xl bg-slate-900 border border-slate-700 text-center hover:border-amber-500" onclick="selectTemplate('clean_news', this)">
                                    <div class="font-bold text-white text-xs">Clean News</div>
                                    <span class="text-[10px] text-slate-400">بطاقات أخبار وشريط أحمر</span>
                                </button>
                                <button type="button" aria-pressed="true" class="p-4 rounded-xl bg-slate-900 border-2 border-amber-500 text-center" onclick="selectTemplate('classic', this)">
                                    <div class="font-bold text-white text-xs">Classic</div>
                                    <span class="text-[10px] text-amber-400">كلاسيكي مميز</span>
                                </button>
                                <button type="button" aria-pressed="false" class="p-4 rounded-xl bg-slate-900 border border-slate-700 text-center hover:border-amber-500" onclick="selectTemplate('cinematic', this)">
                                    <div class="font-bold text-white text-xs">Cinematic Glass</div>
                                    <span class="text-[10px] text-slate-400">زجاجي شفاف</span>
                                </button>
                                <button type="button" aria-pressed="false" class="p-4 rounded-xl bg-slate-900 border border-slate-700 text-center hover:border-amber-500" onclick="selectTemplate('quote_card', this)">
                                    <div class="font-bold text-white text-xs">Quote Card</div>
                                    <span class="text-[10px] text-slate-400">اقتباس بلا إطار</span>
                                </button>
                                <button type="button" aria-pressed="false" class="p-4 rounded-xl bg-slate-900 border border-slate-700 text-center hover:border-amber-500" onclick="selectTemplate('bold_stripe', this)">
                                    <div class="font-bold text-white text-xs">Bold Stripe</div>
                                    <span class="text-[10px] text-slate-400">شريط لوني جسور</span>
                                </button>
                            </div>
                        </div>

                        <!-- Button to Step 6 -->
                        <div class="pt-4 border-t border-slate-800">
                            <button type="button" onclick="goToStep(6)" class="w-full py-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-sm transition shadow-lg shadow-amber-500/20 flex items-center justify-center gap-2">
                                <span>التالي: تقييم الفيديو (فحص الجودة)</span>
                                <i class="fa-solid fa-arrow-left"></i>
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ================= STEP 6: تقييم الفيديو ================= -->
            <div id="stepContainer6" class="hidden space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    <div class="lg:col-span-5 lg:sticky lg:top-24 space-y-4">
                        <div class="studio-card p-4 space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-white text-sm">{{ $isAr ? 'معاينة حقيقية من المحرك' : 'Real engine preview' }}</h3>
                                    <p class="text-[10px] leading-relaxed text-slate-400">{{ $isAr ? 'تُرسم بنفس الخط والتخطيط المستخدم في التصدير، وليست محاكاة HTML.' : 'Rendered with the same font and layout used for export, not an HTML approximation.' }}</p>
                                </div>
                                <button type="button" onclick="requestRealPreview()" id="realPreviewBtn" class="flex-shrink-0 rounded-xl bg-amber-500 px-3 py-2 text-[11px] font-black text-slate-950 transition hover:bg-amber-400 disabled:opacity-60">
                                    {{ $isAr ? 'تحديث المعاينة' : 'Refresh preview' }}
                                </button>
                            </div>
                            <img id="realPreviewImage" alt="" class="hidden mx-auto max-h-[520px] rounded-2xl border border-slate-700 bg-black">
                            <p id="realPreviewStatus" role="status" aria-live="polite" class="text-[11px] text-slate-400"></p>
                        </div>

                        <div class="studio-card p-6 space-y-4">
                        <div class="reel-preview-frame rounded-2xl bg-[#030712] p-4 flex flex-col justify-between border border-slate-800 relative">
                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                <span>{{ old('handle', '') }}</span>
                                <span class="bg-amber-500/20 text-amber-300 px-1.5 py-0.5 rounded">مساحة النص</span>
                            </div>
                            <div class="my-auto space-y-3 px-2">
                                <div class="text-[11px] text-slate-300 text-center font-bold">{{ $isAr ? 'ثلاث خطوات بسيطة للبدء' : '3 Simple Steps to Get Started' }}</div>
                                <div class="p-4 bg-slate-900/90 rounded-2xl border border-slate-700/80 shadow-2xl space-y-2">
                                    <div class="text-sm font-black text-white"><span class="text-amber-400">1.</span> {{ $isAr ? 'حدّد خطوة واحدة واضحة للبدء.' : 'Choose one clear action to begin.' }}</div>
                                    <div class="text-xs text-slate-300">{{ $isAr ? 'قسّم الفكرة إلى خطوات صغيرة قابلة للتكرار.' : 'Break the idea into small steps you can repeat.' }}</div>
                                    <div class="text-xs text-amber-300 font-bold pt-1">{{ $isAr ? 'راجع ما نجح وعدّل خطوتك التالية.' : 'Review what worked and adjust your next step.' }}</div>
                                </div>
                            </div>
                            <div class="text-center"><span class="px-3 py-1 rounded-md bg-white text-slate-950 font-black text-[10px]">{{ $isAr ? 'احفظ هذه القائمة للرجوع إليها.' : 'Save this checklist for later.' }}</span></div>
                        </div>
                    </div>

                    <div class="lg:col-span-7 space-y-5">
                        <div class="studio-card p-6 space-y-4" id="qualityCheckCard">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-black text-white">{{ $isAr ? 'فحص جاهزية قبل التصدير' : 'Pre-export readiness check' }}</h3>
                                    <p class="mt-1 text-xs leading-relaxed text-slate-400">{{ $isAr ? 'يفحص طول الهوك والنص والوتيرة والإعدادات محلياً. لا يتحقق من الحقائق ولا يتنبأ بأداء المنصات.' : 'Checks hook and script length, pacing and export settings. It does not fact-check or predict platform performance.' }}</p>
                                </div>
                                <button type="button" onclick="runWizardQualityCheck()" id="qualityCheckButton" class="rounded-xl bg-amber-500 px-4 py-2 text-xs font-black text-slate-950 hover:bg-amber-400 disabled:opacity-60">{{ $isAr ? 'حلّل الجاهزية' : 'Check readiness' }}</button>
                            </div>
                            <p id="qualityCheckStatus" role="status" aria-live="polite" class="text-xs text-slate-400">{{ $isAr ? 'سيُعاد الفحص تلقائياً قبل إرسال التصدير.' : 'The check runs again automatically before export is submitted.' }}</p>
                            <div id="qualityCheckReport" class="hidden space-y-2" aria-live="polite"></div>
                        </div>

                        <div class="studio-card p-6 space-y-4" id="factCheckCard">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-black text-white">{{ $isAr ? 'فحص الحقائق والمخاطر' : 'Fact-check and risk scan' }}</h3>
                                    <p class="mt-1 text-xs leading-relaxed text-slate-400">{{ $isAr ? 'يفحص الادعاءات بالذكاء الاصطناعي ويحدد ما يحتاج مصدرًا خارجيًا. هذا فحص أولي وليس تحققًا صحفيًا.' : 'Screens claims with AI and flags what needs an external source. This is a pre-check, not journalistic verification.' }}</p>
                                </div>
                                <button type="button" onclick="runWizardFactCheck()" id="factCheckButton" class="rounded-xl border border-sky-400/40 bg-sky-500/10 px-4 py-2 text-xs font-black text-sky-200 hover:bg-sky-500/20 disabled:opacity-60">{{ $isAr ? 'افحص الحقائق' : 'Fact-check' }}</button>
                            </div>
                            <p id="factCheckStatus" role="status" aria-live="polite" class="text-xs text-slate-400">{{ $isAr ? 'استخدمه قبل التصدير خصوصًا للأخبار والصحة والمال والقانون.' : 'Use it before export, especially for news, health, finance, and legal topics.' }}</p>
                            <div id="factCheckReport" class="hidden space-y-2" aria-live="polite"></div>
                        </div>

                        <!-- رأي الخبير التفاعلي -->
                        <div class="studio-card p-6 border border-slate-800 space-y-3">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-bold text-white">اقتراحات تحرير جاهزة</span>
                                <span class="text-[10px] text-slate-400">قوالب محلية</span>
                            </div>
                            <p class="text-xs text-slate-400">نصائح عامة من قوالب محلية، وليست تحليلاً آلياً لمحتوى الفيديو.</p>
                            
                            <div id="expertAdviceList" class="hidden space-y-2 pt-2"></div>

                            <button type="button" onclick="fetchExpertAdvice()" id="askExpertBtn" class="w-full py-3 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-200 text-xs font-bold transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-wand-magic-sparkles text-amber-400"></i>
                                <span>اعرض اقتراحات التحرير</span>
                            </button>
                        </div>

                        <div class="pt-2">
                            <button type="button" onclick="goToStep(7)" class="w-full py-4 rounded-2xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-base transition shadow-xl shadow-amber-500/20 flex items-center justify-center gap-2">
                                <span>المتابعة إلى معاينة التصميم والتصدير</span>
                                <i class="fa-solid fa-arrow-left"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= STEP 7: المعاينة والتصدير ================= -->
            <div id="stepContainer7" class="hidden space-y-6">
                <!-- Preview the design before local video assembly. -->
                <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
                    <div>
                        <h2 class="text-3xl font-black text-white">معاينة تصميم البطاقات</h2>
                        <p class="text-xs text-slate-400 mt-1">
                            هذه معاينة تخطيطية للنص وليست فيديو نهائياً. التصدير يركّب البطاقات على المقطع المصدر.
                            <button type="button" onclick="goToStep(6)" class="text-amber-400 underline font-bold mr-1">إرجع لتقييم الفيديو</button>
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    
                    <!-- Left Column (5 cols): Preview Frame matching Images 2, 3, 4 -->
                    <div class="lg:col-span-5 lg:sticky lg:top-24 space-y-4">
                        <div class="studio-card p-4 space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-white text-sm">{{ $isAr ? 'معاينة حقيقية من المحرك' : 'Real engine preview' }}</h3>
                                    <p class="text-[10px] leading-relaxed text-slate-400">{{ $isAr ? 'تُرسم بنفس الخط والتخطيط المستخدم في التصدير، وليست محاكاة HTML.' : 'Rendered with the same font and layout used for export, not an HTML approximation.' }}</p>
                                </div>
                                <button type="button" onclick="requestRealPreview()" id="realPreviewBtn" class="flex-shrink-0 rounded-xl bg-amber-500 px-3 py-2 text-[11px] font-black text-slate-950 transition hover:bg-amber-400 disabled:opacity-60">
                                    {{ $isAr ? 'تحديث المعاينة' : 'Refresh preview' }}
                                </button>
                            </div>
                            <img id="realPreviewImage" alt="" class="hidden mx-auto max-h-[520px] rounded-2xl border border-slate-700 bg-black">
                            <p id="realPreviewStatus" role="status" aria-live="polite" class="text-[11px] text-slate-400"></p>
                        </div>

                        <div class="studio-card p-6 space-y-4">
                        <div class="reel-preview-frame rounded-2xl bg-[#030712] bg-cover bg-center p-5 flex flex-col justify-between border border-slate-800 relative overflow-hidden" id="step7PreviewCard">
                            <div class="flex items-center justify-between text-[10px] text-slate-400 z-10">
                                <span id="s7Handle" dir="ltr">{{ old('handle', '') }}</span>
                                <span class="bg-amber-500/20 text-amber-300 px-1.5 py-0.5 rounded font-bold">مساحة النص</span>
                            </div>

                            <div class="my-auto space-y-3 px-2 z-10">
                                <div class="text-[12px] text-slate-300 text-center font-bold" id="s7Headline">
                                    {{ $isAr ? 'ثلاث خطوات بسيطة للبدء' : '3 Simple Steps to Get Started' }}
                                </div>
                                <div class="p-4 bg-slate-900/90 rounded-2xl border border-slate-700/80 shadow-2xl space-y-2 backdrop-blur-md">
                                    <div class="text-sm font-black text-white" id="s7Line1">
                                        <span class="text-amber-400">1.</span> {{ $isAr ? 'حدّد خطوة واحدة واضحة للبدء.' : 'Choose one clear action to begin.' }}
                                    </div>
                                    <div class="text-xs text-slate-300" id="s7Line2">
                                        {{ $isAr ? 'قسّم الفكرة إلى خطوات صغيرة قابلة للتكرار.' : 'Break the idea into small steps you can repeat.' }}
                                    </div>
                                    <div class="text-xs text-amber-300 font-bold pt-1" id="s7Line3">
                                        {{ $isAr ? 'راجع ما نجح وعدّل خطوتك التالية.' : 'Review what worked and adjust your next step.' }}
                                    </div>
                                </div>
                            </div>

                            <div class="text-center z-10">
                                <span class="px-3 py-1 rounded-md bg-white text-slate-950 font-black text-[10px]">{{ $isAr ? 'احفظ هذه القائمة للرجوع إليها.' : 'Save this checklist for later.' }}</span>
                            </div>
                        </div>

                        <div class="space-y-3 pt-2" id="previewTimeline" aria-label="مخطط توقيت بطاقات الفيديو">
                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                <span class="font-bold text-white">Timeline المعاينة</span>
                                <span><strong id="timelineCurrentTime">0.0</strong>s / <strong id="timelineDuration">10</strong>s</span>
                            </div>
                            <div class="relative h-2 rounded-full bg-slate-800 overflow-hidden cursor-pointer touch-pan-y focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-400" role="slider" aria-label="موضع المعاينة" aria-valuemin="0" aria-valuemax="10" aria-valuenow="0" tabindex="0" id="timelineTrack">
                                <div id="timelineProgress" class="absolute inset-y-0 right-0 w-0 bg-amber-400 transition-[width] duration-100"></div>
                            </div>
                            <div class="grid grid-cols-3 gap-1.5" id="timelineSegments">
                                <button type="button" data-timeline-segment="0" class="timeline-segment text-right p-2 rounded-lg border border-amber-400/60 bg-amber-500/15 transition" aria-label="البطاقة الأولى">
                                    <span class="block text-[9px] text-amber-300 font-bold">0.0s — 33%</span><span id="timelineLabel1" class="block truncate text-[10px] text-white">النقطة الأولى</span>
                                </button>
                                <button type="button" data-timeline-segment="1" class="timeline-segment text-right p-2 rounded-lg border border-slate-700 bg-slate-900 transition" aria-label="البطاقة الثانية">
                                    <span class="block text-[9px] text-slate-400 font-bold">33% — 66%</span><span id="timelineLabel2" class="block truncate text-[10px] text-white">النقطة الثانية</span>
                                </button>
                                <button type="button" data-timeline-segment="2" class="timeline-segment text-right p-2 rounded-lg border border-slate-700 bg-slate-900 transition" aria-label="البطاقة الثالثة">
                                    <span class="block text-[9px] text-slate-400 font-bold">66% — 100%</span><span id="timelineLabel3" class="block truncate text-[10px] text-white">النقطة الثالثة</span>
                                </button>
                            </div>
                            <p class="text-[10px] text-slate-400">اضغط على أي بطاقة لمعاينة توقيتها وحركتها قبل التصدير.</p>
                        </div>
                    </div>

                    <!-- Right Column (7 cols): Export Box, Music, Captions (Images 2, 3, 4) -->
                    <div class="lg:col-span-7 space-y-6">

                        <!-- Export Box matching Image 4 -->
                        <div class="studio-card p-6 space-y-4 border-2 border-slate-800">
                            <div class="flex items-center justify-between text-xs">
                                <h3 class="font-bold text-white text-base">التصدير</h3>
                                <span class="text-emerald-400 font-bold flex items-center gap-1">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <span>معالجة محلية</span>
                                </span>
                            </div>

                            <!-- Warning box matching Image 4 -->
                            <div class="p-3.5 rounded-xl bg-slate-900 border border-amber-500/30 text-xs text-slate-300 space-y-1">
                                <div class="flex items-center gap-2 text-amber-400 font-bold">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <span>راجع صحة أي ادعاء في النص قبل النشر.</span>
                                </div>
                                <p class="text-[11px] text-slate-400 pr-5">
                                    القوالب ليست تحققاً من الحقائق أو استشارة مهنية. استخدم مصدراً موثوقاً عند الحاجة.
                                    <button type="button" onclick="refineScriptAi('simple')" class="text-amber-400 underline font-bold">أزل علامات التمييز</button>
                                </p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="wizardQuality" class="block text-[11px] font-bold text-slate-300 mb-1.5">{{ $isAr ? 'جودة التصدير' : 'Export quality' }}</label>
                                    <select id="wizardQuality" name="quality" class="w-full px-3 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs">
                                        <option value="1080" selected>1080p — Full HD</option>
                                        <option value="720">720p — أسرع وأخف</option>
                                    </select>
                                </div>
                                <div class="rounded-xl border border-slate-800 bg-slate-900 p-3 text-[10px] leading-relaxed text-slate-400">
                                    {{ $isAr ? 'المدة النهائية قد تُمدّ قليلاً إذا احتاج النص وقتاً أكبر للقراءة. يتم ضبط الصوت إلى −14 LUFS تلقائياً.' : 'The final duration may extend slightly when the text needs more reading time. Audio is normalized to −14 LUFS automatically.' }}
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[11px] text-slate-300">
                                <label class="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-900 px-3 py-2.5 cursor-pointer">
                                    <input type="hidden" name="captions" value="0">
                                    <input type="checkbox" name="captions" value="1" checked class="accent-amber-500">
                                    <span>{{ $isAr ? 'كابشن كلمة بكلمة + ملف SRT' : 'Word-by-word captions + SRT' }}</span>
                                </label>
                                <label class="flex items-center gap-2 rounded-xl border border-slate-800 bg-slate-900 px-3 py-2.5 cursor-pointer">
                                    <input type="hidden" name="auto_music" value="0">
                                    <input type="checkbox" name="auto_music" value="1" checked class="accent-amber-500">
                                    <span>{{ $isAr ? 'موسيقى خلفية أصلية تلقائية' : 'Original ambient bed' }}</span>
                                </label>
                            </div>

                            <!-- Big Orange CTA Button matching Image 4 -->
                            <button type="submit" id="renderFinalVideoBtn" aria-describedby="renderLoadingPrompt" class="w-full py-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-base transition shadow-xl shadow-amber-500/20 flex items-center justify-center gap-2">
                                <i class="fa-solid fa-film"></i>
                                <span>صدّر ملف MP4</span>
                            </button>
                            <p class="text-[11px] text-slate-400 text-center">يُركّب المقطع المرفوع أو المخزّن مع بطاقات النص؛ لا تُنشأ مشاهد فيديو جديدة من الوصف.</p>

                            <div id="renderLoadingPrompt" role="status" aria-live="polite" class="hidden p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-center space-y-2">
                                <div class="inline-block animate-spin rounded-full h-7 w-7 border-4 border-amber-500 border-t-transparent"></div>
                                <p class="text-xs font-bold text-amber-300">يجري تركيب المقطع وبطاقات النص وتصدير ملف MP4...</p>
                            </div>
                        </div>

                        <div class="studio-card p-6 space-y-3 border border-slate-800">
                            <div class="flex items-center gap-2 text-sm font-bold text-white">
                                <i class="fa-solid fa-volume-high text-amber-400" aria-hidden="true"></i>
                                <h3>الصوت في ملف التصدير</h3>
                            </div>
                            <p class="text-xs leading-relaxed text-slate-400">الخلفيات الأصلية المرفقة صامتة. ارفع ملفاً صوتياً تملك حق استخدامه من تبويب الموسيقى، أو اتركه فارغاً لاستخدام موسيقى خلفية أصلية مولّدة بلا حقوق. تُخفض الموسيقى تلقائياً تحت التعليق ويُضبط الصوت إلى −14 LUFS.</p>
                        </div>

                        <!-- Ready Facebook Captions Card matching Image 2 -->
                        <div class="studio-card p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="font-bold text-white text-base">كابشن Facebook الجاهز للنشر</h3>
                                <button type="button" onclick="suggest4Captions()" class="text-xs bg-slate-800 hover:bg-slate-700 text-amber-400 px-3 py-1.5 rounded-xl border border-slate-700 font-bold flex items-center gap-1.5">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                    <span>اقترح 4 صيغ</span>
                                </button>
                            </div>

                            <!-- 5 Caption Formula Tabs matching Image 2 -->
                            <div class="flex items-center gap-2 overflow-x-auto text-xs pb-1">
                                <button type="button" onclick="switchCaptionTab('primary', this)" class="caption-tab-btn active">الأساسي</button>
                                <button type="button" onclick="switchCaptionTab('short', this)" class="caption-tab-btn">مختصر</button>
                                <button type="button" onclick="switchCaptionTab('curiosity', this)" class="caption-tab-btn">مشوّق</button>
                                <button type="button" onclick="switchCaptionTab('educational', this)" class="caption-tab-btn">تعليمي</button>
                                <button type="button" onclick="switchCaptionTab('story', this)" class="caption-tab-btn">قصة</button>
                            </div>

                            <!-- Caption Textarea matching Image 2 -->
                            <div>
                                <label for="captionTextArea" class="text-[11px] font-bold text-slate-400 block mb-1" id="captionLabel">الكابشن • قالب محلي</label>
                                <textarea id="captionTextArea" name="caption" rows="4" placeholder="سيُنشأ من السكريبت بعد التصدير، أو اختر صيغة مقترحة هنا." class="w-full p-4 rounded-xl bg-slate-900 border border-slate-700 text-white text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-amber-500"></textarea>
                            </div>

                            <!-- Hashtags Box matching Image 2 -->
                            <div>
                                <label for="hashtagsInput" class="text-[11px] font-bold text-slate-400 block mb-1">الهاشتاجات</label>
                                <input type="text" name="hashtags" id="hashtagsInput" value="" placeholder="#Tips #Learn" class="w-full px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-amber-400 text-xs font-mono">
                            </div>

                            <!-- Copy Buttons Row matching Image 2 -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-1">
                                <button type="button" onclick="copyAllCaptions()" class="py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-xs flex items-center justify-center gap-1.5 transition">
                                    <i class="fa-solid fa-copy"></i>
                                    <span>انسخ الكل</span>
                                </button>
                                <button type="button" onclick="copyText(document.getElementById('captionTextArea').value)" class="py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs flex items-center justify-center gap-1.5 border border-slate-700 transition">
                                    <i class="fa-solid fa-clipboard"></i>
                                    <span>انسخ الكابشن</span>
                                </button>
                                <button type="button" onclick="copyText(document.getElementById('hashtagsInput').value)" class="py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs flex items-center justify-center gap-1.5 border border-slate-700 transition">
                                    <i class="fa-solid fa-hashtag"></i>
                                    <span>انسخ الهاشتاجات</span>
                                </button>
                            </div>

                            <!-- Refresh caption CTA matching Image 2 -->
                            <div class="text-center pt-2">
                                <button type="button" onclick="suggest4Captions()" class="text-xs text-amber-400 hover:underline font-bold flex items-center justify-center gap-1 mx-auto">
                                    <i class="fa-solid fa-rotate"></i>
                                    <span>كابشن جديد</span>
                                </button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </form>

    </main>

    <!-- Local workspace menu -->
    <div id="accountModal" class="fixed inset-0 z-50 modal-overlay hidden flex items-center justify-center p-4">
        <div class="bg-[#111827] border border-slate-800 rounded-3xl w-full max-w-md p-6 space-y-4 shadow-2xl relative">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <button type="button" onclick="closeAccountModal()" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center text-xs">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div class="text-left">
                    <h3 class="text-base font-black text-white">مساحة العمل المحلية</h3>
                    <span class="text-[11px] text-slate-400 flex items-center gap-1 justify-end">
                        <span>تعمل على هذا الجهاز دون تسجيل دخول</span>
                        <i class="fa-solid fa-hard-drive text-amber-400"></i>
                    </span>
                </div>
            </div>

            <!-- Modal Nav Items matching Image 1 -->
            <div class="space-y-2 text-xs">
                <a href="{{ route('reels.index', ['lang' => $lang]) }}" class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-film text-amber-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">مكتبي</span>
                            <span class="text-[10px] text-slate-400">عرض الفيديوهات المحفوظة وتشغيلها أو تنزيلها</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </a>

                <div class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition cursor-pointer">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-folder-open text-sky-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">مجموعتي</span>
                            <span class="text-[10px] text-slate-400">ستايلاتك وخلفياتك وموسيقاك وفيديوهاتك المحفوظة</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </div>

                <a href="{{ route('reels.wizard', ['lang' => $lang]) }}" class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-plus text-emerald-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">فيديو جديد</span>
                            <span class="text-[10px] text-slate-400">ابدأ فيديو ثاني من أول خطوة</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </a>

                <div class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition cursor-pointer">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-image text-purple-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">صور ترند</span>
                            <span class="text-[10px] text-slate-400">صورة واحدة ملفتة لصفحتك، مع الكابشن</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition cursor-pointer">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-circle-play text-pink-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">ستوري</span>
                            <span class="text-[10px] text-slate-400">ستوري لصفحتك بصورة وحدة احترافية، أو غلاف لريلك</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition cursor-pointer">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-shapes text-yellow-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">شعار وغلاف</span>
                            <span class="text-[10px] text-slate-400">شعار احترافي باسم صفحتك، وغلاف بنفس الألوان</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition cursor-pointer">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-users text-teal-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">شغل الطلاب ونتائجهم</span>
                            <span class="text-[10px] text-slate-400">فيديوهات عملها طلاب غيرك، وشارك فيديوهاتك ونتيجتك</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </div>

                <div class="p-3.5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between hover:border-slate-700 transition cursor-pointer">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-chart-line text-blue-400 text-sm"></i>
                        <div>
                            <span class="font-bold text-white block">صفحات ناجحة</span>
                            <span class="text-[10px] text-slate-400">صفحات إنجليزي ناجحة بمجالك: شوف شو بينشروا وخذ الفكرة</span>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-left text-slate-400"></i>
                </div>
            </div>

            <div class="pt-2 text-center">
                <span class="text-xs text-slate-400 font-bold block">تجميع محلي للفيديو &bull; إخراج MP4</span>
            </div>
        </div>
    </div>

    <!-- Floating Help Button (المساعد) matching Image 1, 2, 3 -->
    <div class="fixed bottom-6 right-6 z-40">
        <button type="button" aria-label="فتح قائمة مساحة العمل" onclick="openAccountModal()" class="w-12 h-12 rounded-full bg-amber-500 text-slate-950 font-black text-xs shadow-xl shadow-amber-500/30 flex flex-col items-center justify-center border-2 border-amber-300">
            <i class="fa-solid fa-wand-magic-sparkles text-sm"></i>
            <span class="text-[9px] font-black">القائمة</span>
        </button>
    </div>

    <script>
        // Navigation between steps
        function goToStep(step) {
            const previousStep = Number(document.body.dataset.currentStep || 1);
            const brief = document.getElementById('inputUserPrompt');
            if (step > previousStep && previousStep === 1 && !brief?.value.trim()) {
                const status = document.getElementById('wizardStatus');
                if (status) {
                    status.textContent = @json($isAr ? 'اكتب وصفاً للفيديو قبل الانتقال إلى الخطوة التالية.' : 'Enter a video brief before continuing to the next step.');
                    status.classList.remove('hidden');
                }
                brief?.focus();
                return;
            }
            for (let i = 1; i <= 7; i++) {
                const c = document.getElementById('stepContainer' + i);
                const t = document.getElementById('stepTab' + i);
                if (c) c.classList.add('hidden');
                if (t) t.classList.remove('active');
            }
            const activeC = document.getElementById('stepContainer' + step);
            const activeT = document.getElementById('stepTab' + step);
            if (activeC) {
                activeC.classList.remove('hidden', 'step-enter');
                void activeC.offsetWidth;
                activeC.classList.add('step-enter');
            }
            if (activeT) activeT.classList.add('active');
            document.body.dataset.currentStep = String(step);
            document.body.dataset.stepDirection = step >= previousStep ? 'forward' : 'backward';
            if (typeof window.persistWizardDraft === 'function') window.persistWizardDraft();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Subtabs in Step 5
        function switchDesignSubTab(tab) {
            ['colors', 'music', 'scenes', 'templates'].forEach(t => {
                const panel = document.getElementById('subPanel' + t.charAt(0).toUpperCase() + t.slice(1));
                const btn = document.getElementById('dst_' + t);
                if (panel) panel.classList.add('hidden');
                if (btn) {
                    btn.className = 'text-slate-400 pb-2 px-1 flex items-center gap-1.5 hover:text-white';
                    btn.setAttribute('aria-pressed', 'false');
                }
            });
            const activePanel = document.getElementById('subPanel' + tab.charAt(0).toUpperCase() + tab.slice(1));
            const activeBtn = document.getElementById('dst_' + tab);
            if (activePanel) activePanel.classList.remove('hidden');
            if (activeBtn) {
                activeBtn.className = 'text-amber-400 border-b-2 border-amber-400 pb-2 px-1 flex items-center gap-1.5 font-bold';
                activeBtn.setAttribute('aria-pressed', 'true');
            }
        }

        // Duration selector
        function selectDuration(dur, el) {
            document.querySelectorAll('#stepContainer1 .option-pill').forEach(p => {
                p.classList.remove('active', 'border-amber-500');
            });
            el.classList.add('active', 'border-amber-500');
            const radio = el.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            updateTimelineLabels();
            updateTimelineFromProgress(0);
        }
        document.querySelectorAll('input[name="duration"]').forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.checked) selectDuration(Number(radio.value), radio.closest('.option-pill'));
            });
        });

        // Live color dot picker
        function pickAccentColor(hex, el) {
            document.querySelectorAll('.color-dot').forEach(d => {
                d.classList.remove('active');
                d.setAttribute('aria-pressed', 'false');
            });
            if (el) {
                el.classList.add('active');
                el.setAttribute('aria-pressed', 'true');
            }
            document.getElementById('hiddenAccentColor').value = hex;
            updateScriptPreview();
        }

        // Live Font Size Slider
        function changeFontSize(val) {
            document.getElementById('fontSizeVal').innerText = val + '%';
            const card = document.getElementById('step5CardInner');
            if (card) {
                card.style.transform = 'scale(' + (val / 100) + ')';
                card.style.transformOrigin = 'center center';
            }
        }

        function changeBackgroundDimming(val) {
            const label = document.getElementById('backgroundDimmingVal');
            if (label) label.textContent = val + '%';
            updateBackgroundPreview();
        }

        const backgroundPosters = @json(array_column($backgrounds, 'poster_url', 'id'));

        function updateBackgroundPreview() {
            const selected = document.querySelector('input[name="bg_preset"]:checked');
            if (!selected) return;

            document.querySelectorAll('[data-background-option]').forEach(card => {
                const radio = card.querySelector('input[name="bg_preset"]');
                const indicator = card.querySelector('[data-choice-indicator]');
                const active = radio?.checked ?? false;
                card.classList.toggle('border-amber-400', active);
                card.classList.toggle('ring-2', active);
                card.classList.toggle('ring-amber-400/30', active);
                card.classList.toggle('border-slate-700', !active);
                if (indicator) {
                    indicator.classList.toggle('bg-amber-400', active);
                    indicator.classList.toggle('bg-transparent', !active);
                }
            });

            const poster = backgroundPosters[selected.value];
            if (!poster) return;
            const dimming = Number(document.getElementById('backgroundDimming')?.value || 35) / 100;
            const topOpacity = (0.3 + dimming * 0.35).toFixed(2);
            const bottomOpacity = (0.52 + dimming * 0.35).toFixed(2);

            ['step4PreviewBox', 'liveDesignCardBox', 'step7PreviewCard'].forEach(id => {
                const preview = document.getElementById(id);
                if (preview) {
                    preview.style.backgroundImage = `linear-gradient(180deg, rgba(3, 7, 18, ${topOpacity}), rgba(3, 7, 18, ${bottomOpacity})), url("${poster}")`;
                }
            });
        }

        document.querySelectorAll('input[name="bg_preset"]').forEach(radio => {
            radio.addEventListener('change', updateBackgroundPreview);
        });
        updateBackgroundPreview();

        function updateAspectRatioPreview() {
            const ratio = document.getElementById('wizardAspectRatio')?.value || '9:16';
            const [width, height] = ratio.split(':');
            document.querySelectorAll('.reel-preview-frame').forEach(frame => {
                frame.style.aspectRatio = `${width} / ${height}`;
            });
        }
        updateAspectRatioPreview();

        function toggleGenerationOptions(mode) {
            const variantOptions = document.getElementById('variantOptions');
            const batchOptions = document.getElementById('batchOptions');
            const variantCount = document.getElementById('variantCount');
            const batchTopics = document.getElementById('batchTopics');
            const userPrompt = document.getElementById('inputUserPrompt');
            const showVariants = mode === 'variants';
            const showBatch = mode === 'batch';
            variantOptions?.classList.toggle('hidden', !showVariants);
            batchOptions?.classList.toggle('hidden', !showBatch);
            if (variantCount) variantCount.disabled = !showVariants;
            if (batchTopics) {
                batchTopics.required = showBatch;
                batchTopics.disabled = !showBatch;
            }
            if (userPrompt) userPrompt.required = !showBatch;
        }
        toggleGenerationOptions(document.getElementById('generationMode')?.value || 'single');

        let wizardSourcePreviewUrl = null;
        function showWizardVideoName(input) {
            const output = document.getElementById('wizardVideoFileName');
            const preview = document.getElementById('wizardSourcePreview');
            const notice = document.getElementById('wizardSourceOverrideNotice');
            if (input.files?.length > 5) {
                if (wizardSourcePreviewUrl) URL.revokeObjectURL(wizardSourcePreviewUrl);
                wizardSourcePreviewUrl = null;
                input.value = '';
                showWizardStatus('يمكن رفع خمسة مقاطع فيديو كحد أقصى في الطلب الواحد.');
                if (output) output.classList.add('hidden');
                if (preview) {
                    preview.pause();
                    preview.removeAttribute('src');
                    preview.classList.add('hidden');
                }
                if (notice) notice.classList.add('hidden');
                return;
            }
            const file = input.files?.[0];
            if (!output || !preview || !notice) return;

            if (wizardSourcePreviewUrl) URL.revokeObjectURL(wizardSourcePreviewUrl);
            if (!file) {
                output.classList.add('hidden');
                preview.classList.add('hidden');
                notice.classList.add('hidden');
                preview.removeAttribute('src');
                return;
            }

            wizardSourcePreviewUrl = URL.createObjectURL(file);
            output.textContent = input.files.length > 1
                ? `${input.files.length} مقاطع مختارة. معاينة أول مقطع: ${file.name}`
                : `المقطع المختار: ${file.name}`;
            output.classList.remove('hidden');
            preview.src = wizardSourcePreviewUrl;
            preview.classList.remove('hidden');
            notice.classList.remove('hidden');
        }

        let wizardMusicPreviewUrl = null;
        function showWizardMusicName(input) {
            const output = document.getElementById('wizardMusicFileName');
            const preview = document.getElementById('wizardMusicPreview');
            const file = input.files?.[0];
            if (wizardMusicPreviewUrl) URL.revokeObjectURL(wizardMusicPreviewUrl);
            wizardMusicPreviewUrl = null;
            if (!file) {
                if (output) output.classList.add('hidden');
                if (preview) {
                    preview.pause();
                    preview.removeAttribute('src');
                    preview.classList.add('hidden');
                }
                return;
            }
            if (!output || !preview) return;

            output.textContent = 'الملف المختار: ' + file.name;
            output.classList.remove('hidden');
            wizardMusicPreviewUrl = URL.createObjectURL(file);
            preview.src = wizardMusicPreviewUrl;
            preview.classList.remove('hidden');
        }

        // Template Selection
        function selectTemplate(name, el) {
            document.querySelectorAll('#subPanelTemplates > div > button').forEach(c => {
                c.className = 'p-4 rounded-xl bg-slate-900 border border-slate-700 text-center hover:border-amber-500';
                c.setAttribute('aria-pressed', 'false');
            });
            if (el) {
                el.className = 'p-4 rounded-xl bg-slate-900 border-2 border-amber-500 text-center';
                el.setAttribute('aria-pressed', 'true');
            }
            const ht = document.getElementById('hiddenTemplate');
            if (ht) ht.value = name;
        }

        // Keep user-entered text as text; never interpret it as HTML.
        function setPreviewLine(elementId, text, number = null, emphasizeFirstWord = false) {
            const element = document.getElementById(elementId);
            if (!element) return;

            element.replaceChildren();

            if (number) {
                const marker = document.createElement('span');
                marker.className = 'text-amber-400';
                marker.textContent = number;
                element.append(marker, document.createTextNode(' '));
            }

            if (emphasizeFirstWord) {
                const firstWord = text.match(/^\s*\S+/u)?.[0] ?? '';
                if (firstWord) {
                    const emphasis = document.createElement('strong');
                    emphasis.style.color = document.getElementById('hiddenAccentColor')?.value || '#fb7185';
                    emphasis.textContent = firstWord.trim();
                    element.append(emphasis, document.createTextNode(text.slice(firstWord.length)));
                    return;
                }
            }

            element.append(document.createTextNode(text));
        }

        // Live update script preview
        function updateScriptPreview() {
            const l1 = document.getElementById('inputLine1').value;
            const l2 = document.getElementById('inputLine2').value;
            const l3 = document.getElementById('inputLine3').value;
            
            setPreviewLine('step4CardLine1', l1, '1.', true);
            setPreviewLine('step4CardLine2', l2, null, true);
            setPreviewLine('step4CardLine3', l3, null, true);

            setPreviewLine('step5CardLine1', l1, '1.', true);
            setPreviewLine('step5CardLine2', l2, null, true);
            setPreviewLine('step5CardLine3', l3, null, true);

            setPreviewLine('s7Line1', l1, '1.', true);
            setPreviewLine('s7Line2', l2, null, true);
            setPreviewLine('s7Line3', l3, null, true);
            updateTimelineLabels();
        }
        updateScriptPreview();

        function getPreviewDuration() {
            return Number(document.querySelector('input[name="duration"]:checked')?.value || 10);
        }

        function updateTimelineLabels() {
            const labels = [
                document.getElementById('inputLine1')?.value || 'النقطة الأولى',
                document.getElementById('inputLine2')?.value || 'النقطة الثانية',
                document.getElementById('inputLine3')?.value || 'النقطة الثالثة'
            ];
            labels.forEach((label, index) => {
                const node = document.getElementById('timelineLabel' + (index + 1));
                if (node) node.textContent = label;
            });
            const duration = getPreviewDuration();
            const durationNode = document.getElementById('timelineDuration');
            const track = document.getElementById('timelineTrack');
            if (durationNode) durationNode.textContent = duration;
            if (track) track.setAttribute('aria-valuemax', String(duration));
        }

        function previewTimelineSegment(index) {
            const duration = getPreviewDuration();
            const segmentStart = duration * (index / 3);
            const progress = ((index + 0.5) / 3) * 100;
            const progressNode = document.getElementById('timelineProgress');
            const currentNode = document.getElementById('timelineCurrentTime');
            if (progressNode) progressNode.style.width = progress + '%';
            if (currentNode) currentNode.textContent = segmentStart.toFixed(1);
            document.querySelectorAll('.timeline-segment').forEach((button, buttonIndex) => {
                const active = buttonIndex === index;
                button.classList.toggle('border-amber-400/60', active);
                button.classList.toggle('bg-amber-500/15', active);
                button.classList.toggle('border-slate-700', !active);
                button.classList.toggle('bg-slate-900', !active);
            });
            const preview = document.getElementById('step7PreviewCard');
            if (preview) {
                preview.classList.remove('timeline-card-pop');
                void preview.offsetWidth;
                preview.classList.add('timeline-card-pop');
            }
            const track = document.getElementById('timelineTrack');
            if (track) track.setAttribute('aria-valuenow', String(segmentStart.toFixed(1)));
        }

        function updateTimelineFromProgress(progress) {
            const duration = getPreviewDuration();
            const safeProgress = Math.max(0, Math.min(100, Number(progress) || 0));
            const current = duration * safeProgress / 100;
            const index = Math.min(2, Math.floor(safeProgress / 33.34));
            const progressNode = document.getElementById('timelineProgress');
            const currentNode = document.getElementById('timelineCurrentTime');
            if (progressNode) progressNode.style.width = safeProgress + '%';
            if (currentNode) currentNode.textContent = current.toFixed(1);
            document.querySelectorAll('.timeline-segment').forEach((button, buttonIndex) => {
                const active = buttonIndex === index;
                button.classList.toggle('border-amber-400/60', active);
                button.classList.toggle('bg-amber-500/15', active);
                button.classList.toggle('border-slate-700', !active);
                button.classList.toggle('bg-slate-900', !active);
            });
            document.getElementById('timelineTrack')?.setAttribute('aria-valuenow', String(current.toFixed(1)));
        }

        document.querySelectorAll('[data-timeline-segment]').forEach((button) => {
            button.addEventListener('click', () => previewTimelineSegment(Number(button.dataset.timelineSegment || 0)));
        });
        document.getElementById('timelineTrack')?.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
            event.preventDefault();
            const current = Number(document.getElementById('timelineTrack').getAttribute('aria-valuenow') || 0);
            const delta = getPreviewDuration() / 20 * (event.key === 'ArrowRight' ? 1 : -1);
            updateTimelineFromProgress(((current + delta) / getPreviewDuration()) * 100);
        });
        document.getElementById('timelineTrack')?.addEventListener('pointerdown', (event) => {
            const track = event.currentTarget;
            const bounds = track.getBoundingClientRect();
            if (!bounds.width) return;
            const visualProgress = Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width));
            const progress = document.documentElement.dir === 'rtl' ? 1 - visualProgress : visualProgress;
            updateTimelineFromProgress(progress * 100);
        });
        updateTimelineLabels();
        updateTimelineFromProgress(0);

        function showWizardStatus(message = '') {
            const status = document.getElementById('wizardStatus');
            if (!status) return;
            status.textContent = message;
            status.classList.toggle('hidden', !message);
        }

        async function postWizardJson(url, payload) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token || ''
                },
                body: JSON.stringify(payload)
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status === 'error') {
                const language = document.querySelector('select[name="language"]')?.value || @json($lang ?? 'ar');
                throw new Error(data.message || (language === 'en' ? 'The request could not be completed. Check your connection and try again.' : 'تعذّر إكمال الطلب. تحقق من الاتصال وحاول مرة أخرى.'));
            }
            showWizardStatus();
            return data;
        }

        const qualityCopy = @json($qualityCopy);

        async function runWizardQualityCheck() {
            const button = document.getElementById('qualityCheckButton');
            const status = document.getElementById('qualityCheckStatus');
            const reportContainer = document.getElementById('qualityCheckReport');
            if (!button || !status || !reportContainer) return false;

            button.disabled = true;
            status.textContent = qualityCopy.checking;
            try {
                const payload = {
                    headline: document.getElementById('hiddenHookHeadline')?.value || document.getElementById('hookHeadline')?.textContent || '',
                    topic: document.getElementById('inputTopic')?.value || '',
                    user_prompt: document.getElementById('inputUserPrompt')?.value || '',
                    line1: document.getElementById('inputLine1')?.value || '',
                    line2: document.getElementById('inputLine2')?.value || '',
                    line3: document.getElementById('inputLine3')?.value || '',
                    duration: document.querySelector('input[name="duration"]:checked')?.value || '10',
                    aspect_ratio: document.getElementById('wizardAspectRatio')?.value || '9:16',
                    language: document.getElementById('wizardLanguage')?.value || @json($lang),
                    manual_content: false,
                };
                const data = await postWizardJson(@json(route('reels.quality-check')), payload);
                const report = data.report;
                reportContainer.replaceChildren();

                const summary = document.createElement('div');
                summary.className = 'flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 py-3';
                const summaryLabel = document.createElement('strong');
                summaryLabel.className = 'text-xs text-white';
                summaryLabel.textContent = report.status === 'ready' ? qualityCopy.ready : qualityCopy.review;
                const score = document.createElement('span');
                score.className = 'rounded-full bg-amber-500/15 px-3 py-1 text-[11px] font-bold text-amber-300';
                score.textContent = `${qualityCopy.score}: ${report.readiness_score}/100`;
                summary.append(summaryLabel, score);
                reportContainer.appendChild(summary);

                (report.checks || []).forEach(check => {
                    const row = document.createElement('div');
                    row.className = 'flex flex-col gap-1 rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between';
                    const textGroup = document.createElement('div');
                    const label = document.createElement('strong');
                    label.className = 'block text-xs text-slate-100';
                    label.textContent = qualityCopy.checks[check.key] || check.key;
                    const detail = document.createElement('span');
                    detail.className = 'mt-0.5 block text-[10px] leading-relaxed text-slate-400';
                    detail.textContent = qualityCopy.details[check.key]?.[check.status] || '';
                    textGroup.append(label, detail);
                    const state = document.createElement('span');
                    state.className = `self-start rounded-full px-2.5 py-1 text-[10px] font-bold ${check.status === 'pass' ? 'bg-emerald-500/10 text-emerald-300' : (check.status === 'warning' ? 'bg-amber-500/10 text-amber-300' : 'bg-sky-500/10 text-sky-300')}`;
                    state.textContent = qualityCopy.states[check.status] || check.status;
                    row.append(textGroup, state);
                    reportContainer.appendChild(row);
                });

                reportContainer.classList.remove('hidden');
                status.textContent = @json($isAr ? 'الفحص إرشادي فقط؛ راجع النص بنفسك قبل التصدير.' : 'This is a heuristic check; review the script yourself before export.');
                return true;
            } catch (error) {
                status.textContent = error.message || qualityCopy.failed;
                return false;
            } finally {
                button.disabled = false;
            }
        }

        async function runWizardFactCheck() {
            const button = document.getElementById('factCheckButton');
            const status = document.getElementById('factCheckStatus');
            const container = document.getElementById('factCheckReport');
            if (!button || !status || !container) return false;
            button.disabled = true;
            status.textContent = @json($isAr ? 'جارٍ فحص الادعاءات...' : 'Screening claims…');
            try {
                const script = [
                    document.getElementById('inputUserPrompt')?.value || '',
                    document.getElementById('inputLine1')?.value || '',
                    document.getElementById('inputLine2')?.value || '',
                    document.getElementById('inputLine3')?.value || '',
                ].filter(Boolean).join('\n');
                const data = await postWizardJson(@json(route('ai.fact-check')), {
                    headline: document.getElementById('hiddenHookHeadline')?.value || '',
                    topic: document.getElementById('inputTopic')?.value || '',
                    script,
                    language: document.getElementById('wizardLanguage')?.value || @json($lang),
                });
                const report = data.report || {};
                const safe = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
                container.replaceChildren();
                const tone = report.status === 'ready' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' : (report.status === 'high_risk' ? 'border-rose-500/30 bg-rose-500/10 text-rose-200' : 'border-amber-500/30 bg-amber-500/10 text-amber-200');
                const summary = document.createElement('div');
                summary.className = `rounded-xl border px-3 py-3 ${tone}`;
                summary.innerHTML = `<div class="flex flex-wrap items-center justify-between gap-2"><strong>${safe(report.status || 'review')}</strong><span class="text-[11px] font-bold">${@json($isAr ? 'الثقة' : 'Confidence')}: ${Number(report.confidence || 0)}/100</span></div><p class="mt-2 text-xs leading-relaxed">${safe(report.summary || '')}</p>`;
                container.appendChild(summary);
                (report.risk_flags || []).forEach((flag) => {
                    const item = document.createElement('div');
                    item.className = 'rounded-xl border border-rose-500/20 bg-rose-950/30 px-3 py-2 text-xs text-rose-200';
                    item.textContent = `⚠ ${flag}`;
                    container.appendChild(item);
                });
                (report.claims || []).forEach((claim) => {
                    const item = document.createElement('div');
                    item.className = 'rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2.5';
                    item.innerHTML = `<div class="flex flex-wrap items-center justify-between gap-2"><strong class="text-xs text-slate-100">${safe(claim.claim || '')}</strong><span class="rounded-full bg-slate-800 px-2 py-1 text-[10px] font-bold text-sky-200">${safe(claim.verdict || 'uncertain')}</span></div><p class="mt-1 text-[10px] leading-relaxed text-slate-400">${safe(claim.reason || '')}</p>${claim.needs_source ? `<p class="mt-1 text-[10px] font-bold text-amber-300">${@json($isAr ? 'يحتاج مصدرًا خارجيًا' : 'Needs an external source')}</p>` : ''}`;
                    container.appendChild(item);
                });
                if (report.disclaimer) {
                    const disclaimer = document.createElement('p');
                    disclaimer.className = 'text-[10px] leading-relaxed text-slate-500';
                    disclaimer.textContent = report.disclaimer;
                    container.appendChild(disclaimer);
                }
                container.classList.remove('hidden');
                status.textContent = @json($isAr ? 'راجع كل ادعاء مهم وأضف مصدرًا موثوقًا قبل النشر.' : 'Review every important claim and add a reliable source before publishing.');
                return true;
            } catch (error) {
                status.textContent = error.message || @json($isAr ? 'تعذر فحص الحقائق.' : 'Fact-check failed.');
                return false;
            } finally {
                button.disabled = false;
            }
        }

        // AI Hook Suggestion
        function triggerAiHook() {
            const topic = document.getElementById('inputTopic').value.trim()
                || document.getElementById('inputUserPrompt').value.trim().slice(0, 255);
            if (!topic) {
                showWizardStatus('اكتب وصف الفيديو أو الموضوع أولاً، ثم اطلب اقتراحاً.');
                document.getElementById('inputUserPrompt').focus();
                return;
            }
            postWizardJson('/api/ai/suggest-hook', {
                topic,
                language: document.querySelector('select[name="language"]')?.value || 'en'
            })
            .then(data => {
                if (data.status === 'success') {
                    const h = data.hook;
                    document.getElementById('hookBadge').innerText = h.badge;
                    document.getElementById('hookHeadline').innerText = h.headline;
                    document.getElementById('hookDesc').innerText = h.desc;
                    document.getElementById('hiddenHookHeadline').value = h.headline;
                    
                    document.getElementById('step4CardHead').innerText = h.headline;
                    document.getElementById('step5CardHead').innerText = h.headline;
                    document.getElementById('s7Headline').innerText = h.headline;
                }
            })
            .catch(e => showWizardStatus(e.message));
        }

        // AI Idea Suggestion
        function triggerAiIdea() {
            const prompt = document.getElementById('inputUserPrompt').value.trim();
            const topic = document.getElementById('inputTopic').value.trim();
            if (!prompt && !topic) {
                showWizardStatus('اكتب وصف الفيديو أو الموضوع أولاً، ثم اطلب اقتراحاً.');
                document.getElementById('inputUserPrompt').focus();
                return;
            }
            postWizardJson('/api/ai/suggest-idea', {
                prompt,
                topic,
                language: document.querySelector('select[name="language"]')?.value || 'en'
            })
            .then(data => {
                if (data.status === 'success') {
                    const id = data.idea;
                    document.getElementById('ideaBadge').innerText = id.badge;
                    document.getElementById('ideaTitle').innerText = id.title;
                    document.getElementById('ideaDesc').innerText = id.desc;
                    document.getElementById('ideaWhy').innerText = id.why;
                    document.getElementById('ideaAngle').innerText = id.angle;
                    document.getElementById('hookIdeaRef').innerText = id.title;
                    
                    if (id.hook) {
                        document.getElementById('hookHeadline').innerText = id.hook;
                        document.getElementById('hiddenHookHeadline').value = id.hook;
                        document.getElementById('step4CardHead').innerText = id.hook;
                        document.getElementById('step5CardHead').innerText = id.hook;
                        document.getElementById('s7Headline').innerText = id.hook;
                    }
                    goToStep(2);
                }
            })
            .catch(e => showWizardStatus(e.message));
        }

        // AI Script Refine
        function refineScriptAi(action) {
            postWizardJson('/api/ai/refine-script', {
                    action: action,
                    language: document.querySelector('select[name="language"]')?.value || 'en',
                    line1: document.getElementById('inputLine1').value,
                    line2: document.getElementById('inputLine2').value,
                    line3: document.getElementById('inputLine3').value
            })
            .then(data => {
                if (data.status === 'success' && data.lines) {
                    document.getElementById('inputLine1').value = data.lines[0];
                    document.getElementById('inputLine2').value = data.lines[1];
                    document.getElementById('inputLine3').value = data.lines[2];
                    updateScriptPreview();
                }
            })
            .catch(e => showWizardStatus(e.message));
        }

        // Fetch Expert Advice
        function fetchExpertAdvice() {
            const language = document.querySelector('select[name="language"]')?.value || 'en';
            postWizardJson('/api/ai/expert-advice', { language })
            .then(data => {
                if (data.status === 'success' && data.tips) {
                    const list = document.getElementById('expertAdviceList');
                    list.replaceChildren();
                    list.classList.remove('hidden');
                    data.tips.forEach(t => {
                        const item = document.createElement('div');
                        item.className = 'p-3 rounded-xl bg-slate-900 border border-slate-800 text-xs flex items-center justify-between';
                        const content = document.createElement('div');
                        content.className = 'flex items-center gap-2';
                        const icon = document.createElement('i');
                        icon.className = `fa-solid ${String(t.icon || 'fa-circle-info').replace(/[^a-z0-9-]/gi, '')} text-amber-400`;
                        icon.setAttribute('aria-hidden', 'true');
                        const text = document.createElement('div');
                        const title = document.createElement('strong');
                        title.className = 'text-white block';
                        title.textContent = t.title || '';
                        const description = document.createElement('span');
                        description.className = 'text-[10px] text-slate-400';
                        description.textContent = t.desc || '';
                        text.append(title, description);
                        content.append(icon, text);
                        const action = document.createElement('button');
                        action.type = 'button';
                        action.className = 'px-2.5 py-1 rounded bg-amber-500/20 text-amber-300 font-bold text-[10px] border border-amber-500/30';
                        action.textContent = language === 'ar' ? 'عدّلها' : 'Edit';
                        action.addEventListener('click', () => {
                            const step = Number(t.action_step);
                            if (Number.isInteger(step) && step >= 1 && step <= 7) goToStep(step);
                        });
                        item.append(content, action);
                        list.appendChild(item);
                    });
                    document.getElementById('askExpertBtn')?.classList.add('hidden');
                }
            })
            .catch(e => showWizardStatus(e.message));
        }

        // Caption Tabs Switcher
        function switchCaptionTab(style, el) {
            document.querySelectorAll('.caption-tab-btn').forEach(b => b.classList.remove('active'));
            if (el) el.classList.add('active');

            const language = document.querySelector('select[name="language"]')?.value || 'en';
            postWizardJson('/api/ai/generate-captions', {
                    style: style,
                    headline: document.getElementById('hiddenHookHeadline').value,
                    language
            })
            .then(data => {
                if (data.status === 'success' && data.data) {
                    document.getElementById('captionLabel').innerText = (language === 'ar' ? 'الكابشن • ' : 'Caption • ') + data.data.name;
                    document.getElementById('captionTextArea').value = data.data.caption;
                    document.getElementById('hashtagsInput').value = data.data.hashtags;
                }
            })
            .catch(e => showWizardStatus(e.message));
        }

        function suggest4Captions() {
            switchCaptionTab('curiosity', document.querySelectorAll('.caption-tab-btn')[2]);
        }

        function copyToClipboardFallback(text) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        }
        function copyText(str) {
            const success = () => alert('تم النسخ بنجاح!');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(str).then(success).catch(() => { copyToClipboardFallback(str); success(); });
            } else {
                copyToClipboardFallback(str);
                success();
            }
        }

        function copyAllCaptions() {
            const cap = document.getElementById('captionTextArea').value;
            const hash = document.getElementById('hashtagsInput').value;
            copyText(cap + "\n\n" + hash);
        }

        // Account Modal
        function openAccountModal() {
            document.getElementById('accountModal').classList.remove('hidden');
        }

        function closeAccountModal() {
            document.getElementById('accountModal').classList.add('hidden');
        }

        // Form Submit
        const wizardForm = document.getElementById('wizardMultiStepForm');
        wizardForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (wizardForm.dataset.submitting === 'true') {
                return;
            }

            const checked = await runWizardQualityCheck();
            if (!checked) {
                document.getElementById('qualityCheckCard')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            wizardForm.dataset.submitting = 'true';
            window.persistWizardDraft?.();
            const btn = document.getElementById('renderFinalVideoBtn');
            const load = document.getElementById('renderLoadingPrompt');
            if (btn) {
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                btn.classList.add('opacity-60', 'cursor-not-allowed');
            }
            if (load) load.classList.remove('hidden');
            HTMLFormElement.prototype.submit.call(wizardForm);
        });

        (function initializeWizardDraft() {
            const draftStatus = document.getElementById('wizardDraftStatus');
            const clearDraftButton = document.getElementById('clearWizardDraft');
            const owner = @json(auth()->id() ? (string) auth()->id() : 'guest');
            const draftKey = `aivideo:wizard-draft:v1:${owner}:{{ $lang }}`;
            const draftFields = Array.from(wizardForm.elements).filter((field) => {
                const name = field.name || '';
                return name && !['_token', 'gemini_keys'].includes(name)
                    && !/(?:^|[_-])(key|secret|token)(?:$|[_-])/i.test(name)
                    && !['file', 'submit', 'button', 'reset', 'password'].includes((field.type || '').toLowerCase());
            });
            const savedMessage = @json($isAr ? 'تم حفظ المسودة محلياً.' : 'Draft saved locally.');
            const restoredMessage = @json($isAr ? 'استُعيدت مسودتك المحفوظة. الملفات والمفاتيح المؤقتة تحتاج إلى إدخال من جديد.' : 'Your saved draft was restored. Re-select files and re-enter temporary API keys if needed.');

            function persistDraft() {
                const draft = {
                    version: 1,
                    savedAt: Date.now(),
                    step: Number(document.body.dataset.currentStep || 1),
                    fields: draftFields.map((field) => ({
                        name: field.name,
                        type: field.type || '',
                        value: field.value,
                        checked: Boolean(field.checked),
                    })),
                };
                try {
                    localStorage.setItem(draftKey, JSON.stringify(draft));
                    if (draftStatus) draftStatus.textContent = savedMessage;
                    return true;
                } catch (error) {
                    if (draftStatus) draftStatus.textContent = @json($isAr ? 'تعذّر الحفظ المحلي في هذا المتصفح؛ انسخ نصك قبل مغادرة الصفحة.' : 'Local saving is unavailable in this browser; copy your text before leaving.');
                    return false;
                }
            }

            window.persistWizardDraft = persistDraft;
            let saveTimer;
            const scheduleSave = () => {
                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(persistDraft, 350);
            };
            wizardForm.addEventListener('input', scheduleSave);
            wizardForm.addEventListener('change', scheduleSave);
            clearDraftButton?.addEventListener('click', () => {
                try { localStorage.removeItem(draftKey); } catch (error) {}
                if (draftStatus) draftStatus.textContent = @json($isAr ? 'مُسحت المسودة المحفوظة من هذا المتصفح.' : 'The saved draft was cleared from this browser.');
            });

            try {
                const raw = localStorage.getItem(draftKey);
                const draft = raw ? JSON.parse(raw) : null;
                if (draft?.version === 1 && Date.now() - Number(draft.savedAt || 0) < 30 * 24 * 60 * 60 * 1000) {
                    draftFields.forEach((field, index) => {
                        const saved = draft.fields?.[index];
                        if (!saved || saved.name !== field.name || saved.type !== (field.type || '')) return;
                        if (['radio', 'checkbox'].includes((field.type || '').toLowerCase())) field.checked = Boolean(saved.checked);
                        else if (typeof saved.value === 'string') field.value = saved.value;
                    });
                    if (typeof updateAspectRatioPreview === 'function') updateAspectRatioPreview();
                    if (typeof toggleGenerationOptions === 'function') toggleGenerationOptions(document.getElementById('generationMode')?.value || 'single');
                    if (typeof updateScriptPreview === 'function') updateScriptPreview();
                    if (typeof updateTimelineLabels === 'function') updateTimelineLabels();
                    const savedStep = Number(draft.step);
                    if (Number.isInteger(savedStep) && savedStep >= 1 && savedStep <= 7) goToStep(savedStep);
                    if (draftStatus) draftStatus.textContent = restoredMessage;
                } else if (raw) {
                    localStorage.removeItem(draftKey);
                }
            } catch (error) {
                if (draftStatus) draftStatus.textContent = @json($isAr ? 'تعذّر استعادة المسودة؛ يمكنك متابعة التحرير يدوياً.' : 'The draft could not be restored; you can continue editing manually.');
            }

            wizardForm.addEventListener('submit', persistDraft);
        })();

        (function initializeWizardPresets() {
            const presetSelect = document.getElementById('wizardPresetSelect');
            const savePresetButton = document.getElementById('saveWizardPreset');
            const loadPresetButton = document.getElementById('loadWizardPreset');
            if (!presetSelect || !savePresetButton || !loadPresetButton) return;

            const owner = @json(auth()->id() ? (string) auth()->id() : 'guest');
            const presetKey = `aivideo:wizard-presets:v1:${owner}:{{ $lang }}`;
            const fields = Array.from(wizardForm.elements).filter((field) => {
                const name = field.name || '';
                return name && !['_token', 'gemini_keys'].includes(name)
                    && !/(?:^|[_-])(key|secret|token)(?:$|[_-])/i.test(name)
                    && !['file', 'submit', 'button', 'reset', 'password'].includes((field.type || '').toLowerCase());
            });
            const presets = () => {
                try {
                    const value = JSON.parse(localStorage.getItem(presetKey) || '[]');
                    return Array.isArray(value) ? value.filter((item) => item && typeof item.name === 'string' && Array.isArray(item.fields)) : [];
                } catch (error) {
                    return [];
                }
            };
            const writePresets = (items) => localStorage.setItem(presetKey, JSON.stringify(items.slice(-8)));
            const refreshPresetSelect = () => {
                const selected = presetSelect.value;
                presetSelect.innerHTML = `<option value="">${@json($isAr ? 'الإعدادات المحفوظة' : 'Saved presets')}</option>`;
                presets().forEach((preset) => {
                    const option = document.createElement('option');
                    option.value = preset.id;
                    option.textContent = preset.name;
                    presetSelect.appendChild(option);
                });
                presetSelect.value = selected;
            };
            const collectFields = () => fields.map((field) => ({
                name: field.name,
                type: field.type || '',
                value: field.value,
                checked: Boolean(field.checked),
            }));
            const applyFields = (savedFields) => {
                fields.forEach((field) => {
                    const matches = savedFields.filter((saved) => saved.name === field.name && saved.type === (field.type || ''));
                    const saved = ['radio', 'checkbox'].includes((field.type || '').toLowerCase())
                        ? matches.find((item) => item.value === field.value)
                        : matches[0];
                    if (!saved) return;
                    if (['radio', 'checkbox'].includes((field.type || '').toLowerCase())) field.checked = Boolean(saved.checked);
                    else if (typeof saved.value === 'string') field.value = saved.value;
                });
                if (typeof updateAspectRatioPreview === 'function') updateAspectRatioPreview();
                if (typeof toggleGenerationOptions === 'function') toggleGenerationOptions(document.getElementById('generationMode')?.value || 'single');
                if (typeof updateScriptPreview === 'function') updateScriptPreview();
                if (typeof updateTimelineLabels === 'function') updateTimelineLabels();
                wizardForm.dispatchEvent(new Event('change', { bubbles: true }));
            };

            savePresetButton.addEventListener('click', () => {
                const name = window.prompt(@json($isAr ? 'اسم الإعداد المحفوظ:' : 'Preset name:'))?.trim();
                if (!name) return;
                const items = presets().filter((preset) => preset.name !== name);
                items.push({ id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`, name, savedAt: Date.now(), fields: collectFields() });
                try {
                    writePresets(items);
                    refreshPresetSelect();
                    presetSelect.value = items.at(-1).id;
                } catch (error) {
                    window.alert(@json($isAr ? 'تعذر حفظ الإعداد في هذا المتصفح.' : 'This browser could not save the preset.'));
                }
            });

            loadPresetButton.addEventListener('click', () => {
                const selected = presets().find((preset) => preset.id === presetSelect.value);
                if (!selected) return;
                applyFields(selected.fields);
            });

            refreshPresetSelect();
        })();

        // The wizard is a shared Blade template. Translate its static labels when
        // opened with ?lang=en so the selected language is consistent throughout.
        if (@json(!$isAr)) {
            const englishText = new Map([
                ['ماكينة المحتوى الأجنبي محلي', 'Local Global Content Studio'],
                ['ماكينة المحتوى الأجنبي', 'Global Content Studio'],
                ['محلي', 'Local'],
                ['إدارة المفاتيح', 'Manage Keys'],
                ['تجميع محلي', 'Local Assembly'],
                ['فتح قائمة مساحة العمل', 'Open workspace menu'],
                ['القائمة', 'Menu'],
                ['شو الفيديو اللي بدك تعمله؟', 'What video do you want to make?'],
                ['اكتب وصف الفيديو والموضوع. يرسل المحرك الطلب إلى Gemini ويتطلب مفتاحاً مفعّلاً؛ من دون مفتاح صالح يتوقف التوليد ولا يُنشأ نص محلي بديل.', 'Describe the video topic. The engine sends the request to Gemini and requires an active key; without a valid key generation stops and no local fallback is used.'],
                ['الموضوع أو الفكرة اللي ببالك (اختياري)', 'Topic or idea (optional)'],
                ['مدة الفيديو', 'Video duration'],
                ['اختر المدة التي تناسب كمية المعلومات', 'Choose a duration that fits the amount of information'],
                ['ثواني', 'seconds'],
                ['ثانية', 'seconds'],
                ['سريع', 'Fast'],
                ['مباشر', 'Direct'],
                ['قائمة قصيرة', 'Short list'],
                ['الأنسب', 'Recommended'],
                ['تفاصيل', 'Detailed'],
                ['كاملة', 'Full'],
                ['مفصلة', 'In-depth'],
                ['اللغة • Language', 'Language'],
                ['النيش • Niche', 'Niche'],
                ['القالب • Template', 'Template'],
                ['الهدف • Goal', 'Goal'],
                ['السوق المستهدف • Target market', 'Target market'],
                ['صيغة الفيديو • Format', 'Video format'],
                ['نسبة أبعاد التصدير • Aspect ratio', 'Export aspect ratio'],
                ['عمودي 9:16 — Reels / Shorts', 'Vertical 9:16 — Reels / Shorts'],
                ['مربع 1:1', 'Square 1:1'],
                ['أفقي 16:9', 'Landscape 16:9'],
                ['تتغير أبعاد المعاينة والتصدير معاً؛ محتوى المصدر يُقصّ لملاءمة النسبة.', 'Preview and export dimensions change together; source content is cropped to fit the ratio.'],
                ['فيديو المصدر (اختياري)', 'Source video (optional)'],
                ['اختر مقطعك الخاص لاستخدامه خلف بطاقات النص. عند رفعه يتقدّم على الخلفية المتحركة المختارة.', 'Choose a source clip to use behind the text cards. An uploaded clip takes priority over the selected motion background.'],
                ['⚙️ إنتاجية', '⚙️ Productivity'],
                ['💰 مال وثراء', '💰 Money'],
                ['☀️ عادات', '☀️ Habits'],
                ['🧠 علم نفس', '🧠 Psychology'],
                ['⚡ تكنولوجيا', '⚡ Technology'],
                ['📈 أعمال', '📈 Business'],
                ['👥 متابعين', '👥 Followers'],
                ['🔖 حفظ', '🔖 Saves'],
                ['🚀 مشاركات', '🚀 Shares'],
                ['💬 تعليقات', '💬 Comments'],
                ['Global / عالمي', 'Global'],
                ['فكرة كاملة', 'Full idea'],
                ['نصيحة سريعة', 'Quick tip'],
                ['قائمة', 'List'],
                ['قصة', 'Story'],
                ['مفاتيح Gemini لهذه العملية فقط (اختياري)', 'Gemini keys for this run (optional)'],
                ['تُستخدم مفاتيح Gemini النشطة المحفوظة تلقائياً؛ يمكنك إضافة مفاتيح مؤقتة لهذه العملية. لإدارتها اضغط «إدارة المفاتيح» بالأعلى. قد تُحتسب الطلبات لدى المزود.', 'Active saved Gemini keys are used automatically. You can add temporary keys for this run; manage them from Manage Keys above. Provider requests may incur usage.'],
                ['قالب اقتراح', 'Suggestion template'],
                ['اقتراح محلي قابل للتعديل', 'Editable local suggestion'],
                ['اقترح فكرة من القوالب المحلية', 'Suggest from local templates'],
                ['فكرة الفيديو', 'Video idea'],
                ['اعتمد الفكرة', 'Use this idea'],
                ['جرّب فكرة مختلفة', 'Try another idea'],
                ['ليش هالفكرة:', 'Why this idea:'],
                ['الزاوية:', 'Angle:'],
                ['الهوك', 'Hook'],
                ['الفكرة المعتمدة:', 'Approved idea:'],
                ['قالب قابل للتعديل', 'Editable template'],
                ['اكتب النص بهالهوك', 'Write script from this hook'],
                ['اقترح عنواناً بديلاً', 'Suggest another headline'],
                ['عنوان تمهيدي قابل للتعديل؛ تأكد أن الفيديو يجيب عنه بدقة.', 'Editable opening line; make sure the video answers it clearly.'],
                ['مساحة النص', 'Script workspace'],
                ['النقطة الأولى', 'Point one'],
                ['النقطة الثانية', 'Point two'],
                ['النقطة الثالثة', 'Point three'],
                ['أضف علامة ترقيم', 'Add punctuation'],
                ['قصّ إلى 9 كلمات', 'Trim to 9 words'],
                ['أزل علامات التمييز', 'Remove highlights'],
                ['المتابعة إلى خطوة التصميم ←', 'Continue to design →'],
                ['المتابعة إلى معاينة التصميم والتصدير', 'Continue to design preview and export'],
                ['معاينة تصميم البطاقات', 'Preview card design'],
                ['Timeline المعاينة', 'Preview timeline'],
                ['معاينة الملف الصوتي', 'Audio file preview'],
                ['اضغط على أي بطاقة لمعاينة توقيتها وحركتها قبل التصدير.', 'Click any card to preview its timing and motion before export.'],
                ['تم النسخ بنجاح!', 'Copied successfully!'],
            ]);
            const translateTextNodes = (root) => {
                const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
                const nodes = [];
                while (walker.nextNode()) nodes.push(walker.currentNode);
                nodes.forEach(node => {
                    const value = node.nodeValue.trim();
                    if (englishText.has(value)) node.nodeValue = node.nodeValue.replace(value, englishText.get(value));
                });
            };
            translateTextNodes(document.body);
            document.querySelectorAll('input[placeholder], textarea[placeholder]').forEach(input => {
                input.placeholder = input.placeholder
                    .replace('مثلاً: فيديو قصير يشرح ثلاث طرق لتنظيم الوقت بأسلوب عملي ومباشر', 'e.g. A short video explaining three practical ways to manage time')
                    .replace('سيُنشأ من السكريبت بعد التصدير، أو اختر صيغة مقترحة هنا.', 'Generated from the script after export, or choose a suggested format here.');
            });
            document.title = 'Content Machine - Short Video Studio';
        }

        async function requestRealPreview() {
            const button = document.getElementById('realPreviewBtn');
            const image = document.getElementById('realPreviewImage');
            const status = document.getElementById('realPreviewStatus');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const value = (selector) => document.querySelector(selector)?.value || '';

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            status.textContent = @json($isAr ? 'جارٍ رسم المعاينة...' : 'Rendering preview...');
            image.classList.add('hidden');

            const payload = new FormData();
            payload.append('headline', value('[name="headline"]') || value('[name="hook_headline"]'));
            payload.append('line1', value('[name="line1"]'));
            payload.append('line2', value('[name="line2"]'));
            payload.append('line3', value('[name="line3"]'));
            payload.append('language', value('#wizardLanguage') || @json($lang));
            payload.append('template', value('#hiddenTemplate') || 'classic');
            payload.append('handle', value('#inputPageHandle'));
            payload.append('accent_color', value('#hiddenAccentColor') || '#fb7185');
            payload.append('font_scale', value('#fontScaleSlider') || '100');
            payload.append('quality', value('#wizardQuality') || '1080');
            payload.append('aspect_ratio', value('#wizardAspectRatio') || '9:16');

            try {
                const response = await fetch(@json(route('reels.preview')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: payload,
                });
                const data = await response.json();
                if (!response.ok || data.status === 'error') {
                    throw new Error(data.message || 'preview failed');
                }
                image.src = data.url + '?v=' + Date.now();
                image.classList.remove('hidden');
                status.textContent = @json($isAr ? 'هذه معاينة حقيقية من نفس محرك التصدير.' : 'This preview comes from the same render engine used for export.');
            } catch (error) {
                status.textContent = @json($isAr ? 'تعذّر توليد المعاينة. حاول مرة أخرى.' : 'Could not render the preview. Try again.');
            } finally {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        }
    </script>
</body>
</html>
