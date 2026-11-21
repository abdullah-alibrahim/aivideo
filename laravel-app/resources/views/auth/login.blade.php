<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07111f">
    <title>{{ __('auth.login_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="relative min-h-screen bg-[#07111f] font-sans text-slate-100 antialiased">
    <div class="aurora-bg" aria-hidden="true"></div>

    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-5 py-12">
        <a href="{{ route('landing') }}" class="mx-auto mb-6 flex items-center gap-2.5 text-lg font-black tracking-tight focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-400" aria-label="AI Reels Studio">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl border border-amber-400/30 bg-amber-400/10 text-amber-400 shadow-lg shadow-amber-500/10">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg>
            </span>
            <span><span class="text-amber-400">AI</span> Reels Studio</span>
        </a>

        <section class="w-full rounded-3xl border border-white/10 bg-[#0b1728]/85 p-7 shadow-2xl shadow-black/40 backdrop-blur-xl sm:p-8">
            <div class="mb-6 flex justify-end">
                <div class="flex items-center rounded-xl border border-white/10 bg-white/5 p-1 text-xs font-bold" role="group" aria-label="{{ __('landing.navigation.language') }}">
                    <a href="{{ route('login', ['lang' => 'ar']) }}" lang="ar" hreflang="ar" class="rounded-lg px-2.5 py-1.5 transition {{ app()->getLocale() === 'ar' ? 'bg-amber-400 text-slate-950' : 'text-slate-400 hover:text-white' }}">العربية</a>
                    <a href="{{ route('login', ['lang' => 'en']) }}" lang="en" hreflang="en" class="rounded-lg px-2.5 py-1.5 transition {{ app()->getLocale() === 'en' ? 'bg-amber-400 text-slate-950' : 'text-slate-400 hover:text-white' }}">English</a>
                </div>
            </div>
            <h1 class="text-2xl font-black">{{ __('auth.login_title') }}</h1>
            <p class="mt-2 text-sm leading-6 text-slate-400">{{ __('auth.login_description') }}</p>
            @if($errors->any())
                <div role="alert" class="mt-5 rounded-xl border border-rose-500/30 bg-rose-950/40 p-3 text-sm text-rose-200">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label class="mb-1.5 block text-sm font-bold" for="email">{{ __('auth.email') }}</label>
                    <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" class="w-full rounded-xl border border-white/10 bg-slate-950/70 px-3.5 py-3 text-white placeholder-slate-500 transition focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-bold" for="password">{{ __('auth.password') }}</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required class="w-full rounded-xl border border-white/10 bg-slate-950/70 px-3.5 py-3 text-white placeholder-slate-500 transition focus:border-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-400/30">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" name="remember" value="1" class="rounded border-slate-600 bg-slate-950 accent-amber-500">
                    {{ __('auth.remember') }}
                </label>
                <button class="w-full rounded-xl bg-amber-400 px-4 py-3 font-black text-slate-950 shadow-lg shadow-amber-500/20 transition hover:bg-amber-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200">{{ __('auth.sign_in') }}</button>
            </form>
            <div class="my-5 flex items-center gap-3 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                <span class="h-px flex-1 bg-white/10" aria-hidden="true"></span>
                <span aria-hidden="true">•</span>
                <span class="h-px flex-1 bg-white/10" aria-hidden="true"></span>
            </div>
            <a href="{{ route('oauth.google.redirect') }}" class="flex w-full items-center justify-center gap-2.5 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm font-bold text-slate-100 transition hover:border-white/25 hover:bg-white/[0.06] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400">
                <svg class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true"><path fill="#EA4335" d="M12 10.2v3.9h5.5c-.24 1.4-1.7 4.2-5.5 4.2-3.3 0-6-2.7-6-6.1s2.7-6.1 6-6.1c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.7 3.5 14.6 2.5 12 2.5 6.8 2.5 2.5 6.8 2.5 12s4.3 9.5 9.5 9.5c5.5 0 9.1-3.9 9.1-9.3 0-.6-.1-1.1-.2-1.6H12Z"/></svg>
                {{ __('auth.continue_with_google') }}
            </a>
            <p class="mt-5 text-center text-sm text-slate-400">
                {{ __('auth.no_account') }}
                <a href="{{ route('register') }}" class="font-bold text-amber-300 underline underline-offset-2 transition hover:text-amber-200">{{ __('auth.register') }}</a>
            </p>
        </section>
    </main>
</body>
</html>
