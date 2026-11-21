<!DOCTYPE html>
@php
    $isAr = ($lang ?? 'ar') === 'ar';
    $dir = $isAr ? 'rtl' : 'ltr';
@endphp
<html lang="{{ $isAr ? 'ar' : 'en' }}" dir="{{ $dir }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isAr ? 'إدارة المستخدمين' : 'Manage users' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <main class="mx-auto max-w-5xl space-y-6 px-4 py-10 sm:px-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-emerald-300">{{ $isAr ? 'لوحة الإدارة' : 'Admin' }}</p>
                <h1 class="mt-2 text-2xl font-black">{{ $isAr ? 'إدارة المستخدمين' : 'Manage users' }}</h1>
                <p class="mt-1 text-sm text-slate-400">{{ $isAr ? 'أنشئ حساباً جاهزاً للدخول دون طلب تأكيد البريد.' : 'Create an account that can sign in without email verification.' }}</p>
            </div>
            <a href="{{ route('reels.index', ['lang' => $lang]) }}" class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-bold text-slate-200 hover:border-slate-500">
                {{ $isAr ? 'العودة للاستوديو' : 'Back to studio' }}
            </a>
        </header>

        @if (session('success'))
            <div role="status" class="rounded-xl border border-emerald-500/30 bg-emerald-950/40 p-4 text-sm text-emerald-200">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-rose-500/30 bg-rose-950/40 p-4 text-sm text-rose-200">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
            <form method="POST" action="{{ route('admin.users.store', ['lang' => $lang]) }}" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
                @csrf
                <h2 class="text-lg font-bold">{{ $isAr ? 'إضافة مستخدم' : 'Create user' }}</h2>
                <label class="block space-y-1.5 text-sm font-semibold">
                    <span>{{ $isAr ? 'البريد الإلكتروني' : 'Email address' }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white focus:border-emerald-400 focus:outline-none">
                </label>
                <label class="block space-y-1.5 text-sm font-semibold">
                    <span>{{ $isAr ? 'كلمة المرور (8 أحرف على الأقل)' : 'Password (at least 8 characters)' }}</span>
                    <input type="password" name="password" required minlength="8" maxlength="255" autocomplete="new-password" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white focus:border-emerald-400 focus:outline-none">
                </label>
                <label class="block space-y-1.5 text-sm font-semibold">
                    <span>{{ $isAr ? 'تأكيد كلمة المرور' : 'Password confirmation' }}</span>
                    <input type="password" name="password_confirmation" required minlength="8" maxlength="255" autocomplete="new-password" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white focus:border-emerald-400 focus:outline-none">
                </label>
                <p class="text-xs leading-relaxed text-slate-400">{{ $isAr ? 'سيُشتق اسم العرض من البريد، وسيصبح الحساب جاهزاً للدخول دون تأكيد البريد.' : 'The display name is derived from the email. No email verification is required.' }}</p>
                <button type="submit" class="w-full rounded-xl bg-emerald-400 px-4 py-3 text-sm font-black text-slate-950 transition hover:bg-emerald-300">
                    {{ $isAr ? 'إنشاء الحساب' : 'Create account' }}
                </button>
            </form>

            <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70">
                <div class="border-b border-slate-800 px-5 py-4">
                    <h2 class="font-bold">{{ $isAr ? 'الحسابات الحالية' : 'Existing accounts' }}</h2>
                </div>
                <div class="divide-y divide-slate-800">
                    @forelse ($users as $user)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">{{ $user->name }}</p>
                                <p class="truncate text-xs text-slate-400">{{ $user->email }}</p>
                                @if ($user->suspension_reason)
                                    <p class="mt-1 text-xs text-rose-300">{{ $user->suspension_reason }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @if ($user->trashed())
                                    <span class="shrink-0 rounded-full border border-slate-500/30 bg-slate-500/10 px-2.5 py-1 text-[10px] text-slate-300">{{ $isAr ? 'محذوف' : 'Deleted' }}</span>
                                    <form method="POST" action="{{ route('admin.users.restore', ['userId' => $user->id, 'lang' => $lang]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="rounded-lg border border-sky-500/30 px-3 py-1.5 text-xs font-bold text-sky-200 hover:bg-sky-500/10">{{ $isAr ? 'استعادة' : 'Restore' }}</button>
                                    </form>
                                @else
                                    <span class="shrink-0 rounded-full border px-2.5 py-1 text-[10px] {{ $user->isStudioAdmin() ? 'border-violet-500/30 bg-violet-500/10 text-violet-200' : ($user->suspended_at ? 'border-rose-500/30 bg-rose-500/10 text-rose-200' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200') }}">
                                        {{ $user->isStudioAdmin() ? ($isAr ? 'مدير' : 'Admin') : ($user->suspended_at ? ($isAr ? 'موقوف' : 'Suspended') : ($isAr ? 'نشط' : 'Active')) }}
                                    </span>
                                    @unless ((int) $currentUserId === (int) $user->id)
                                        <form method="POST" action="{{ route('admin.users.suspension', ['user' => $user->id, 'lang' => $lang]) }}" class="flex flex-wrap items-center gap-2">
                                            @csrf
                                            @method('PATCH')
                                            @if (! $user->suspended_at)
                                                <input type="text" name="suspension_reason" maxlength="255" placeholder="{{ $isAr ? 'سبب الإيقاف (اختياري)' : 'Reason (optional)' }}" class="w-36 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-xs text-white">
                                            @endif
                                            <button type="submit" class="rounded-lg border px-3 py-1.5 text-xs font-bold {{ $user->suspended_at ? 'border-emerald-500/30 text-emerald-200 hover:bg-emerald-500/10' : 'border-amber-500/30 text-amber-200 hover:bg-amber-500/10' }}">
                                                {{ $user->suspended_at ? ($isAr ? 'إعادة تفعيل' : 'Reactivate') : ($isAr ? 'إيقاف' : 'Suspend') }}
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.users.destroy', ['user' => $user->id, 'lang' => $lang]) }}" onsubmit="return confirm('{{ $isAr ? 'سيتم إخفاء الحساب مع الاحتفاظ ببياناته، ويمكن استعادته لاحقاً. متابعة؟' : 'This account will be hidden but its data retained. It can be restored later. Continue?' }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg border border-rose-500/30 px-3 py-1.5 text-xs font-bold text-rose-200 hover:bg-rose-500/10">{{ $isAr ? 'حذف' : 'Delete' }}</button>
                                        </form>
                                    @endunless
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-slate-400">{{ $isAr ? 'لا يوجد مستخدمون بعد.' : 'No users yet.' }}</p>
                    @endforelse
                </div>
                <div class="border-t border-slate-800 px-5 py-3">{{ $users->links() }}</div>
            </section>
        </section>
    </main>
</body>
</html>
