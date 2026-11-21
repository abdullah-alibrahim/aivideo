<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('landing.description') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ __('landing.title') }}">
    <meta property="og:description" content="{{ __('landing.description') }}">
    <meta name="theme-color" content="#07111f">
    <title>{{ __('landing.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="relative min-h-screen bg-[#07111f] font-sans text-[#f8fafc] antialiased">
    <div class="aurora-bg" aria-hidden="true"></div>

    <header class="glass-header sticky top-0 z-50 border-b border-white/5">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3.5 sm:px-7">
            <a href="{{ route('landing') }}" class="flex shrink-0 items-center gap-2.5 text-lg font-black tracking-tight focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-400" aria-label="AI Reels Studio">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-amber-400/30 bg-amber-400/10 text-amber-400 shadow-lg shadow-amber-500/10">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg>
                </span>
                <span><span class="text-amber-400">AI</span> Reels Studio</span>
            </a>
            <nav class="hidden items-center gap-7 text-sm font-semibold text-slate-300 md:flex" aria-label="{{ __('landing.navigation.features') }}">
                <a href="#features" class="transition hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-400">{{ __('landing.navigation.features') }}</a>
                <a href="#how-it-works" class="transition hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-400">{{ __('landing.navigation.process') }}</a>
            </nav>
            <div class="flex items-center gap-2 sm:gap-3">
                <div class="flex items-center rounded-xl border border-white/10 bg-white/5 p-1 text-xs font-bold" role="group" aria-label="{{ __('landing.navigation.language') }}">
                    <a href="{{ route('landing', ['lang' => 'ar']) }}" lang="ar" hreflang="ar" aria-current="{{ app()->getLocale() === 'ar' ? 'page' : 'false' }}" class="rounded-lg px-2 py-1.5 transition {{ app()->getLocale() === 'ar' ? 'bg-amber-400 text-slate-950' : 'text-slate-400 hover:text-white' }}">ع</a>
                    <a href="{{ route('landing', ['lang' => 'en']) }}" lang="en" hreflang="en" aria-current="{{ app()->getLocale() === 'en' ? 'page' : 'false' }}" class="rounded-lg px-2 py-1.5 transition {{ app()->getLocale() === 'en' ? 'bg-amber-400 text-slate-950' : 'text-slate-400 hover:text-white' }}">EN</a>
                </div>
                <a href="{{ route('login') }}" class="rounded-lg px-2 py-2 text-xs font-bold text-slate-300 transition hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400 sm:px-3 sm:text-sm">{{ __('landing.navigation.sign_in') }}</a>
                <a href="{{ url('/register') }}" class="rounded-xl bg-amber-400 px-3 py-2 text-xs font-black text-slate-950 shadow-lg shadow-amber-500/20 transition hover:bg-amber-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200 sm:px-4 sm:py-2.5 sm:text-sm">{{ __('landing.navigation.sign_up') }}</a>
            </div>
        </div>
    </header>

    <main>
        <section class="mx-auto grid max-w-7xl items-center gap-12 px-5 pb-16 pt-12 sm:px-8 sm:pb-24 sm:pt-16 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16 lg:pt-20">
            <div class="text-start">
                <p class="inline-flex items-center gap-2 rounded-full border border-amber-400/25 bg-amber-400/10 px-3.5 py-1.5 text-xs font-bold text-amber-200">
                    <span class="relative flex h-1.5 w-1.5" aria-hidden="true">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-60"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-amber-300"></span>
                    </span>
                    {{ __('landing.hero.eyebrow') }}
                </p>
                <h1 class="gradient-text mt-6 max-w-2xl text-4xl font-black leading-[1.18] tracking-tight sm:text-5xl lg:text-6xl">{{ __('landing.hero.title') }}</h1>
                <p class="mt-6 max-w-xl text-base leading-8 text-slate-300 sm:text-lg">{{ __('landing.hero.description') }}</p>
                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <a href="{{ url('/register') }}" class="group inline-flex items-center gap-2 rounded-xl bg-amber-400 px-6 py-3.5 text-sm font-black text-slate-950 shadow-lg shadow-amber-500/25 transition hover:bg-amber-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-200">
                        {{ __('landing.hero.primary_cta') }}
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    </a>
                    <a href="#how-it-works" class="rounded-xl border border-white/10 bg-white/[0.03] px-5 py-3.5 text-sm font-bold text-slate-100 backdrop-blur transition hover:border-white/25 hover:bg-white/[0.06] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-400">{{ __('landing.hero.secondary_cta') }}</a>
                </div>
                <p class="mt-5 flex items-center gap-2 text-xs text-slate-400">
                    <svg class="h-4 w-4 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                    {{ __('landing.hero.reassurance') }}
                </p>
            </div>

            <figure class="relative mx-auto w-full max-w-[390px] lg:ms-auto">
                <div class="absolute -inset-6 rounded-[2.5rem] bg-amber-400/10 blur-3xl" aria-hidden="true"></div>
                <div class="relative rounded-[1.8rem] border border-white/10 bg-white/[0.04] p-1.5 shadow-2xl shadow-black/40 backdrop-blur">
                    <div class="relative aspect-[9/11] overflow-hidden rounded-[1.45rem] bg-slate-800">
                        <video
                            data-landing-video
                            class="h-full w-full object-cover"
                            controls
                            muted
                            loop
                            playsinline
                            preload="metadata"
                            poster="{{ asset('images/landing-nature-poster.jpg') }}"
                            aria-label="{{ __('landing.hero.preview_alt') }}"
                        >
                            <source src="{{ asset('videos/landing-nature.webm') }}" type="video/webm">
                        </video>
                        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-slate-950/70 to-transparent" aria-hidden="true"></div>
                        <p data-video-playback-status role="status" hidden class="absolute inset-x-3 bottom-14 rounded-lg bg-slate-950/85 px-3 py-2 text-center text-xs leading-5 text-white">{{ __('landing.hero.playback_fallback') }}</p>
                    </div>
                </div>
                <figcaption class="mt-4 flex items-center justify-center gap-2 text-center text-xs text-slate-400">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-400" aria-hidden="true"></span>
                    {{ __('landing.hero.preview_label') }}
                </figcaption>
            </figure>
        </section>

        <section id="features" class="border-y border-white/5 bg-white/[0.015]">
            <div class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-20">
                <div class="mb-10 max-w-2xl text-start">
                    <p class="text-sm font-bold text-amber-300">{{ __('landing.features.eyebrow') }}</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">{{ __('landing.features.title') }}</h2>
                </div>
                <div class="grid gap-5 sm:grid-cols-3">
                    <article class="card-lift group relative overflow-hidden rounded-2xl border border-white/[0.07] bg-[#0b1728] p-6 hover:border-amber-400/40 hover:shadow-2xl hover:shadow-amber-500/10 sm:p-7">
                        <span aria-hidden="true" class="absolute end-5 top-5 text-xs font-black tabular-nums text-slate-600">01</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-amber-400/25 bg-amber-400/10 text-amber-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-extrabold">{{ __('landing.features.script_title') }}</h3>
                        <p class="mt-2.5 text-sm leading-7 text-slate-400">{{ __('landing.features.script_body') }}</p>
                    </article>
                    <article class="card-lift group relative overflow-hidden rounded-2xl border border-white/[0.07] bg-[#0b1728] p-6 hover:border-amber-400/40 hover:shadow-2xl hover:shadow-amber-500/10 sm:p-7">
                        <span aria-hidden="true" class="absolute end-5 top-5 text-xs font-black tabular-nums text-slate-600">02</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-sky-400/25 bg-sky-400/10 text-sky-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="5" width="14" height="14" rx="2.5"/><path d="m16.5 12 5-3v9l-5-3"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-extrabold">{{ __('landing.features.video_title') }}</h3>
                        <p class="mt-2.5 text-sm leading-7 text-slate-400">{{ __('landing.features.video_body') }}</p>
                    </article>
                    <article class="card-lift group relative overflow-hidden rounded-2xl border border-white/[0.07] bg-[#0b1728] p-6 hover:border-amber-400/40 hover:shadow-2xl hover:shadow-amber-500/10 sm:p-7">
                        <span aria-hidden="true" class="absolute end-5 top-5 text-xs font-black tabular-nums text-slate-600">03</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-emerald-400/25 bg-emerald-400/10 text-emerald-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.7 3.8 9S14.5 18.4 12 21c-2.5-2.6-3.8-5.7-3.8-9S9.5 5.6 12 3Z"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-extrabold">{{ __('landing.features.language_title') }}</h3>
                        <p class="mt-2.5 text-sm leading-7 text-slate-400">{{ __('landing.features.language_body') }}</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-24">
            <div class="mb-10 max-w-2xl text-start">
                <p class="text-sm font-bold text-amber-300">{{ __('landing.process.eyebrow') }}</p>
                <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">{{ __('landing.process.title') }}</h2>
            </div>
            <ol class="relative grid gap-5 sm:grid-cols-3 sm:gap-6">
                <span aria-hidden="true" class="absolute inset-x-10 top-9 hidden h-px bg-gradient-to-r from-transparent via-amber-400/30 to-transparent sm:block"></span>
                <li class="relative rounded-2xl border border-white/[0.07] bg-white/[0.02] p-6">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full border border-amber-400/40 bg-[#07111f] text-sm font-black text-amber-300">1</span>
                    <h3 class="mt-4 text-base font-extrabold">{{ __('landing.process.step_idea_title') }}</h3>
                    <p class="mt-2 text-sm leading-7 text-slate-400">{{ __('landing.process.step_idea') }}</p>
                </li>
                <li class="relative rounded-2xl border border-white/[0.07] bg-white/[0.02] p-6">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full border border-amber-400/40 bg-[#07111f] text-sm font-black text-amber-300">2</span>
                    <h3 class="mt-4 text-base font-extrabold">{{ __('landing.process.step_build_title') }}</h3>
                    <p class="mt-2 text-sm leading-7 text-slate-400">{{ __('landing.process.step_build') }}</p>
                </li>
                <li class="relative rounded-2xl border border-white/[0.07] bg-white/[0.02] p-6">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full border border-amber-400/40 bg-[#07111f] text-sm font-black text-amber-300">3</span>
                    <h3 class="mt-4 text-base font-extrabold">{{ __('landing.process.step_export_title') }}</h3>
                    <p class="mt-2 text-sm leading-7 text-slate-400">{{ __('landing.process.step_export') }}</p>
                </li>
            </ol>
        </section>

        <section class="mx-auto max-w-7xl px-5 pb-20 sm:px-8">
            <div class="relative overflow-hidden rounded-3xl border border-amber-400/20 bg-gradient-to-br from-amber-400/[0.09] via-[#0b1728] to-sky-400/[0.07] px-6 py-12 sm:px-12 sm:py-14">
                <div class="absolute -end-24 -top-24 h-72 w-72 rounded-full bg-amber-400/10 blur-3xl" aria-hidden="true"></div>
                <div class="relative flex flex-col items-start justify-between gap-7 md:flex-row md:items-center">
                    <h2 class="max-w-xl text-2xl font-black leading-relaxed sm:text-3xl">{{ __('landing.closing.title') }}</h2>
                    <a href="{{ url('/register') }}" class="group inline-flex shrink-0 items-center gap-2 rounded-xl bg-amber-400 px-6 py-3.5 text-sm font-black text-slate-950 shadow-lg shadow-amber-500/25 transition hover:bg-amber-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-200">
                        {{ __('landing.closing.cta') }}
                        <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    </a>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/5">
        <div class="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-8 text-xs text-slate-400 sm:px-8 md:flex-row md:items-center md:justify-between">
            <div class="flex flex-col gap-1.5">
                <p class="text-sm font-bold text-slate-200"><span class="text-amber-400">AI</span> Reels Studio</p>
                <p>{{ __('landing.footer.tagline') }}</p>
            </div>
            <p class="max-w-md leading-6 md:text-end">
                {{ __('landing.footer.video_credit') }}
                <a href="https://commons.wikimedia.org/wiki/File:Waves_at_Hikkaduwa_beach,_Sri_Lanka.webm" target="_blank" rel="noopener noreferrer" class="underline decoration-slate-600 underline-offset-2 transition hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400">{{ __('landing.footer.video_title') }}</a>
                <span aria-hidden="true"> · </span>
                <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener noreferrer" class="underline decoration-slate-600 underline-offset-2 transition hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400">{{ __('landing.footer.license') }}</a>
            </p>
        </div>
    </footer>
</body>
</html>
