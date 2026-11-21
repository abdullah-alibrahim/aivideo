<div class="flex items-center gap-2">
    @if(auth()->user()?->isStudioAdmin())
        <a href="{{ route('admin.invitations') }}" class="rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-bold text-amber-200">
            {{ ! empty($isAr) ? 'إدارة الدعوات' : 'Invitations' }}
        </a>
    @endif
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-bold text-slate-200">
            {{ ! empty($isAr) ? 'تسجيل الخروج' : 'Sign out' }}
        </button>
    </form>
</div>
