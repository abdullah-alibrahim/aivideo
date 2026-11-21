<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.invitation_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-5 py-12">
        <section class="w-full rounded-3xl border border-slate-800 bg-slate-900 p-7 shadow-2xl">
            <h1 class="text-2xl font-black">{{ __('auth.invitation_title') }}</h1>
            <p class="mt-2 text-sm text-slate-400">{{ __('auth.invitation_description') }}</p>
            @if($errors->any())
                <div role="alert" class="mt-5 rounded-xl border border-rose-500/30 bg-rose-950/40 p-3 text-sm text-rose-200">{{ $errors->first() }}</div>
            @endif
            <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-6 space-y-4">
                @csrf
                <label class="block text-sm font-bold" for="name">{{ __('auth.name') }}</label>
                <input id="name" name="name" autocomplete="name" required value="{{ old('name') }}" class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white">
                <label class="block text-sm font-bold" for="password">{{ __('auth.password') }}</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white">
                <label class="block text-sm font-bold" for="password_confirmation">{{ __('auth.confirm_password') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-white">
                <button class="w-full rounded-xl bg-amber-400 px-4 py-3 font-black text-slate-950">{{ __('auth.accept_invitation') }}</button>
            </form>
        </section>
    </main>
</body>
</html>
