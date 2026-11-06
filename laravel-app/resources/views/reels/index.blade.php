<!DOCTYPE html>
@php
    $isAr = ($lang ?? 'ar') === 'ar';
    $dir = $isAr ? 'rtl' : 'ltr';
    $activeId = request('active');
    $activeReel = $activeId ? $reels->firstWhere('id', $activeId) : $reels->first();
    $niches = ['money' => '💰 مال', 'habits' => '☀️ عادات', 'psychology' => '🧠 علم نفس', 'tech' => '⚡ تكنولوجيا', 'business' => '📈 أعمال', 'productivity' => '⚙️ إنتاجية'];
    $stageLabels = __('studio.stages');
    $isProcessing = $activeReel && in_array($activeReel->status, ['queued', 'processing'], true);
@endphp
<html lang="{{ $isAr ? 'ar' : 'en' }}" dir="{{ $dir }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isAr ? 'ماكينة المحتوى - استوديو تركيب الفيديوهات القصيرة' : 'AI Reels Machine - Short Video Studio' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: '{{ $isAr ? "Cairo" : "Plus Jakarta Sans" }}', sans-serif; background: #070b12; color: #f1f5f9; min-height: 100vh; }
        .studio-card { background: linear-gradient(180deg, #111827 0%, #0d131f 100%); border: 1px solid rgba(255,255,255,0.08); border-radius: 1.5rem; box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5); }
        .studio-card:hover { border-color: rgba(255, 255, 255, 0.12); }
        .feature-box { background: #111a2e; border: 1px solid #1e2c47; border-radius: 1.25rem; padding: 1.25rem; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
        .feature-box:hover { border-color: #f59e0b; background: #162238; transform: translateY(-2px); }
        .reel-player-shadow { box-shadow: 0 25px 60px -15px rgba(0,0,0,0.9), 0 0 35px rgba(245,158,11,0.18); border: 1px solid rgba(255,255,255,0.08); }
        #videoWrapper { transition: box-shadow 0.45s ease, border-color 0.45s ease; }
        #videoWrapper[data-playing="true"] { border-color: rgba(245,158,11,0.45); box-shadow: 0 24px 70px -20px rgba(245,158,11,0.32); }
        #studioVideoPlayer { transition: transform 0.45s cubic-bezier(0.22,1,0.36,1), filter 0.45s ease; }
        #videoWrapper[data-playing="true"] #studioVideoPlayer { transform: scale(1.008); }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; scroll-behavior: auto !important; }
        }
        .stat-card { background: linear-gradient(135deg, #111827 0%, #131c2e 100%); border: 1px solid rgba(255,255,255,0.07); border-radius: 1.25rem; padding: 1.1rem 1.35rem; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .badge-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.28rem 0.75rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #070b12; } ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 99px; } ::-webkit-scrollbar-thumb:hover { background: #334155; }
        .tab-active { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #070b12; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.25); }
        .tab-inactive { background: #111827; color: #94a3b8; border: 1px solid rgba(255,255,255,0.05); }
        .tab-inactive:hover { background: #1e293b; color: #f1f5f9; }
        @keyframes fadeInUp { from { opacity:0; transform: translateY(12px); } to { opacity:1; transform: translateY(0); } }
        .animate-fadeinup { animation: fadeInUp 0.35s ease forwards; }
    </style>
</head>
<body class="antialiased">

    <!-- Top Navigation Bar -->
    <header class="bg-[#0b111c] border-b border-slate-800 sticky top-0 z-50 shadow-xl">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 min-h-18 flex flex-wrap items-center justify-between gap-3 py-3">
            <!-- Logo -->
            <div class="flex items-center gap-3 flex-shrink min-w-0">
                <div class="w-11 h-11 rounded-2xl bg-amber-500/20 border border-amber-500/40 flex items-center justify-center shadow-lg shadow-amber-500/10">
                    <i class="fa-solid fa-play text-amber-400 text-lg"></i>
                </div>
                <div>
                    <h1 class="text-lg font-black text-white flex items-center gap-2">
                        {{ $isAr ? 'ماكينة المحتوى الأجنبي' : 'AI Content Machine' }}
                        <span class="text-[10px] bg-amber-500/20 text-amber-400 font-bold px-2 py-0.5 rounded-full border border-amber-500/30">Studio</span>
                    </h1>
                    <p class="text-[11px] text-slate-400 hidden sm:block">{{ $isAr ? 'تجميع فيديوهات قصيرة للجمهور الأجنبي والعربي' : 'Short-form video studio for global audiences' }}</p>
                </div>
            </div>

            <!-- Stats Strip (center) -->
            <div class="hidden lg:flex items-center gap-5 text-xs">
                <div class="flex items-center gap-1.5 text-slate-400">
                    <i class="fa-solid fa-film text-amber-400"></i>
                    <span class="font-bold text-white">{{ $stats['total'] }}</span> {{ $isAr ? 'فيديو' : 'Videos' }}
                </div>
                <div class="w-px h-4 bg-slate-700"></div>
                <div class="flex items-center gap-1.5 text-slate-400">
                    <span class="font-bold text-emerald-400">{{ $stats['completed'] }}</span> {{ $isAr ? 'مكتمل' : 'Completed' }}
                </div>
                <div class="w-px h-4 bg-slate-700"></div>
                <div class="flex items-center gap-1.5 text-slate-400">
                    <i class="fa-solid fa-gauge-high text-emerald-400"></i>
                    <span>{{ $isAr ? 'معدل الإكمال:' : 'Completion rate:' }} <strong class="text-white">{{ $stats['completion_rate'] }}</strong>%</span>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex w-full items-center justify-between gap-2 sm:w-auto sm:justify-end sm:gap-3">
                <!-- Lang switch -->
                <div class="flex items-center bg-slate-900 rounded-xl p-1 border border-slate-800 text-xs font-bold">
                    <a href="{{ route('reels.index', array_merge(['lang' => 'ar'], request()->only(['active','filter_lang','filter_niche']))) }}" class="px-2.5 py-1.5 rounded-lg transition {{ $isAr ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-400 hover:text-white' }}">العربية</a>
                    <a href="{{ route('reels.index', array_merge(['lang' => 'en'], request()->only(['active','filter_lang','filter_niche']))) }}" class="px-2.5 py-1.5 rounded-lg transition {{ !$isAr ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-400 hover:text-white' }}">English</a>
                </div>

                <a href="{{ route('reels.wizard', ['lang' => $lang]) }}" class="hidden sm:flex px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-400 font-bold text-xs border border-amber-500/30 transition items-center gap-1.5">
                    <i class="fa-solid fa-list-check"></i>
                    <span>{{ $isAr ? 'ويزارد' : 'Wizard' }}</span>
                </a>

                <a href="{{ route('settings.provider-keys', ['lang' => $lang]) }}" aria-label="{{ $isAr ? 'إدارة المفاتيح' : 'Manage keys' }}" class="px-3 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs border border-slate-700 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-key text-amber-400"></i>
                    <span class="hidden sm:inline">{{ $isAr ? 'المفاتيح' : 'Keys' }}</span>
                </a>

                <a href="#studio-generator" aria-label="{{ $isAr ? 'توليد سريع' : 'Fast generate' }}" class="px-3 sm:px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-xs transition shadow-lg shadow-amber-500/20 flex items-center gap-1.5">
                    <i class="fa-solid fa-bolt"></i>
                    <span class="hidden sm:inline">{{ $isAr ? 'توليد سريع' : 'Fast Generate' }}</span>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

        <!-- Flash messages -->
        @if(session('success'))
        <div class="p-4 rounded-2xl bg-emerald-950/70 border border-emerald-500/40 text-emerald-300 flex items-center gap-3 animate-fadeinup">
            <i class="fa-solid fa-circle-check text-xl flex-shrink-0"></i>
            <div class="font-bold text-sm">{{ session('success') }}</div>
        </div>
        @endif
        @if(session('error'))
        <div class="p-4 rounded-2xl bg-rose-950/70 border border-rose-500/40 text-rose-300 flex items-center gap-3 animate-fadeinup">
            <i class="fa-solid fa-circle-exclamation text-xl flex-shrink-0"></i>
            <div class="font-bold text-sm">{{ session('error') }}</div>
            <details class="mt-1 text-[11px] text-rose-400"><summary class="cursor-pointer">تفاصيل الخطأ</summary><pre class="mt-1 whitespace-pre-wrap text-[10px]">{{ session('error') }}</pre></details>
        </div>
        @endif

        <!-- Hero Banner -->
        <section class="rounded-3xl bg-gradient-to-br from-slate-900 via-[#0e1828] to-slate-900 border border-slate-800 p-8 sm:p-10 relative overflow-hidden">
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxwYXRoIGQ9Ik0zNiAxOGMwLTkuOTQtOC4wNi0xOC0xOC0xOFYwaDQydjQySDE4YzktLjA2IDE4LTguMDYgMTgtMTh6IiBmaWxsPSIjZjU5ZTBiMDgiLz48L2c+PC9zdmc+')] opacity-30"></div>
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center relative">
                <div class="lg:col-span-8 space-y-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs font-bold">
                        <span class="w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
                        {{ $isAr ? 'استوديو تركيب وتصدير الفيديو' : 'Video assembly & export studio' }}
                    </div>

                    <h2 class="text-3xl sm:text-4xl font-black text-white leading-tight tracking-tight">
                        {{ $isAr ? 'أنشئ فيديوهات قصيرة بلغتك،' : 'Create short videos in your chosen language,' }}<br>
                        <span class="text-amber-400">{{ $isAr ? 'ثم صدّر ملف MP4' : 'then export an MP4' }}</span>
                    </h2>

                    <p class="text-sm text-slate-400 max-w-2xl leading-relaxed">
                        {{ $isAr ? 'اكتب وصفك وعدّل النص والتصميم. يتطلب التوليد مفتاح Gemini مفعّلاً من شاشة المفاتيح؛ والتصدير يركّب بطاقات النص على مقطع مصدر أو خلفية محفوظة.' : 'Edit the script and design, then export text cards over a source clip or saved background. Content generation requires an active Gemini key.' }}
                    </p>

                    <div class="pt-2 flex flex-wrap items-center gap-3">
                        <a href="{{ route('reels.wizard', ['lang' => $lang]) }}" class="px-6 py-3 rounded-xl bg-amber-500 text-slate-950 font-black text-sm hover:bg-amber-400 transition shadow-xl shadow-amber-500/20 flex items-center gap-2">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            <span>{{ $isAr ? 'ابدأ ويزارد الإنشاء' : 'Start Creation Wizard' }}</span>
                        </a>
                        <a href="#studio-generator" class="px-5 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-sm border border-slate-700 transition flex items-center gap-2">
                            <i class="fa-solid fa-bolt text-amber-400"></i>
                            <span>{{ $isAr ? 'توليد سريع' : 'Fast Generate' }}</span>
                        </a>
                        <div class="flex items-center gap-2 bg-slate-800/80 px-3.5 py-2.5 rounded-xl border border-slate-700 text-xs text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            <span>{{ $isAr ? 'تجميع محلي • تصدير MP4' : 'Local assembly • MP4 export' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Mockup Cards -->
                <div class="lg:col-span-4 flex justify-center gap-3">
                    <div class="w-24 h-40 rounded-xl bg-gradient-to-b from-slate-800/90 to-slate-900/90 border border-slate-700 p-2 shadow-2xl flex flex-col justify-between transform -rotate-6 translate-y-2">
                        <span class="text-[9px] bg-slate-900 text-slate-400 px-1 py-0.5 rounded">10 ث</span>
                        <div class="text-[9px] font-bold text-center text-white">3 Practical Steps to Start</div>
                        <div class="w-6 h-6 rounded-full bg-amber-500/30 mx-auto flex items-center justify-center text-amber-300 text-[10px]">▶</div>
                    </div>
                    <div class="w-28 h-48 rounded-xl bg-gradient-to-b from-slate-800 to-slate-900 border-2 border-amber-500 p-2 shadow-2xl flex flex-col justify-between z-10 scale-105 ring-4 ring-amber-500/10">
                        <span class="text-[9px] bg-amber-500/20 text-amber-300 px-1 py-0.5 rounded">20 ث</span>
                        <div class="text-[10px] font-bold text-center text-white leading-snug">A Simple Guide to Your Topic</div>
                        <div class="w-8 h-8 rounded-full bg-amber-500 mx-auto flex items-center justify-center text-slate-950 text-xs font-black">▶</div>
                    </div>
                    <div class="w-24 h-40 rounded-xl bg-gradient-to-b from-slate-800/90 to-slate-900/90 border border-slate-700 p-2 shadow-2xl flex flex-col justify-between transform rotate-6 translate-y-2">
                        <span class="text-[9px] bg-slate-900 text-slate-400 px-1 py-0.5 rounded">30 ث</span>
                        <div class="text-[9px] font-bold text-center text-white">A Short Checklist to Save</div>
                        <div class="w-6 h-6 rounded-full bg-amber-500/30 mx-auto flex items-center justify-center text-amber-300 text-[10px]">▶</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats Row -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="stat-card text-center">
                <div class="text-2xl font-black text-amber-400">{{ $stats['total'] }}</div>
                <div class="text-xs text-slate-400 mt-0.5">{{ $isAr ? 'إجمالي الفيديوهات' : 'Total Reels' }}</div>
            </div>
            <div class="stat-card text-center">
                <div class="text-2xl font-black text-emerald-400">{{ $stats['completed'] }}</div>
                <div class="text-xs text-slate-400 mt-0.5">{{ $isAr ? 'مكتمل ومحفوظ' : 'Completed' }}</div>
            </div>
            <div class="stat-card text-center">
                <div class="text-2xl font-black text-sky-400">{{ $stats['en_count'] }}/{{ $stats['ar_count'] }}</div>
                <div class="text-xs text-slate-400 mt-0.5">{{ $isAr ? 'إنجليزي / عربي' : 'EN / AR' }}</div>
            </div>
            <div class="stat-card text-center">
                <div class="text-2xl font-black text-purple-400">{{ $stats['completion_rate'] }}%</div>
                <div class="text-xs text-slate-400 mt-0.5">{{ $isAr ? 'معدل الإكمال' : 'Completion rate' }}</div>
            </div>
        </div>

        <!-- Video Collections Section -->
        @php
            $exampleGroups = [
                'phrases' => $isAr ? 'عبارات تهدئة' : 'Protective Phrases',
                'money' => $isAr ? 'عادات المال' : 'Money Habits',
                'calm' => $isAr ? 'صباح هادئ' : 'Calm Habits',
            ];
            $exampleCards = [
                [
                    'category' => 'phrases',
                    'topic' => $isAr ? '3 عبارات لتهدئة أي نقاش محتدم' : '3 phrases to calm a heated argument',
                    'headline' => $isAr ? '3 عبارات تهدّي أي نقاش محتدم' : '3 Phrases That Calm Any Heated Argument',
                    'point_1' => $isAr ? 'أريد أن أفهم وجهة نظرك.' : 'I want to understand your side.',
                    'point_2' => $isAr ? 'خلّينا نحل المشكلة معاً.' : 'Let’s solve this together.',
                    'niche' => 'psychology', 'goal' => 'comments', 'template' => 'clean_news', 'duration' => 10, 'background' => 'ocean_dusk',
                ],
                [
                    'category' => 'money',
                    'topic' => $isAr ? '3 عادات مالية تساعدك على الاحتفاظ براتبك' : '3 money habits to keep more of your paycheck',
                    'headline' => $isAr ? '3 عادات مالية تحافظ على راتبك' : '3 Money Habits That Protect Your Paycheck',
                    'point_1' => $isAr ? 'ادفع لنفسك أولاً يوم الراتب.' : 'Pay yourself first on payday.',
                    'point_2' => $isAr ? 'انتظر 48 ساعة قبل الشراء الكبير.' : 'Wait 48 hours before a big purchase.',
                    'niche' => 'money', 'goal' => 'saves', 'template' => 'cinematic', 'duration' => 20, 'background' => 'finance_grid',
                ],
                [
                    'category' => 'calm',
                    'topic' => $isAr ? '3 خطوات صغيرة لصباح أكثر هدوءاً' : '3 small habits for a calmer morning',
                    'headline' => $isAr ? '3 عادات لصباح أهدأ' : '3 Small Habits for a Calmer Morning',
                    'point_1' => $isAr ? 'أبعد هاتفك عن السرير ليلاً.' : 'Leave your phone outside the bedroom.',
                    'point_2' => $isAr ? 'ابدأ يومك بكوب ماء وضوء طبيعي.' : 'Start with water and natural light.',
                    'niche' => 'habits', 'goal' => 'followers', 'template' => 'classic', 'duration' => 10, 'background' => 'clean_studio',
                ],
            ];
        @endphp
        <section class="space-y-4" aria-labelledby="featuredExamplesTitle">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <h3 id="featuredExamplesTitle" class="text-xl font-black text-white">{{ $isAr ? 'أمثلة جاهزة للتخصيص' : 'Ready-to-Customize Examples' }}</h3>
                    <p class="text-xs text-slate-400">{{ $isAr ? 'اختر مثالاً لتحميل موضوعه وإعداداته في النموذج، ثم عدّله قبل التوليد.' : 'Load a sample topic and its settings into the generator, then edit before rendering.' }}</p>
                </div>
                <div class="flex gap-2 overflow-x-auto pb-1 text-xs font-bold" role="group" aria-label="{{ $isAr ? 'تصفية الأمثلة' : 'Filter examples' }}">
                    <button type="button" data-example-filter="all" aria-pressed="true" aria-controls="featuredExamples" class="px-3 py-1.5 rounded-xl tab-active whitespace-nowrap">{{ $isAr ? 'الكل' : 'All samples' }}</button>
                    @foreach($exampleGroups as $key => $label)
                    <button type="button" data-example-filter="{{ $key }}" aria-pressed="false" aria-controls="featuredExamples" class="px-3 py-1.5 rounded-xl tab-inactive whitespace-nowrap">{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div id="featuredExamples" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                @foreach($exampleCards as $example)
                <article class="example-card studio-card rounded-2xl p-4 space-y-3 hover:border-amber-500/40 transition" data-example-card data-example-category="{{ $example['category'] }}">
                    <div class="flex items-center justify-between gap-2 text-xs text-slate-400">
                        <span class="bg-amber-500/15 text-amber-400 px-2 py-0.5 rounded border border-amber-500/20">{{ $exampleGroups[$example['category']] }}</span>
                        <span class="bg-slate-900 px-2 py-0.5 rounded">{{ $example['duration'] }} {{ $isAr ? 'ث' : 'sec' }}</span>
                    </div>
                    <div class="rounded-xl bg-slate-950 p-3.5 border border-slate-800 space-y-2">
                        <span class="text-[11px] text-slate-400 block text-center">@yourpage</span>
                        <div class="p-2.5 bg-slate-900 rounded-lg text-xs font-black text-white">{{ $example['headline'] }}</div>
                        <div class="text-[11px] text-slate-300 space-y-1.5 {{ $isAr ? 'text-right' : 'text-left' }}">
                            <div><span class="text-amber-400 font-bold">1.</span> “<span class="text-amber-300">{{ $example['point_1'] }}</span>”</div>
                            <div><span class="text-amber-400 font-bold">2.</span> “<span class="text-amber-300">{{ $example['point_2'] }}</span>”</div>
                        </div>
                    </div>
                    <button type="button" data-use-example
                        data-topic="{{ $example['topic'] }}" data-niche="{{ $example['niche'] }}" data-goal="{{ $example['goal'] }}"
                        data-template="{{ $example['template'] }}" data-duration="{{ $example['duration'] }}" data-background="{{ $example['background'] }}" data-language="{{ $lang }}"
                        aria-label="{{ $isAr ? 'استخدم هذا المثال: '.$example['headline'] : 'Use this example: '.$example['headline'] }}"
                        class="w-full rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-xs font-bold text-slate-200 hover:border-amber-400 hover:text-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-400 transition">
                        <i class="fa-solid fa-arrow-down-to-bracket {{ $isAr ? 'ml-1' : 'mr-1' }}"></i>{{ $isAr ? 'استخدم هذا المثال في النموذج' : 'Use this example in the form' }}
                    </button>
                </article>
                @endforeach
            </div>
            <p id="exampleLoadStatus" role="status" aria-live="polite" class="min-h-5 text-xs text-emerald-300"></p>
        </section>

        <!-- Main Studio Grid: Player (left) + Generator Form (right) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-7 items-start" id="studio-generator">

            <!-- LEFT: Video Player + Archive -->
            <div class="lg:col-span-5 space-y-5">

                <!-- Video Player Card -->
                <div class="studio-card rounded-3xl p-5 relative">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse flex-shrink-0"></span>
                            <h3 class="font-bold text-white text-sm">{{ $isAr ? 'مشغل الريلز الحي' : 'Live Reel Player' }}</h3>
                        </div>
                        @if($activeReel && $activeReel->status === 'completed')
                        <div class="flex items-center gap-1.5">
                            <button type="button" id="togglePlayBtn" onclick="togglePlay()" aria-label="{{ $isAr ? 'تشغيل الفيديو' : 'Play video' }}" aria-pressed="false" class="text-xs bg-slate-800 hover:bg-slate-700 text-amber-400 px-2.5 py-1.5 rounded-lg border border-slate-700 transition font-bold flex items-center gap-1">
                                <i class="fa-solid fa-play text-[10px]" id="playIcon"></i>
                                <span id="playLabel" class="hidden sm:inline">{{ $isAr ? 'تشغيل' : 'Play' }}</span>
                            </button>
                            <button type="button" id="toggleMuteBtn" onclick="toggleMute()" aria-label="{{ $isAr ? 'كتم الصوت' : 'Mute audio' }}" aria-pressed="false" title="{{ $isAr ? 'كتم الصوت' : 'Mute audio' }}" class="text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 px-2.5 py-1.5 rounded-lg border border-slate-700 transition">
                                <i class="fa-solid fa-volume-high" id="muteIcon"></i>
                            </button>
                            <button type="button" id="toggleFullscreenBtn" onclick="toggleFullscreen()" aria-label="{{ $isAr ? 'ملء الشاشة' : 'Enter fullscreen' }}" title="{{ $isAr ? 'ملء الشاشة' : 'Enter fullscreen' }}" class="text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 px-2.5 py-1.5 rounded-lg border border-slate-700 transition">
                                <i class="fa-solid fa-expand" id="fullscreenIcon"></i>
                            </button>
                            <a href="{{ route('reels.stream', $activeReel->id) }}" download="reel_{{ $activeReel->id }}.mp4" class="text-xs bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold px-3 py-1.5 rounded-xl transition flex items-center gap-1 shadow">
                                <i class="fa-solid fa-download"></i> MP4
                            </a>
                        </div>
                        @elseif($activeReel)
                        <span class="text-[11px] font-bold {{ $isProcessing ? 'text-amber-400' : 'text-rose-400' }}">
                            {{ $isProcessing ? ($isAr ? 'قيد المعالجة...' : 'Processing...') : ($isAr ? 'فشل التوليد' : 'Generation failed') }}
                        </span>
                        @endif
                    </div>

                    @if($activeReel)
                    <div class="mb-4 flex flex-wrap items-center gap-2 text-[10px] text-slate-400">
                        @if($activeReel->content_source)
                        <span class="rounded-full border border-sky-500/30 bg-sky-500/10 px-2.5 py-1 text-sky-300">{{ strtoupper($activeReel->content_source) }}{{ $activeReel->ai_model ? ' · '.$activeReel->ai_model : '' }}</span>
                        @endif
                        <span class="rounded-full border border-slate-700 bg-slate-900 px-2.5 py-1">{{ $activeReel->progress ?? 0 }}% · {{ $activeReel->progress_stage ?: $activeReel->status }}</span>
                    </div>
                    @endif

                    @if($activeReel && $activeReel->status === 'completed')
                    <div class="flex justify-center bg-black rounded-2xl border border-slate-800 relative overflow-hidden" id="videoWrapper" data-playing="false">
                        <video id="studioVideoPlayer" data-native-player aria-label="{{ $isAr ? 'مشغل الفيديو الناتج' : 'Generated video player' }}" tabindex="0" class="reel-player-shadow rounded-2xl w-full max-h-[560px] object-contain bg-black" playsinline webkit-playsinline loop preload="metadata">
                            <source src="{{ route('reels.stream', $activeReel->id) }}" type="video/mp4">
                        </video>
                        <button type="button" id="centerPlayBtn" onclick="togglePlay()" aria-label="{{ $isAr ? 'تشغيل الفيديو' : 'Play video' }}" class="absolute inset-0 m-auto w-16 h-16 rounded-full bg-amber-500 text-slate-950 shadow-2xl shadow-amber-500/40 flex items-center justify-center transition hover:scale-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white" aria-hidden="false">
                            <i class="fa-solid fa-play text-xl mr-[-2px]"></i>
                        </button>
                    </div>
                    <div id="videoPlayerStatus" role="status" aria-live="polite" aria-atomic="true" class="hidden mt-3 rounded-xl border border-slate-700 bg-slate-900 px-3 py-2.5 text-xs text-slate-300 flex items-center gap-2">
                        <i id="videoPlayerStatusIcon" class="fa-solid fa-spinner fa-spin text-amber-400" aria-hidden="true"></i>
                        <span id="videoPlayerStatusText"></span>
                        <button type="button" id="retryVideoBtn" onclick="retryVideoPlayback()" class="hidden mr-auto rounded-lg border border-rose-400/30 bg-rose-500/10 px-2.5 py-1.5 font-bold text-rose-200 hover:bg-rose-500/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-400">{{ $isAr ? 'إعادة المحاولة' : 'Retry' }}</button>
                    </div>

                    <!-- Progress Bar -->
                    <div class="mt-3 space-y-1">
                        <input type="range" id="videoSeekBar" min="0" max="100" step="0.1" value="0" aria-label="{{ $isAr ? 'موضع الفيديو' : 'Video position' }}" class="w-full accent-amber-500 h-1.5 bg-slate-800 rounded-lg cursor-pointer">
                        <div class="flex justify-between text-[10px] text-slate-400 font-mono">
                            <span id="videoCurrentTime">0:00</span>
                            <span id="videoDuration">0:{{ str_pad(intval($activeReel->duration ?? 10), 2, '0', STR_PAD_LEFT) }}</span>
                        </div>
                    </div>

                    <!-- Meta Info -->
                    <div class="mt-3 p-4 rounded-2xl bg-slate-900 border border-slate-800 space-y-3">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="badge-pill bg-amber-500/15 text-amber-400 border border-amber-500/25">{{ $activeReel->handle ?? '@yourpage' }}</span>
                                <span class="badge-pill bg-slate-800 text-slate-300">{{ strtoupper($activeReel->language ?? 'en') }}</span>
                                <span class="badge-pill bg-slate-800 text-slate-300">{{ $activeReel->template ?? 'classic' }}</span>
                                @if($activeReel->niche)
                                <span class="badge-pill bg-indigo-500/15 text-indigo-400">{{ $niches[$activeReel->niche] ?? $activeReel->niche }}</span>
                                @endif
                            </div>
                            <span class="badge-pill bg-emerald-500/15 text-emerald-400 border border-emerald-500/20">
                                <i class="fa-solid fa-film text-[9px]"></i>
                                {{ $isAr ? 'MP4 جاهز' : 'MP4 ready' }}
                            </span>
                        </div>

                        <h4 class="font-black text-white text-sm leading-snug">{{ $activeReel->title }}</h4>

                        @if($activeReel->duration)
                        <div class="flex items-center gap-3 text-[11px] text-slate-400">
                            <span><i class="fa-solid fa-clock text-amber-400 mr-1"></i>{{ $activeReel->duration }}s</span>
                            @if($activeReel->niche)<span><i class="fa-solid fa-tag text-slate-600 mr-1"></i>{{ $activeReel->niche }}</span>@endif
                            @if($activeReel->created_at)<span>{{ $activeReel->created_at->diffForHumans() }}</span>@endif
                        </div>
                        @endif

                        @if($activeReel->generated_script)
                        <div class="pt-2 border-t border-slate-800">
                            <div class="text-[11px] font-bold text-slate-400 mb-1.5 flex items-center gap-1">
                                <i class="fa-solid fa-scroll text-amber-400"></i>
                                {{ $isAr ? 'السكريبت المُولَّد:' : 'Generated Script:' }}
                            </div>
                            <div class="text-[11px] text-slate-400 bg-slate-950 p-2.5 rounded-xl max-h-24 overflow-y-auto border border-slate-800 leading-relaxed">
                                {{ Str::limit($activeReel->generated_script, 200) }}
                            </div>
                        </div>
                        @endif

                        @if($activeReel->caption)
                        <div class="pt-2 border-t border-slate-800 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-amber-400 flex items-center gap-1">
                                    <i class="fa-solid fa-copy"></i>
                                    {{ $isAr ? 'الكابشن والهاشتاغات:' : 'Caption & Hashtags:' }}
                                </span>
                                <button onclick="copyCaption()" class="text-[11px] bg-slate-800 hover:bg-slate-700 text-slate-200 px-2.5 py-1 rounded-lg transition flex items-center gap-1">
                                    <i class="fa-solid fa-copy text-[10px]"></i>
                                    {{ $isAr ? 'نسخ الكل' : 'Copy All' }}
                                </button>
                            </div>
                            <div id="captionContent" class="text-xs text-slate-300 bg-slate-950 p-3 rounded-xl max-h-32 overflow-y-auto leading-relaxed border border-slate-800">
                                {{ $activeReel->caption }}
                                <br><br>
                                <span class="text-amber-400">{{ $activeReel->hashtags }}</span>
                            </div>
                        </div>
                        @endif
                    </div>

                    @elseif($isProcessing)
                    <div class="rounded-2xl bg-amber-950/30 border border-amber-500/30 p-6 space-y-3" id="renderProgressCard">
                        <h4 class="font-bold text-amber-300 flex items-center gap-2">
                            <i class="fa-solid fa-gear fa-spin"></i>
                            <span id="renderStatusTitle">{{ $activeReel->status === 'queued' ? ($stageLabels['queued'] ?? 'Queued') : ($stageLabels['processing'] ?? 'Processing') }}</span>
                        </h4>
                        <div class="h-2 rounded-full bg-slate-900 overflow-hidden">
                            <div id="renderProgressBar" class="h-full bg-amber-500 transition-all duration-500" style="width: {{ (int) ($activeReel->progress ?? 0) }}%"></div>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-amber-200">
                            <span id="renderStage">{{ $stageLabels[$activeReel->progress_stage ?: 'queued'] ?? ($activeReel->progress_stage ?: 'queued') }}</span>
                            <span id="renderPercent">{{ (int) ($activeReel->progress ?? 0) }}%</span>
                        </div>
                        <p class="text-[11px] text-slate-400">{{ $isAr ? 'يتم الرندر في الخلفية؛ ستتحدّث هذه الصفحة تلقائياً عند الانتهاء.' : 'Rendering runs in the background; this page will update automatically when it finishes.' }}</p>
                    </div>
                    @elseif($activeReel)
                    <div class="rounded-2xl bg-rose-950/40 border border-rose-500/30 p-6 space-y-3">
                        <h4 class="font-bold text-rose-300 flex items-center gap-2">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            {{ $isAr ? 'فشل توليد الفيديو' : 'Video generation failed' }}
                        </h4>
                        @if($activeReel->error_message)
                        <pre class="text-[10px] text-rose-200/80 whitespace-pre-wrap break-all max-h-40 overflow-auto bg-black/40 p-3 rounded-lg">{{ Str::limit($activeReel->error_message, 1000) }}</pre>
                        @endif
                        <a href="{{ route('reels.wizard', ['lang' => $lang]) }}" class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2 text-xs font-black text-slate-950 transition hover:bg-amber-400">
                            <i class="fa-solid fa-rotate-right"></i>
                            {{ $isAr ? 'إعادة المحاولة' : 'Try again' }}
                        </a>
                    </div>
                    @else
                    <!-- Empty State -->
                    <div class="rounded-2xl bg-slate-900/60 border border-dashed border-slate-700 min-h-[340px] flex flex-col items-center justify-center p-8 text-center text-slate-400">
                        <div class="w-16 h-16 rounded-full bg-slate-800 flex items-center justify-center mb-4 text-slate-400 text-2xl">
                            <i class="fa-solid fa-film"></i>
                        </div>
                        <h4 class="font-bold text-white text-base mb-1">{{ $isAr ? 'لا يوجد فيديو بعد' : 'No Video Yet' }}</h4>
                        <p class="text-xs text-slate-400 max-w-xs">{{ $isAr ? 'استخدم الاستوديو أدناه لإنشاء أول فيديو قصير.' : 'Use the studio below to create your first short video.' }}</p>
                        <a href="#studio-generator" class="mt-4 px-4 py-2 rounded-xl bg-amber-500 text-slate-950 font-bold text-xs hover:bg-amber-400 transition">
                            {{ $isAr ? 'توليد الآن' : 'Generate Now' }}
                        </a>
                    </div>
                    @endif
                </div>

                <!-- Archive / History -->
                @if($reels->count() > 0)
                <div class="studio-card rounded-3xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-bold text-white text-sm flex items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left text-amber-400"></i>
                            {{ $isAr ? 'أرشيف الفيديوهات' : 'Reels Archive' }}
                        </h4>
                        <span class="text-xs text-slate-400 bg-slate-900 px-2.5 py-1 rounded-lg">{{ $reels->count() }}</span>
                    </div>

                    <!-- Filter tabs -->
                    <div class="flex gap-2 mb-3 text-[11px] font-bold items-center flex-wrap">
                        <a href="{{ route('reels.index', ['lang' => $lang]) }}" class="px-2.5 py-1 rounded-lg {{ !request('filter_lang') && !request('filter_niche') ? 'bg-amber-500 text-slate-950' : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800' }}">{{ $isAr ? 'الكل' : 'All' }}</a>
                        <a href="{{ route('reels.index', ['filter_lang' => 'en', 'lang' => $lang, 'filter_niche' => request('filter_niche')]) }}" class="px-2.5 py-1 rounded-lg {{ request('filter_lang') == 'en' ? 'bg-amber-500 text-slate-950' : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800' }}">EN</a>
                        <a href="{{ route('reels.index', ['filter_lang' => 'ar', 'lang' => $lang, 'filter_niche' => request('filter_niche')]) }}" class="px-2.5 py-1 rounded-lg {{ request('filter_lang') == 'ar' ? 'bg-amber-500 text-slate-950' : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800' }}">AR</a>
                        <form method="GET" action="{{ route('reels.index') }}" class="ml-auto flex items-center gap-1">
                            <input type="hidden" name="lang" value="{{ $lang }}">
                            @if(request('filter_lang'))<input type="hidden" name="filter_lang" value="{{ request('filter_lang') }}">@endif
                            <select name="filter_niche" onchange="this.form.submit()" class="px-2 py-1 rounded-lg bg-slate-900 border border-slate-800 text-slate-300 text-[11px]">
                                <option value="">{{ $isAr ? 'كل النيشات' : 'All niches' }}</option>
                                @foreach($niches as $k => $v)
                                    <option value="{{ $k }}" {{ request('filter_niche')===$k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>

                    <div class="space-y-2 max-h-80 overflow-y-auto pr-1">
                        @foreach($reels as $r)
                        <div class="p-3 rounded-xl bg-slate-900 border {{ ($activeReel && $activeReel->id == $r->id) ? 'border-amber-500 bg-amber-500/5' : 'border-slate-800 hover:border-slate-700' }} flex items-center gap-3 transition group">
                            <!-- Status dot -->
                            <div class="w-2 h-2 rounded-full flex-shrink-0 {{ $r->status === 'completed' ? 'bg-emerald-400' : ($r->status === 'processing' ? 'bg-amber-400 animate-pulse' : 'bg-rose-400') }}"></div>

                            <div class="truncate flex-1 min-w-0" @if($r->error_message) title="{{ $r->error_message }}" @endif>
                                <a href="{{ route('reels.index', ['active' => $r->id]) }}" class="text-xs font-bold text-white hover:text-amber-400 block truncate">
                                    {{ $r->title }}
                                </a>
                                <span class="text-[10px] text-slate-400">
                                    {{ $r->duration }}s • {{ strtoupper($r->language ?? 'en') }} • {{ $r->niche ?? 'general' }}
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                @if($r->status === 'completed')
                                <a href="{{ route('reels.index', ['active' => $r->id]) }}" class="p-1.5 rounded-lg bg-amber-500/15 text-amber-400 hover:bg-amber-500/30 text-xs transition">
                                    <i class="fa-solid fa-play text-[9px]"></i>
                                </a>
                                <a href="{{ route('reels.stream', $r->id) }}" download="reel_{{ $r->id }}.mp4" class="p-1.5 rounded-lg bg-sky-500/15 text-sky-400 hover:bg-sky-500/25 text-xs transition">
                                    <i class="fa-solid fa-download text-[9px]"></i>
                                </a>
                                @else
                                <span class="text-[10px] text-amber-400 font-bold">{{ $r->status === 'processing' ? '...' : ($r->status === 'failed' ? '✗' : '?') }}</span>
                                @endif
                                <form action="{{ route('reels.destroy', $r->id) }}" method="POST" onsubmit="return confirm('{{ $isAr ? 'حذف هذا الفيديو؟' : 'Delete this reel?' }}')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="p-1.5 rounded-lg bg-rose-500/15 text-rose-400 hover:bg-rose-500/25 text-xs transition">
                                        <i class="fa-solid fa-trash text-[9px]"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            <!-- RIGHT: Fast Generator Studio -->
            <div class="lg:col-span-7">
                <div class="studio-card rounded-3xl p-7 border border-slate-800">
                    <div class="mb-6 pb-5 border-b border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-amber-400 bg-amber-500/10 px-3 py-1 rounded-full border border-amber-500/20">
                                {{ $isAr ? 'ماكينة إنتاج الفيديوهات السريعة' : 'Fast Reel Compiler' }}
                            </span>
                            <h3 class="text-xl font-black text-white mt-3">
                                {{ $isAr ? 'اختر النيش والهدف، وكبسة وحدة بتطلّع الفيديو' : 'Select Niche & Goal, 1-Click Render' }}
                            </h3>
                        </div>
                        <a href="{{ route('reels.wizard', ['lang' => $lang]) }}" class="text-xs text-amber-400 underline font-bold whitespace-nowrap">
                            {{ $isAr ? 'ويزارد الخطوات ←' : 'Full Wizard →' }}
                        </a>
                    </div>

                    <form action="{{ route('reels.store') }}" method="POST" enctype="multipart/form-data" id="generatorForm" class="space-y-5">
                        @csrf

                        <!-- Topic -->
                        <div>
                            <label class="block text-xs font-bold text-slate-300 mb-2">
                                {{ $isAr ? 'موضوع الفيديو أو الفكرة' : 'Video Topic or Concept' }}
                                <span class="text-amber-400">*</span>
                            </label>
                            <input type="text" name="topic" required
                                placeholder="{{ $isAr ? 'مثال: دليل مبسط لتنظيم الوقت، أو خطوات لتعلم مهارة جديدة...' : 'e.g., A simple guide to focus, or steps to learn a new skill...' }}"
                                class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm">
                        </div>

                        <!-- Niche & Goal -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">
                                    <i class="fa-solid fa-compass text-amber-400 mr-1"></i>
                                    {{ $isAr ? 'النيش (Niche)' : 'Niche Selection' }}
                                </label>
                                <select name="niche" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs">
                                    <option value="productivity" selected>⚙️ إنتاجية ومهارات عملية</option>
                                    <option value="money">💰 مال وعادات مالية</option>
                                    <option value="habits">☀️ عادات وصباح هادئ وتطوير</option>
                                    <option value="psychology">🧠 علم نفس وتهدئة النقاشات</option>
                                    <option value="tech">⚡ ذكاء اصطناعي وتكنولوجيا</option>
                                    <option value="business">📈 ريادة أعمال وتسويق</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">
                                    <i class="fa-solid fa-bullseye text-emerald-400 mr-1"></i>
                                    {{ $isAr ? 'هدف الفيديو' : 'Video Goal' }}
                                </label>
                                <select name="goal" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs">
                                    <option value="followers">👥 جلب متابعين جدد</option>
                                    <option value="saves">🔖 حفظ الفيديو (Saves)</option>
                                    <option value="shares">🚀 مشاركات</option>
                                    <option value="comments">💬 تعليقات ونقاش</option>
                                </select>
                            </div>
                        </div>

                        <!-- Template & Handle -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">
                                    <i class="fa-solid fa-palette text-indigo-400 mr-1"></i>
                                    {{ $isAr ? 'القالب الاحترافي' : 'Video Template' }}
                                </label>
                                <select name="template" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs">
                                    <option value="classic">⭐ Classic (كارت كلاسيكي)</option>
                                    <option value="clean_news">📰 Clean News (ستايل أخبار)</option>
                                    <option value="cinematic">🎬 Cinematic Glass (سينمائي شفاف)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">
                                    <i class="fa-solid fa-at text-amber-400 mr-1"></i>
                                    {{ $isAr ? 'اسم الصفحة (Handle)' : 'Page Handle' }}
                                </label>
                                <input type="text" name="handle" value="@yourpage"
                                    class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-sm">
                            </div>
                        </div>

                        <!-- Language, Duration, Motion -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">{{ $isAr ? 'اللغة' : 'Language' }}</label>
                                <select name="language" class="w-full px-3 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs">
                                    <option value="en" {{ $lang === 'en' ? 'selected' : '' }}>🇺🇸 English</option>
                                    <option value="ar" {{ $lang === 'ar' ? 'selected' : '' }}>🇸🇦 العربية</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">{{ $isAr ? 'المدة' : 'Duration' }}</label>
                                <select name="duration" class="w-full px-3 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs">
                                    <option value="5">5 ث</option>
                                    <option value="10" selected>10 ث</option>
                                    <option value="20">20 ث</option>
                                    <option value="30">30 ث</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">{{ $isAr ? 'حركة الكاميرا' : 'Camera' }}</label>
                                <select name="camera_motion" class="w-full px-3 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs">
                                    <option value="zoom_in">🔍 تقريب هادئ</option>
                                    <option value="pan">↔️ Pan</option>
                                    <option value="static">ثابت</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-300 mb-2">{{ $isAr ? 'السوق المستهدف' : 'Target market' }}</label>
                                <select name="target_country" class="w-full px-3 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs">
                                    <option value="Global" selected>Global</option>
                                    <option value="US">United States</option>
                                    <option value="UK">United Kingdom</option>
                                    <option value="Canada">Canada</option>
                                    <option value="Australia">Australia</option>
                                </select>
                            </div>
                        </div>

                        <!-- Options Row -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <!-- Anti-Border -->
                            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-shield-halved text-amber-400"></i>
                                    <div>
                                        <span class="text-xs font-bold text-white block">{{ $isAr ? 'هوامش آمنة تقريبية' : 'Approximate safe margins' }}</span>
                                        <span class="text-[10px] text-slate-400">{{ $isAr ? 'لا تضمن التزام المنصة أو التوصية' : 'No platform or reach guarantee' }}</span>
                                    </div>
                                </div>
                                <input type="checkbox" name="anti_border" checked class="w-5 h-5 accent-amber-500 cursor-pointer">
                            </div>

                            <!-- Aspect Ratio -->
                            <div class="p-3 rounded-xl bg-slate-900 border border-slate-800">
                                <label class="block text-xs font-bold text-slate-300 mb-1.5">
                                    <i class="fa-solid fa-crop text-sky-400 mr-1"></i>
                                    {{ $isAr ? 'نسبة العرض إلى الارتفاع' : 'Aspect Ratio' }}
                                </label>
                                <div class="flex gap-2 text-[11px] font-bold">
                                    <label class="flex items-center gap-1 cursor-pointer"><input type="radio" name="aspect_ratio" value="9:16" checked class="accent-amber-500"> 9:16 ريلز</label>
                                    <label class="flex items-center gap-1 cursor-pointer"><input type="radio" name="aspect_ratio" value="1:1" class="accent-amber-500"> 1:1 سكوير</label>
                                    <label class="flex items-center gap-1 cursor-pointer"><input type="radio" name="aspect_ratio" value="16:9" class="accent-amber-500"> 16:9 أفقي</label>
                                </div>
                            </div>
                        </div>

                        @include('reels.partials.background-picker', ['backgrounds' => $backgrounds, 'lang' => $lang])

                        <!-- Video Upload -->
                        <div>
                            <label class="block text-xs font-bold text-slate-300 mb-2">
                                <i class="fa-solid fa-video text-amber-400 mr-1"></i>
                                {{ $isAr ? 'استخدم فيديوهاتك بدلاً من الخلفية المختارة (اختياري)' : 'Use your own video instead of the selected background (optional)' }}
                            </label>
                            <div class="border-2 border-dashed border-slate-700 rounded-xl p-4 text-center hover:border-amber-500/50 transition cursor-pointer" onclick="document.getElementById('videoUpload').click()">
                                <i class="fa-solid fa-cloud-arrow-up text-slate-400 text-lg mb-1"></i>
                                <p class="text-xs text-slate-400">{{ $isAr ? 'اضغط لرفع فيديو MP4 أو MOV أو AVI أو WebM — سيتم استخدامه كمصدر' : 'Upload MP4, MOV, AVI, or WebM — your video becomes the source' }}</p>
                                <input type="file" id="videoUpload" name="videos[]" multiple accept="video/mp4,video/quicktime,video/x-msvideo,video/webm" class="hidden" onchange="showFileNames(this, 'videoNames'); updateUploadedSourceNotice(this, 'videoSourceNotice')">
                            </div>
                            <div id="videoNames" role="status" aria-live="polite" class="text-[11px] text-amber-400 mt-1 hidden"></div>
                            <p id="videoSourceNotice" class="hidden text-[10px] text-emerald-300 mt-1">{{ $isAr ? 'سيُستخدم الفيديو المرفوع بدلاً من الخلفية المحددة أعلاه.' : 'The uploaded video will override the background selected above.' }}</p>
                        </div>

                        <!-- Music Upload -->
                        <div>
                            <label class="block text-xs font-bold text-slate-300 mb-2">
                                <i class="fa-solid fa-music text-pink-400 mr-1"></i>
                                {{ $isAr ? 'موسيقى الخلفية (اختياري - MP3)' : 'Background Music (Optional MP3)' }}
                            </label>
                            <div class="border-2 border-dashed border-slate-700 rounded-xl p-4 text-center hover:border-pink-500/50 transition cursor-pointer" onclick="document.getElementById('musicUpload').click()">
                                <i class="fa-solid fa-music text-slate-400 text-lg mb-1"></i>
                                <p class="text-xs text-slate-400">{{ $isAr ? 'ارفع MP3 أو WAV أو M4A تملك حق استخدامه' : 'Upload MP3, WAV, or M4A audio you have rights to use' }}</p>
                                <input type="file" id="musicUpload" name="music" accept="audio/mpeg,audio/wav,audio/mp4,.mp3,.wav,.m4a" class="hidden" onchange="showFileNames(this, 'musicName')">
                            </div>
                            <div id="musicName" class="text-[11px] text-pink-400 mt-1 hidden"></div>
                        </div>

                        <!-- AI Keys (optional, multiple) -->
                        <div class="space-y-3">
                            <label class="block text-xs font-bold text-slate-300 mb-1">
                                <i class="fa-solid fa-key text-yellow-400 mr-1"></i>
                                {{ $isAr ? 'مفاتيح Gemini لهذه العملية (مطلوبة إذا لم تحفظ مفتاحاً)' : 'Gemini keys for this run (required if no saved key)' }}
                            </label>
                            <textarea name="gemini_keys" rows="2" placeholder="AIza...&#10;AIza..."
                                class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500 text-xs font-mono"></textarea>
                            <p class="text-[10px] text-slate-400">{{ $isAr ? 'تُستخدم مفاتيح Gemini النشطة المحفوظة تلقائياً؛ يمكنك إضافة مفاتيح مؤقتة لهذه العملية. لإدارة المفاتيح اضغط «المفاتيح» بالأعلى. قد تُحتسب الطلبات لدى المزود.' : 'Saved active Gemini keys are used automatically; you can add one-time keys for this run. Manage saved keys from the Keys button above. Provider usage may incur charges.' }}</p>
                        </div>

                        <!-- Submit -->
                        <button type="submit" id="submitBtn" aria-describedby="loadingBox" class="w-full py-4 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-black text-base transition shadow-xl shadow-amber-500/20 flex items-center justify-center gap-2">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            <span>{{ $isAr ? 'توليد الفيديو والكابشن والهاشتاغات الآن' : 'Generate Full Reel Package Now' }}</span>
                        </button>

                        <div id="loadingBox" role="status" aria-live="polite" class="hidden p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-center space-y-3">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-amber-500 border-t-transparent"></div>
                            <p class="text-xs font-bold text-amber-300">{{ $isAr ? 'يجري تركيب المقطع والبطاقات النصية وتصدير MP4. قد يستغرق ذلك عدة دقائق.' : 'Assembling the source clip and text overlays, then exporting MP4. This may take several minutes.' }}</p>
                            <div class="flex justify-center gap-1">
                                @foreach(['إعداد النص', 'رسم البطاقات', 'دمج الفيديو والصوت', 'تصدير MP4'] as $step)
                                <div class="text-[10px] px-2 py-1 rounded bg-slate-800 text-slate-400">{{ $step }}</div>
                                @endforeach
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Features Highlight Row -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-5">
                    @foreach([
                        ['fa-microchip', 'text-amber-400', $isAr ? 'Gemini AI' : 'Gemini AI', $isAr ? 'يتطلب مفتاحاً فعالاً' : 'Active key required'],
                        ['fa-palette', 'text-indigo-400', $isAr ? '3 قوالب' : '3 Templates', $isAr ? 'Classic / News / Glass' : 'Classic / News / Glass'],
                        ['fa-shield-halved', 'text-emerald-400', $isAr ? 'هوامش عرض' : 'Safe margins', $isAr ? 'إرشادية فقط' : 'Approximate only'],
                        ['fa-download', 'text-sky-400', $isAr ? 'تصدير MP4' : 'MP4 export', $isAr ? 'راجع قبل النشر' : 'Review before posting'],
                    ] as $f)
                    <div class="feature-box text-center">
                        <i class="fa-solid {{ $f[0] }} {{ $f[1] }} text-lg mb-2 block"></i>
                        <div class="text-xs font-bold text-white">{{ $f[2] }}</div>
                        <div class="text-[10px] text-slate-400 mt-0.5">{{ $f[3] }}</div>
                    </div>
                    @endforeach
                </div>
            </div>

        </div>

    </main>

    <footer class="mt-16 border-t border-slate-800/80 py-8 text-center text-xs text-slate-600">
        ماكينة المحتوى الأجنبي والعربي &bull; تجميع محلي لملفات MP4 &bull; راجع النص والحقوق قبل النشر
    </footer>

    <script>
        const video = document.getElementById('studioVideoPlayer');
        const videoWrapper = document.getElementById('videoWrapper');
        const playIcon = document.getElementById('playIcon');
        const playButton = document.getElementById('togglePlayBtn');
        const playLabel = document.getElementById('playLabel');
        const muteIcon = document.getElementById('muteIcon');
        const muteButton = document.getElementById('toggleMuteBtn');
        const fullscreenIcon = document.getElementById('fullscreenIcon');
        const fullscreenButton = document.getElementById('toggleFullscreenBtn');
        const seekBar = document.getElementById('videoSeekBar');
        const playerStatus = document.getElementById('videoPlayerStatus');
        const playerStatusIcon = document.getElementById('videoPlayerStatusIcon');
        const playerStatusText = document.getElementById('videoPlayerStatusText');
        const centerPlayButton = document.getElementById('centerPlayBtn');
        const retryVideoButton = document.getElementById('retryVideoBtn');
        const playerLabels = {
            play: @json($isAr ? 'تشغيل' : 'Play'),
            pause: @json($isAr ? 'إيقاف مؤقت' : 'Pause'),
            playVideo: @json($isAr ? 'تشغيل الفيديو' : 'Play video'),
            pauseVideo: @json($isAr ? 'إيقاف الفيديو مؤقتاً' : 'Pause video'),
            mute: @json($isAr ? 'كتم الصوت' : 'Mute audio'),
            unmute: @json($isAr ? 'إلغاء كتم الصوت' : 'Unmute audio'),
            enterFullscreen: @json($isAr ? 'ملء الشاشة' : 'Enter fullscreen'),
            exitFullscreen: @json($isAr ? 'الخروج من ملء الشاشة' : 'Exit fullscreen'),
            noAudio: @json($isAr ? 'لا يوجد مسار صوت' : 'No audio track'),
            loading: @json($isAr ? 'جارٍ تحميل الفيديو…' : 'Loading video…'),
            error: @json($isAr ? 'تعذّر تشغيل الفيديو. أعد المحاولة أو نزّل ملف MP4.' : 'Could not play this video. Retry or download the MP4.'),
            playbackError: @json($isAr ? 'تعذّر بدء التشغيل. جرّب زر التشغيل مرة أخرى.' : 'Playback could not start. Please try again.'),
        };

        function formatTime(seconds) {
            const safeSeconds = Number.isFinite(Number(seconds)) ? Math.max(0, Math.floor(Number(seconds))) : 0;
            return Math.floor(safeSeconds / 60) + ':' + String(safeSeconds % 60).padStart(2, '0');
        }

        function updatePlayControl() {
            if (!video) return;
            const isPlaying = !video.paused && !video.ended;
            if (videoWrapper) videoWrapper.dataset.playing = String(isPlaying);
            if (centerPlayButton) {
                centerPlayButton.classList.toggle('opacity-0', isPlaying);
                centerPlayButton.classList.toggle('pointer-events-none', isPlaying);
                centerPlayButton.setAttribute('aria-hidden', String(isPlaying));
            }
            if (playIcon) playIcon.className = isPlaying ? 'fa-solid fa-pause text-[10px]' : 'fa-solid fa-play text-[10px]';
            if (playLabel) playLabel.textContent = isPlaying ? playerLabels.pause : playerLabels.play;
            if (playButton) {
                playButton.setAttribute('aria-label', isPlaying ? playerLabels.pauseVideo : playerLabels.playVideo);
                playButton.setAttribute('aria-pressed', String(isPlaying));
            }
        }

        function updateMuteControl() {
            if (!video) return;
            let hasAudio = null;
            if (video.audioTracks && typeof video.audioTracks.length === 'number') {
                hasAudio = video.audioTracks.length > 0;
            } else if (typeof video.webkitAudioDecodedByteCount === 'number' && video.readyState >= 2) {
                hasAudio = video.webkitAudioDecodedByteCount > 0;
            }

            if (muteButton && hasAudio === false) {
                muteButton.disabled = true;
                muteButton.setAttribute('aria-label', playerLabels.noAudio);
                muteButton.setAttribute('title', playerLabels.noAudio);
                muteButton.setAttribute('aria-pressed', 'false');
                muteButton.classList.add('opacity-50', 'cursor-not-allowed');
                if (muteIcon) muteIcon.className = 'fa-solid fa-volume-xmark';
                return;
            }

            if (muteButton) {
                muteButton.disabled = false;
                muteButton.classList.remove('opacity-50', 'cursor-not-allowed');
            }
            const isMuted = video.muted || video.volume === 0;
            if (muteIcon) muteIcon.className = isMuted ? 'fa-solid fa-volume-xmark' : 'fa-solid fa-volume-high';
            if (muteButton) {
                muteButton.setAttribute('aria-label', isMuted ? playerLabels.unmute : playerLabels.mute);
                muteButton.setAttribute('title', isMuted ? playerLabels.unmute : playerLabels.mute);
                muteButton.setAttribute('aria-pressed', String(isMuted));
            }
        }

        function updateFullscreenControl() {
            const isFullscreen = Boolean(document.fullscreenElement && (document.fullscreenElement === videoWrapper || document.fullscreenElement === video));
            if (fullscreenIcon) fullscreenIcon.className = isFullscreen ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
            if (fullscreenButton) {
                fullscreenButton.setAttribute('aria-label', isFullscreen ? playerLabels.exitFullscreen : playerLabels.enterFullscreen);
                fullscreenButton.setAttribute('title', isFullscreen ? playerLabels.exitFullscreen : playerLabels.enterFullscreen);
            }
        }

        function setPlayerStatus(kind) {
            if (!playerStatus || !playerStatusIcon || !playerStatusText) return;
            if (!kind) {
                playerStatus.classList.add('hidden');
                if (retryVideoButton) retryVideoButton.classList.add('hidden');
                return;
            }
            const isError = kind === 'error' || kind === 'playback-error';
            playerStatus.classList.remove('hidden');
            playerStatus.classList.toggle('border-rose-500/40', isError);
            playerStatus.classList.toggle('text-rose-200', isError);
            playerStatus.classList.toggle('border-slate-700', !isError);
            playerStatus.classList.toggle('text-slate-300', !isError);
            playerStatusIcon.className = isError ? 'fa-solid fa-triangle-exclamation text-rose-400' : 'fa-solid fa-spinner fa-spin text-amber-400';
            playerStatusText.textContent = kind === 'error' ? playerLabels.error : (kind === 'playback-error' ? playerLabels.playbackError : playerLabels.loading);
            if (retryVideoButton) retryVideoButton.classList.toggle('hidden', !isError);
        }

        function updatePlayerTimeline() {
            if (!video) return;
            const duration = Number.isFinite(video.duration) && video.duration > 0 ? video.duration : 0;
            const currentTime = Number.isFinite(video.currentTime) ? video.currentTime : 0;
            if (seekBar && duration) {
                seekBar.value = String(Math.min(100, Math.max(0, (currentTime / duration) * 100)));
                seekBar.setAttribute('aria-valuetext', formatTime(currentTime) + ' / ' + formatTime(duration));
            }
            const current = document.getElementById('videoCurrentTime');
            const total = document.getElementById('videoDuration');
            if (current) current.textContent = formatTime(currentTime);
            if (total && duration) total.textContent = formatTime(duration);
        }

        function togglePlay() {
            if (!video) return;
            if (video.paused || video.ended) {
                const playRequest = video.play();
                if (playRequest && typeof playRequest.catch === 'function') {
                    playRequest.catch(() => setPlayerStatus('playback-error'));
                }
            } else {
                video.pause();
            }
        }

        let previousVolume = video && video.volume > 0 ? video.volume : 1;

        function toggleMute() {
            if (!video) return;
            if (video.muted || video.volume === 0) {
                video.muted = false;
                if (video.volume === 0) video.volume = previousVolume || 1;
            } else {
                previousVolume = video.volume;
                video.muted = true;
            }
        }

        function retryVideoPlayback() {
            if (!video) return;
            const sourceUrl = video.currentSrc || video.querySelector('source')?.src;
            if (!sourceUrl) return;
            setPlayerStatus(null);
            video.pause();
            const retryUrl = new URL(sourceUrl, window.location.href);
            retryUrl.searchParams.set('_retry', String(Date.now()));
            video.src = retryUrl.toString();
            video.load();
            const playRequest = video.play();
            if (playRequest && typeof playRequest.catch === 'function') {
                playRequest.catch(() => setPlayerStatus('playback-error'));
            }
        }

        async function toggleFullscreen() {
            if (!video) return;
            try {
                if (video.webkitDisplayingFullscreen && typeof video.webkitExitFullscreen === 'function') {
                    video.webkitExitFullscreen();
                } else if (document.fullscreenElement) {
                    await document.exitFullscreen();
                } else if (videoWrapper && videoWrapper.requestFullscreen) {
                    await videoWrapper.requestFullscreen();
                } else if (video.webkitEnterFullscreen) {
                    video.webkitEnterFullscreen();
                }
            } catch (error) {
                setPlayerStatus('playback-error');
            }
        }

        if (video) {
            video.addEventListener('click', togglePlay);
            video.addEventListener('keydown', function(event) {
                if (event.key === ' ' || event.key === 'Enter') {
                    event.preventDefault();
                    togglePlay();
                }
            });
            video.addEventListener('play', updatePlayControl);
            video.addEventListener('playing', function() { updatePlayControl(); setPlayerStatus(null); });
            video.addEventListener('pause', updatePlayControl);
            video.addEventListener('ended', updatePlayControl);
            video.addEventListener('volumechange', function() {
                if (video.volume > 0) previousVolume = video.volume;
                updateMuteControl();
            });
            video.addEventListener('loadedmetadata', updateMuteControl);
            video.addEventListener('loadeddata', updateMuteControl);
            video.addEventListener('canplay', updateMuteControl);
            video.addEventListener('playing', updateMuteControl);
            video.addEventListener('timeupdate', updatePlayerTimeline);
            video.addEventListener('loadedmetadata', updatePlayerTimeline);
            video.addEventListener('durationchange', updatePlayerTimeline);
            video.addEventListener('waiting', function() { setPlayerStatus('loading'); });
            video.addEventListener('stalled', function() { setPlayerStatus('loading'); });
            video.addEventListener('canplay', function() { setPlayerStatus(null); });
            video.addEventListener('error', function() { setPlayerStatus('error'); });
            video.addEventListener('webkitbeginfullscreen', updateFullscreenControl);
            video.addEventListener('webkitendfullscreen', updateFullscreenControl);
            if (seekBar) {
                seekBar.addEventListener('input', function() {
                    if (!Number.isFinite(video.duration) || video.duration <= 0) return;
                    const fraction = Math.min(1, Math.max(0, Number(this.value) / 100));
                    video.currentTime = fraction * video.duration;
                    updatePlayerTimeline();
                });
            }
            updatePlayControl();
            updateMuteControl();
            updatePlayerTimeline();
        }
        document.addEventListener('fullscreenchange', updateFullscreenControl);
        updateFullscreenControl();

        const generatorForm = document.getElementById('generatorForm');
        generatorForm.addEventListener('submit', function(event) {
            if (generatorForm.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            generatorForm.dataset.submitting = 'true';
            const submitButton = document.getElementById('submitBtn');
            submitButton.disabled = true;
            submitButton.setAttribute('aria-busy', 'true');
            submitButton.classList.add('opacity-60', 'cursor-not-allowed');
            document.getElementById('loadingBox').classList.remove('hidden');
        });

        document.querySelectorAll('[data-example-filter]').forEach(filterButton => {
            filterButton.addEventListener('click', function() {
                const selectedCategory = this.dataset.exampleFilter || 'all';
                document.querySelectorAll('[data-example-card]').forEach(card => {
                    card.classList.toggle('hidden', selectedCategory !== 'all' && card.dataset.exampleCategory !== selectedCategory);
                });
                document.querySelectorAll('[data-example-filter]').forEach(button => {
                    const active = button === this;
                    button.setAttribute('aria-pressed', String(active));
                    button.classList.toggle('tab-active', active);
                    button.classList.toggle('tab-inactive', !active);
                });
            });
        });

        document.querySelectorAll('[data-use-example]').forEach(exampleButton => {
            exampleButton.addEventListener('click', function() {
                if (!generatorForm) return;
                ['topic', 'niche', 'goal', 'template', 'duration', 'language'].forEach(name => {
                    const field = generatorForm.elements.namedItem(name);
                    if (field && this.dataset[name]) field.value = this.dataset[name];
                });

                const backgroundId = this.dataset.background;
                if (backgroundId) {
                    generatorForm.querySelectorAll('input[name="bg_preset"]').forEach(radio => {
                        const selected = radio.value === backgroundId;
                        radio.checked = selected;
                        if (selected) radio.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }

                const status = document.getElementById('exampleLoadStatus');
                if (status) status.textContent = @json($isAr ? 'تم تحميل المثال وإعداداته. راجعها وعدّلها قبل التوليد.' : 'Example settings loaded. Review and edit them before rendering.');
                document.getElementById('studio-generator')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                generatorForm.elements.namedItem('topic')?.focus({ preventScroll: true });
            });
        });

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
        function copyCaption() {
            const el = document.getElementById('captionContent');
            if (!el) return;
            const text = el.innerText;
            const success = () => alert('{{ $isAr ? "تم نسخ الكابشن والهاشتاغات بنجاح!" : "Caption & hashtags copied to clipboard!" }}');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(success).catch(() => { copyToClipboardFallback(text); success(); });
            } else {
                copyToClipboardFallback(text);
                success();
            }
        }

        function showFileNames(input, targetId) {
            const el = document.getElementById(targetId);
            if (!el) return;
            if (input.files.length) {
                el.classList.remove('hidden');
                el.textContent = Array.from(input.files).map(f => f.name).join(', ');
            } else { el.classList.add('hidden'); }
        }

        function updateUploadedSourceNotice(input, targetId) {
            const notice = document.getElementById(targetId);
            if (notice) notice.classList.toggle('hidden', !input.files.length);
        }

        document.querySelectorAll('[data-background-picker]').forEach(picker => {
            picker.querySelectorAll('input[name="bg_preset"]').forEach(radio => {
                radio.addEventListener('change', () => {
                    picker.querySelectorAll('[data-background-option]').forEach(card => {
                        const active = card.querySelector('input[name="bg_preset"]')?.checked ?? false;
                        const indicator = card.querySelector('[data-choice-indicator]');
                        card.classList.toggle('border-amber-400', active);
                        card.classList.toggle('ring-2', active);
                        card.classList.toggle('ring-amber-400/30', active);
                        card.classList.toggle('border-slate-700', !active);
                        if (indicator) {
                            indicator.classList.toggle('bg-amber-400', active);
                            indicator.classList.toggle('bg-transparent', !active);
                        }
                    });
                });
            });
        });

        // Poll a queued/processing render and refresh the page when it settles.
        const pendingReel = @json($isProcessing ? ['url' => route('reels.status', $activeReel)] : null);
        if (pendingReel) {
            const stageLabels = @json($stageLabels);
            const pollRender = async () => {
                try {
                    const response = await fetch(pendingReel.url, { headers: { 'Accept': 'application/json' } });
                    if (response.ok) {
                        const data = await response.json();
                        const bar = document.getElementById('renderProgressBar');
                        const percent = document.getElementById('renderPercent');
                        const stage = document.getElementById('renderStage');
                        if (bar) bar.style.width = `${data.progress}%`;
                        if (percent) percent.textContent = `${data.progress}%`;
                        if (stage) stage.textContent = stageLabels[data.stage] || data.stage || '';
                        if (data.status === 'completed' || data.status === 'failed') {
                            window.location.reload();
                            return;
                        }
                    }
                } catch (error) {
                    // Keep polling; a transient network error should not stop the UI.
                }
                setTimeout(pollRender, 2000);
            };
            setTimeout(pollRender, 1000);
        }
    </script>
</body>
</html>
