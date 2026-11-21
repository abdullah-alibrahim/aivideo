<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        $lang = $this->language($request);
        app()->setLocale($lang);
        $users = User::withTrashed()->latest()->paginate(25)->withQueryString();
        $currentUserId = $request->user()->getKey();

        return view('admin.users', compact('users', 'lang', 'currentUserId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $lang = $this->language($request);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $email = (string) $request->input('email');
            if (User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $validator->errors()->add('email', trans('validation.unique', ['attribute' => 'email']));
            }
        });
        $validated = $validator->validate();
        $email = $validated['email'];

        $user = User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => $validated['password'],
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $message = $lang === 'en'
            ? 'The user account was created and is ready to sign in.'
            : 'تم إنشاء حساب المستخدم وأصبح جاهزاً لتسجيل الدخول.';

        return redirect()->route('admin.users', ['lang' => $lang])->with('success', $message);
    }

    public function toggleSuspension(Request $request, User $user): RedirectResponse
    {
        $lang = $this->language($request);
        $this->ensureManageable($request, $user);
        $validated = $request->validate([
            'suspension_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($user->suspended_at !== null) {
            $user->forceFill([
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();
            $message = $lang === 'en' ? 'The user account was reactivated.' : 'تمت إعادة تفعيل حساب المستخدم.';
        } else {
            $user->forceFill([
                'suspended_at' => now(),
                'suspension_reason' => trim((string) ($validated['suspension_reason'] ?? '')) ?: null,
            ])->save();
            $message = $lang === 'en' ? 'The user account was suspended.' : 'تم إيقاف حساب المستخدم.';
        }

        return redirect()->route('admin.users', ['lang' => $lang])->with('success', $message);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensureManageable($request, $user);
        $user->delete();

        $lang = $this->language($request);
        $message = $lang === 'en'
            ? 'The user account was deleted and can be restored.'
            : 'تم حذف الحساب ويمكن استعادته لاحقاً.';

        return redirect()->route('admin.users', ['lang' => $lang])->with('success', $message);
    }

    public function restore(Request $request, int $userId): RedirectResponse
    {
        $user = User::withTrashed()->findOrFail($userId);
        abort_unless($user->trashed(), 404);
        $this->ensureManageable($request, $user);
        $user->restore();

        $lang = $this->language($request);
        $message = $lang === 'en'
            ? 'The user account was restored.'
            : 'تمت استعادة حساب المستخدم.';

        return redirect()->route('admin.users', ['lang' => $lang])->with('success', $message);
    }

    private function ensureManageable(Request $request, User $user): void
    {
        abort_if($request->user()?->is($user), 403);
    }

    private function language(Request $request): string
    {
        $language = $request->query('lang');
        if (in_array($language, ['ar', 'en'], true)) {
            return $language;
        }

        $sessionLanguage = session('app_locale');

        return in_array($sessionLanguage, ['ar', 'en'], true) ? $sessionLanguage : 'ar';
    }
}
