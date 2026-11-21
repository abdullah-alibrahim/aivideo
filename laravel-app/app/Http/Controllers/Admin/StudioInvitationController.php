<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudioInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StudioInvitationController extends Controller
{
    public function index(): View
    {
        $invitations = StudioInvitation::query()->latest()->limit(100)->get();

        return view('admin.invitations.index', compact('invitations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);
        $email = mb_strtolower(trim($validated['email']));

        if (StudioInvitation::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists()) {
            return back()->withErrors(['email' => __('auth.invitation_exists')]);
        }

        $token = Str::random(64);
        StudioInvitation::query()->create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours((int) config('studio.auth.invitation_expiration_hours', 72)),
        ]);

        return redirect()->route('admin.invitations')
            ->with('invitation_url', route('invitations.show', ['token' => $token]))
            ->with('success', __('auth.invitation_created'));
    }
}
