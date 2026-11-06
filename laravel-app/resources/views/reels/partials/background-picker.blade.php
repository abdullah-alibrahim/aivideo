@php
    $isAr = ($lang ?? 'ar') === 'ar';
    $selectedBackground = old('bg_preset', $backgrounds[0]['id'] ?? '');
@endphp

<div class="space-y-3" data-background-picker>
    <div class="flex items-end justify-between gap-3">
        <div>
            <label class="block text-xs font-bold text-slate-200">
                {{ $isAr ? 'اختر خلفية متحركة' : 'Choose a motion background' }}
            </label>
            <p class="mt-1 text-[10px] leading-relaxed text-slate-400">
                {{ $isAr ? 'خلفيات أصلية مولّدة محلياً، صامتة وجاهزة للريلز.' : 'Original locally generated backgrounds, silent and Reels-ready.' }}
            </p>
        </div>
        <span class="shrink-0 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-[9px] font-bold text-emerald-300">
            {{ $isAr ? 'محلي • بلا خدمة خارجية' : 'Local • no external service' }}
        </span>
    </div>

    <fieldset class="grid grid-cols-2 gap-2 sm:grid-cols-3" aria-label="{{ $isAr ? 'خلفية الفيديو' : 'Video background' }}">
        @foreach ($backgrounds as $background)
            @php($isSelected = $selectedBackground === $background['id'])
            <label class="relative block cursor-pointer overflow-hidden rounded-xl border transition hover:border-amber-400/70 {{ $isSelected ? 'border-amber-400 ring-2 ring-amber-400/30' : 'border-slate-700' }}" data-background-option>
                <input type="radio" name="bg_preset" value="{{ $background['id'] }}" class="peer sr-only" @checked($isSelected)>
                <img src="{{ $background['poster_url'] }}" alt="{{ $background['name'][$isAr ? 'ar' : 'en'] }}" loading="lazy" class="aspect-[9/12] w-full object-cover transition duration-300 peer-checked:scale-[1.02]">
                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950 via-slate-950/85 to-transparent px-2.5 pb-2.5 pt-9">
                    <span class="flex items-center justify-between gap-1 text-[11px] font-bold text-white">
                        <span>{{ $background['name'][$isAr ? 'ar' : 'en'] }}</span>
                        <span class="h-2 w-2 shrink-0 rounded-full border border-white/80 {{ $isSelected ? 'bg-amber-400' : 'bg-transparent' }}" data-choice-indicator></span>
                    </span>
                    <span class="mt-0.5 block text-[9px] leading-relaxed text-slate-300/90">{{ $background['description'][$isAr ? 'ar' : 'en'] }}</span>
                </span>
                <span class="absolute left-2 top-2 rounded-full border border-white/10 bg-slate-950/75 px-2 py-0.5 text-[8px] font-semibold tracking-wide text-slate-200">{{ $isAr ? '6 ث • صامت' : '6s • silent' }}</span>
            </label>
        @endforeach
    </fieldset>
    <p class="text-[10px] leading-relaxed text-slate-400" data-background-help>
        {{ $isAr ? 'إذا رفعت فيديو خاصاً، سيُستخدم بدلاً من الخلفية المختارة. الصوت يُرفع بشكل منفصل.' : 'If you upload your own video, it replaces the selected background. Audio is uploaded separately.' }}
    </p>
</div>
