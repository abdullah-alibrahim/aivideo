<!DOCTYPE html>
@php($isAr = $isAr ?? ($lang === 'ar'))
<html lang="{{ $isAr ? 'ar' : 'en' }}" dir="{{ $isAr ? 'rtl' : 'ltr' }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isAr ? 'لوحة التحليلات' : 'Analytics Dashboard' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#070b12] text-slate-100 antialiased">
    <main class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-800 bg-slate-900/70 p-5">
            <div>
                <a href="{{ route('reels.index', ['lang' => $lang]) }}" class="text-xs font-bold text-amber-400">← {{ $isAr ? 'العودة للاستوديو' : 'Back to studio' }}</a>
                <h1 class="mt-2 text-2xl font-black text-white">{{ $isAr ? 'لوحة التحليلات' : 'Analytics Dashboard' }}</h1>
                <p class="mt-1 text-xs text-slate-400">{{ $isAr ? 'صورة عملية عن الإنتاج، النجاح، والفشل خلال دورة المحتوى.' : 'A practical view of content production, success, and failure.' }}</p>
            </div>
            <div class="flex gap-2 rounded-xl border border-slate-700 bg-slate-950 p-1 text-xs font-bold">
                <a href="{{ route('analytics.dashboard', ['lang' => 'ar']) }}" class="rounded-lg px-3 py-2 {{ $isAr ? 'bg-amber-500 text-slate-950' : 'text-slate-400' }}">العربية</a>
                <a href="{{ route('analytics.dashboard', ['lang' => 'en']) }}" class="rounded-lg px-3 py-2 {{ !$isAr ? 'bg-amber-500 text-slate-950' : 'text-slate-400' }}">English</a>
            </div>
        </header>

        <section class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-8">
            @foreach([
                ['total', $isAr ? 'كل الريلز' : 'All reels', 'text-amber-400'],
                ['completed', $isAr ? 'مكتمل' : 'Completed', 'text-emerald-400'],
                ['active', $isAr ? 'قيد التنفيذ' : 'Active', 'text-sky-400'],
                ['failed', $isAr ? 'فشل' : 'Failed', 'text-rose-400'],
                ['cancelled', $isAr ? 'ملغى' : 'Cancelled', 'text-slate-300'],
                ['completion_rate', $isAr ? 'نسبة النجاح' : 'Success rate', 'text-purple-400'],
                ['avg_duration', $isAr ? 'متوسط الثواني' : 'Avg seconds', 'text-cyan-400'],
                ['quality_ready', $isAr ? 'جاهز للنشر' : 'Quality ready', 'text-lime-400'],
            ] as [$key, $label, $color])
            <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-4"><div class="text-[10px] font-bold text-slate-500">{{ $label }}</div><div class="mt-2 text-2xl font-black {{ $color }}">{{ $metrics[$key] }}{{ $key === 'completion_rate' ? '%' : '' }}</div></div>
            @endforeach
        </section>

        <section class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-5 lg:col-span-2">
                <div class="flex items-center justify-between"><h2 class="font-black text-white">{{ $isAr ? 'الإنتاج خلال آخر 14 يومًا' : 'Production over the last 14 days' }}</h2><span class="text-[10px] text-slate-500">{{ $isAr ? 'إنشاء / مكتمل' : 'Created / completed' }}</span></div>
                <div class="mt-6 grid h-48 grid-cols-14 items-end gap-1.5 sm:gap-3">
                    @foreach($daily as $day)
                    @php($max = max(1, max(array_column($daily, 'count'))))
                    <div class="group flex h-full flex-col items-center justify-end gap-1"><div class="relative flex h-full w-full items-end justify-center gap-0.5"><span class="w-1/2 rounded-t bg-amber-500/80" style="height: {{ ($day['count'] / $max) * 100 }}%" title="{{ $day['count'] }}"></span><span class="w-1/2 rounded-t bg-emerald-400/80" style="height: {{ ($day['completed'] / $max) * 100 }}%" title="{{ $day['completed'] }}"></span></div><span class="text-[8px] text-slate-500">{{ $day['label'] }}</span></div>
                    @endforeach
                </div>
            </div>
            <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-5"><h2 class="font-black text-white">{{ $isAr ? 'جودة المحتوى' : 'Content quality' }}</h2><div class="mt-5 space-y-4"><div><div class="flex justify-between text-xs"><span class="text-slate-400">{{ $isAr ? 'جاهز' : 'Ready' }}</span><strong class="text-lime-400">{{ $metrics['quality_ready'] }}</strong></div><div class="mt-2 h-2 rounded-full bg-slate-800"><div class="h-2 rounded-full bg-lime-400" style="width: {{ min(100, $metrics['quality_ready'] * 10) }}%"></div></div></div><div><div class="flex justify-between text-xs"><span class="text-slate-400">{{ $isAr ? 'يحتاج مراجعة' : 'Needs review' }}</span><strong class="text-amber-400">{{ $metrics['quality_review'] }}</strong></div><div class="mt-2 h-2 rounded-full bg-slate-800"><div class="h-2 rounded-full bg-amber-400" style="width: {{ min(100, $metrics['quality_review'] * 10) }}%"></div></div></div><p class="text-[11px] leading-relaxed text-slate-500">{{ $isAr ? 'المؤشرات إرشادية ولا تعني أن المحتوى دُقّق خارجيًا.' : 'These are heuristics and do not mean the content was externally verified.' }}</p></div></div>
        </section>

        <section class="grid gap-6 lg:grid-cols-3">
            @foreach([['languages', $isAr ? 'حسب اللغة' : 'By language'], ['niches', $isAr ? 'حسب المجال' : 'By niche'], ['templates', $isAr ? 'حسب القالب' : 'By template']] as [$key, $title])
            <div class="rounded-3xl border border-slate-800 bg-slate-900/70 p-5"><h2 class="font-black text-white">{{ $title }}</h2><div class="mt-4 space-y-3">@forelse($$key as $item)<div class="flex items-center justify-between text-xs"><span class="truncate text-slate-300">{{ $item['label'] }}</span><span class="rounded-full bg-slate-800 px-2 py-1 font-bold text-amber-300">{{ $item['total'] }}</span></div>@empty<span class="text-xs text-slate-500">{{ $isAr ? 'لا توجد بيانات بعد' : 'No data yet' }}</span>@endforelse</div></div>
            @endforeach
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="overflow-hidden rounded-3xl border border-slate-800 bg-slate-900/70"><div class="border-b border-slate-800 p-5"><h2 class="font-black text-white">{{ $isAr ? 'آخر الريلز' : 'Recent reels' }}</h2></div><div class="divide-y divide-slate-800">@forelse($recent as $reel)<div class="flex items-center justify-between gap-3 p-4 text-xs"><div class="min-w-0"><div class="truncate font-bold text-white">{{ $reel->title ?: $reel->topic }}</div><div class="mt-1 text-[10px] text-slate-500">{{ $reel->created_at?->format('Y-m-d H:i') }} · {{ $reel->language }}</div></div><span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $reel->status === 'completed' ? 'bg-emerald-500/10 text-emerald-300' : ($reel->status === 'failed' ? 'bg-rose-500/10 text-rose-300' : 'bg-slate-800 text-slate-300') }}">{{ $reel->status }}</span></div>@empty<div class="p-5 text-xs text-slate-500">{{ $isAr ? 'لا توجد ريلز بعد.' : 'No reels yet.' }}</div>@endforelse</div></div>
            <div class="overflow-hidden rounded-3xl border border-slate-800 bg-slate-900/70"><div class="border-b border-slate-800 p-5"><h2 class="font-black text-white">{{ $isAr ? 'المجموعات' : 'Collections' }}</h2></div><div class="divide-y divide-slate-800">@forelse($collections as $collection)<div class="flex items-center justify-between gap-3 p-4 text-xs"><div><div class="font-bold text-white">{{ $collection->label ?: $collection->type }}</div><div class="mt-1 text-[10px] text-slate-500">{{ $collection->created_at?->format('Y-m-d H:i') }}</div></div><span class="rounded-full bg-purple-500/10 px-2.5 py-1 font-bold text-purple-300">{{ $collection->reels_count }}</span></div>@empty<div class="p-5 text-xs text-slate-500">{{ $isAr ? 'لا توجد مجموعات بعد.' : 'No collections yet.' }}</div>@endforelse</div></div>
        </section>
    </main>
</body>
</html>
