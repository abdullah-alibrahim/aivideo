<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.invitations_admin_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto max-w-4xl space-y-6 px-5 py-10">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <a class="text-sm font-bold text-amber-300" href="{{ route('reels.index') }}">{{ __('auth.back_to_studio') }}</a>
                <h1 class="mt-2 text-3xl font-black">{{ __('auth.invitations_admin_title') }}</h1>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-bold">{{ __('auth.logout') }}</button>
            </form>
        </header>

        @if(session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-950/30 p-4 text-sm text-emerald-200">{{ session('success') }}</div>
        @endif
        @if(session('invitation_url'))
            <div class="rounded-xl border border-sky-500/30 bg-sky-950/30 p-4">
                <p class="text-sm font-bold">{{ __('auth.invitation_link_once') }}</p>
                <input readonly value="{{ session('invitation_url') }}" class="mt-3 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-sky-200" onclick="this.select()">
            </div>
        @endif
        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-4 text-sm text-rose-200">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-5">
            <h2 class="font-black">{{ __('auth.create_invitation') }}</h2>
            <form method="POST" action="{{ route('admin.invitations.store') }}" class="mt-4 flex flex-wrap gap-3">
                @csrf
                <label class="sr-only" for="email">{{ __('auth.email') }}</label>
                <input id="email" name="email" type="email" required value="{{ old('email') }}" placeholder="{{ __('auth.email') }}" class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5">
                <button class="rounded-xl bg-amber-400 px-5 py-2.5 font-black text-slate-950">{{ __('auth.create_invitation') }}</button>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            <h2 class="border-b border-slate-800 p-5 font-black">{{ __('auth.recent_invitations') }}</h2>
            <div class="divide-y divide-slate-800">
                @forelse($invitations as $invitation)
                    <div class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                        <span class="font-bold">{{ $invitation->email }}</span>
                        <span class="text-slate-400">
                            {{ $invitation->accepted_at ? __('auth.invitation_accepted') : ($invitation->expires_at->isPast() ? __('auth.invitation_expired') : __('auth.invitation_pending')) }}
                            · {{ $invitation->expires_at->toDateTimeString() }}
                        </span>
                    </div>
                @empty
                    <p class="p-5 text-sm text-slate-400">{{ __('auth.no_invitations') }}</p>
                @endforelse
            </div>
        </section>
    </main>
</body>
</html>
