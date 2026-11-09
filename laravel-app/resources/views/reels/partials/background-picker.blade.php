@php
    $isAr = ($lang ?? 'ar') === 'ar';
    $selectedBackground = old('bg_preset', $backgrounds[0]['id'] ?? '');
    $categories = [];
    foreach ($backgrounds as $background) {
        $categories[$background['category_id']] = $background['category'][$isAr ? 'ar' : 'en'];
    }
    $motionCount = count(array_filter($backgrounds, fn (array $background) => ($background['media_type'] ?? 'video') === 'video'));
    $stillCount = count($backgrounds) - $motionCount;
@endphp

<div class="space-y-4" data-background-picker>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <label class="block text-xs font-bold text-slate-200" for="backgroundLibrarySearch">
                {{ $isAr ? 'مكتبة الخلفيات' : 'Background library' }}
            </label>
            <p class="mt-1 text-[10px] leading-relaxed text-slate-400">
                {{ $isAr ? 'اختر صورة ثابتة أو حلقة متحركة صامتة، ثم جرّب معاينتها بالمرور عليها.' : 'Choose a still image or silent motion loop; hover a motion card to preview it.' }}
            </p>
        </div>
        <span class="shrink-0 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-[9px] font-bold text-emerald-300">
            {{ $isAr ? count($backgrounds).' خلفية محلية' : count($backgrounds).' local backgrounds' }}
        </span>
    </div>

    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
        <div class="relative">
            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-xs text-slate-500" aria-hidden="true"></i>
            <input id="backgroundLibrarySearch" type="search" autocomplete="off" data-background-search
                placeholder="{{ $isAr ? 'ابحث بالاسم أو الوصف أو التصنيف' : 'Search by name, description, or category' }}"
                class="w-full rounded-xl border border-slate-700 bg-slate-950/80 py-2.5 ps-9 pe-3 text-xs text-white placeholder:text-slate-500 focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/20"
                aria-label="{{ $isAr ? 'ابحث في مكتبة الخلفيات' : 'Search background library' }}">
        </div>
        <button type="button" data-background-favorites aria-pressed="false"
            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-3 text-[11px] font-bold text-slate-300 transition hover:border-amber-400/60 hover:text-white">
            <i class="fa-regular fa-star" aria-hidden="true"></i>
            <span>{{ $isAr ? 'المفضلة' : 'Favorites' }}</span>
        </button>
    </div>

    <div>
        <p class="mb-2 text-[10px] font-bold text-slate-400">{{ $isAr ? 'نوع الخلفية' : 'Media type' }}</p>
        <div class="flex gap-2 overflow-x-auto pb-1" role="group" aria-label="{{ $isAr ? 'تصفية حسب نوع الخلفية' : 'Filter by media type' }}">
            <button type="button" data-background-type="all" aria-pressed="true" class="background-filter-pill">
                {{ $isAr ? 'الكل · '.count($backgrounds) : 'All · '.count($backgrounds) }}
            </button>
            <button type="button" data-background-type="video" aria-pressed="false" class="background-filter-pill">
                <i class="fa-solid fa-film me-1" aria-hidden="true"></i>{{ $isAr ? 'متحركة · '.$motionCount : 'Motion · '.$motionCount }}
            </button>
            <button type="button" data-background-type="image" aria-pressed="false" class="background-filter-pill">
                <i class="fa-regular fa-image me-1" aria-hidden="true"></i>{{ $isAr ? 'ثابتة · '.$stillCount : 'Still · '.$stillCount }}
            </button>
        </div>
    </div>

    <div>
        <p class="mb-2 text-[10px] font-bold text-slate-400">{{ $isAr ? 'الأقسام' : 'Categories' }}</p>
        <div class="flex gap-2 overflow-x-auto pb-1" role="group" aria-label="{{ $isAr ? 'تصفية حسب القسم' : 'Filter by category' }}">
        <button type="button" data-background-category="all" aria-pressed="true" class="background-filter-pill">
            {{ $isAr ? 'الكل' : 'All' }}
        </button>
        @foreach ($categories as $categoryId => $categoryName)
            <button type="button" data-background-category="{{ $categoryId }}" aria-pressed="false" class="background-filter-pill">
                {{ $categoryName }}
            </button>
        @endforeach
        </div>
    </div>

    <p class="sr-only" data-background-results role="status" aria-live="polite"></p>
    <p class="hidden rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-5 text-center text-xs text-slate-400" data-background-empty>
        {{ $isAr ? 'ما لقينا خلفيات تطابق بحثك. جرّب كلمة أو تصنيفًا آخر.' : 'No backgrounds match that search. Try another word or category.' }}
    </p>

    <fieldset class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4" aria-label="{{ $isAr ? 'اختر صورة أو خلفية فيديو' : 'Choose an image or video background' }}">
        <legend class="sr-only">{{ $isAr ? 'الخلفية المستخدمة في الفيديو' : 'Background used in the video' }}</legend>
        @foreach ($backgrounds as $background)
            @php
                $isSelected = $selectedBackground === $background['id'];
                $searchText = strtolower(implode(' ', array_merge(array_values($background['name']), array_values($background['description']), array_values($background['category']))));
                $isMotion = ($background['media_type'] ?? 'video') === 'video';
                $durationSeconds = (int) ($background['duration_seconds'] ?? 6);
                $backgroundInputId = 'background-'.$background['id'];
            @endphp
            <article class="group relative overflow-hidden rounded-xl border {{ $isSelected ? 'border-amber-400 ring-2 ring-amber-400/30' : 'border-slate-700' }} transition duration-200" data-background-option
                data-category="{{ $background['category_id'] }}" data-type="{{ $isMotion ? 'video' : 'image' }}" data-search="{{ $searchText }}">
                <input id="{{ $backgroundInputId }}" type="radio" name="bg_preset" value="{{ $background['id'] }}" class="peer sr-only" @checked($isSelected)>
                <label for="{{ $backgroundInputId }}" class="block cursor-pointer focus:outline-none">
                    <span class="relative block aspect-[9/12] overflow-hidden bg-slate-950">
                        <img src="{{ $background['poster_url'] }}" alt="" loading="lazy" class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">
                        @if ($isMotion)
                            <video muted loop playsinline preload="none" poster="{{ $background['poster_url'] }}" data-background-video-preview data-src="{{ $background['media_url'] }}" aria-hidden="true"
                                class="pointer-events-none absolute inset-0 h-full w-full object-cover opacity-0 transition-opacity duration-300"></video>
                        @endif
                        <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950 via-slate-950/85 to-transparent px-2.5 pb-2.5 pt-9">
                            <span class="flex items-center justify-between gap-1 text-[11px] font-bold text-white">
                                <span class="truncate">{{ $background['name'][$isAr ? 'ar' : 'en'] }}</span>
                                <span class="h-2 w-2 shrink-0 rounded-full border border-white/80 {{ $isSelected ? 'bg-amber-400' : 'bg-transparent' }}" data-choice-indicator aria-hidden="true"></span>
                            </span>
                            <span class="mt-0.5 block text-[9px] leading-relaxed text-slate-300/90">{{ $background['description'][$isAr ? 'ar' : 'en'] }}</span>
                        </span>
                        <span class="absolute start-2 top-2 rounded-full border border-white/10 bg-slate-950/80 px-2 py-0.5 text-[8px] font-semibold text-slate-100">
                            {{ $isMotion ? ($isAr ? 'متحركة • '.$durationSeconds.' ث • صامتة' : 'Motion • '.$durationSeconds.'s • silent') : ($isAr ? 'صورة ثابتة' : 'Still image') }}
                        </span>
                        <span class="absolute end-2 top-2 rounded-full bg-slate-950/75 px-2 py-0.5 text-[8px] font-semibold text-slate-200">{{ $background['category'][$isAr ? 'ar' : 'en'] }}</span>
                    </span>
                </label>
                <button type="button" data-background-favorite aria-pressed="false"
                    data-label-add="{{ $isAr ? 'أضف إلى المفضلة: ' : 'Add to favorites: ' }}{{ $background['name'][$isAr ? 'ar' : 'en'] }}"
                    data-label-remove="{{ $isAr ? 'إزالة من المفضلة: ' : 'Remove from favorites: ' }}{{ $background['name'][$isAr ? 'ar' : 'en'] }}"
                    aria-label="{{ $isAr ? 'أضف إلى المفضلة: ' : 'Add to favorites: ' }}{{ $background['name'][$isAr ? 'ar' : 'en'] }}"
                    class="absolute end-2 top-10 z-10 grid h-8 w-8 place-items-center rounded-full border border-white/15 bg-slate-950/80 text-white transition hover:scale-105 hover:border-amber-300 hover:text-amber-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-amber-300">
                    <i class="fa-regular fa-star text-[11px]" aria-hidden="true"></i>
                </button>
            </article>
        @endforeach
    </fieldset>

    <p class="text-[10px] leading-relaxed text-slate-400" data-background-help>
        {{ $isAr ? 'الخلفيات من ملفات محلية أصلية. إذا رفعت فيديو خاصًا فسيحلّ محل الخلفية المختارة؛ ارفع الصوت بشكل منفصل. معاينة الحركة لا تعمل تلقائيًا عند تفعيل تقليل الحركة في جهازك.' : 'Backgrounds use original local assets. An uploaded video takes priority over this selection; upload audio separately. Motion previews stay still when your device requests reduced motion.' }}
    </p>
</div>
