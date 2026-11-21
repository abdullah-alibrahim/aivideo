<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.verify_email_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-5 py-12">
        <section class="w-full rounded-3xl border border-slate-800 bg-slate-900 p-7 shadow-2xl">
            <div class="mb-6 flex items-center justify-between gap-4">
                <a href="{{ route('landing') }}" class="text-sm font-bold text-amber-300 hover:text-amber-200">AI Reels Studio</a>
                <nav class="flex items-center gap-3 text-xs font-bold" aria-label="{{ __('landing.navigation.language') }}">
                    <a href="{{ route('verification.notice', ['lang' => 'ar']) }}" lang="ar" hreflang="ar" class="text-slate-300 hover:text-white">العربية</a>
                    <a href="{{ route('verification.notice', ['lang' => 'en']) }}" lang="en" hreflang="en" class="text-slate-300 hover:text-white">English</a>
                </nav>
            </div>
            <h1 class="text-2xl font-black">{{ __('auth.verify_email_title') }}</h1>
            <p role="status" class="mt-3 text-sm leading-7 text-slate-300">{{ __('auth.verify_email_description') }}</p>
            @if(session('status') === 'verification-link-sent')
                <div role="status" class="mt-5 rounded-xl border border-emerald-500/30 bg-emerald-950/40 p-3 text-sm text-emerald-200">{{ __('auth.verification_link_sent') }}</div>
            @endif
            @if($errors->any())
                <div role="alert" class="mt-5 rounded-xl border border-rose-500/30 bg-rose-950/40 p-3 text-sm text-rose-200">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
                @csrf
                <button class="w-full rounded-xl bg-amber-400 px-4 py-3 font-black text-slate-950 transition hover:bg-amber-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-200">{{ __('auth.resend_verification') }}</button>
            </form>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button class="w-full rounded-xl border border-slate-700 bg-slate-800 px-4 py-3 text-sm font-bold text-slate-200 transition hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400">{{ __('auth.logout') }}</button>
            </form>
        </section>
    </main>
</body>
</html>
