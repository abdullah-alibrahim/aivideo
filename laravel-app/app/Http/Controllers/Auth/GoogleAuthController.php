<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->googleIsConfigured()) {
            return $this->loginError('google_not_configured');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->googleIsConfigured()) {
            return $this->loginError('google_not_configured');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->loginError('google_auth_failed');
        } catch (GuzzleException $exception) {
            report($exception);

            return $this->loginError('google_auth_failed');
        }

        if (! $googleUser instanceof GoogleUser) {
            return $this->loginError('google_auth_failed');
        }

        $profile = $googleUser->getRaw();
        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();

        if (
            ! is_array($profile)
            || ($profile['email_verified'] ?? $profile['verified_email'] ?? false) !== true
            || ! is_string($email)
            || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false
            || (! is_string($googleId) && ! is_int($googleId))
            || trim((string) $googleId) === ''
        ) {
            return $this->loginError('google_auth_failed');
        }

        $email = mb_strtolower(trim($email));
        $googleId = trim((string) $googleId);
        $name = trim((string) $googleUser->getName());
        $name = $name !== '' ? $name : Str::before($email, '@');
        $name = mb_substr($name, 0, 100);

        $user = DB::transaction(function () use ($email, $googleId, $name): ?User {
            $linkedUser = User::query()
                ->where('google_id', $googleId)
                ->lockForUpdate()
                ->first();

            if ($linkedUser !== null) {
                if (! $linkedUser->hasVerifiedEmail()) {
                    if (mb_strtolower(trim($linkedUser->email)) !== $email) {
                        return null;
                    }

                    $linkedUser->forceFill(['email_verified_at' => now()])->save();
                }

                return $linkedUser;
            }

            $emailUser = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($emailUser !== null) {
                if (! $emailUser->hasVerifiedEmail() || $emailUser->google_id !== null) {
                    return null;
                }

                $emailUser->forceFill(['google_id' => $googleId])->save();

                return $emailUser;
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(64),
            ]);
            $user->forceFill([
                'google_id' => $googleId,
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });

        if ($user === null) {
            return $this->loginError('google_auth_failed');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('reels.index'));
    }

    private function googleIsConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    private function loginError(string $translationKey): RedirectResponse
    {
        return redirect()->route('login')
            ->withErrors(['oauth' => __("auth.{$translationKey}")]);
    }
}
