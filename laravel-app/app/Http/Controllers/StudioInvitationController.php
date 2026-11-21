<?php

namespace App\Http\Controllers;

use App\Models\StudioInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudioInvitationController extends Controller
{
    public function show(string $token): View
    {
        abort_unless($this->invitation($token) !== null, 404);

        return view('auth.accept-invitation', ['token' => $token]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($token, $validated): User {
            $invitation = StudioInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            abort_unless($invitation !== null, 404);
            abort_if(User::query()->where('email', $invitation->email)->exists(), 404);

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
            ]);
            $user->forceFill(['is_admin' => false])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        $user->sendEmailVerificationNotification();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }

    private function invitation(string $token): ?StudioInvitation
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/D', $token) !== 1) {
            return null;
        }

        return StudioInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
