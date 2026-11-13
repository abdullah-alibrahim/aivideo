<?php

use App\Http\Controllers\Admin\StudioInvitationController as AdminStudioInvitationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\ProviderApiKeyController;
use App\Http\Controllers\ReelController;
use App\Http\Controllers\StudioInvitationController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingPageController::class, 'index'])->name('landing');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1')->name('register.store');
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1')->name('oauth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:10,1')->name('oauth.google.callback');
});

Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('/invitations/{token}', [StudioInvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [StudioInvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/email/verify', fn () => view('auth.verify-email'))->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request): RedirectResponse {
        $request->fulfill();

        return redirect()->intended(route('reels.index'))->with('verified', true);
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $request): RedirectResponse {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('reels.index');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    })->middleware('throttle:6,1')->name('verification.send');

    Route::middleware('verified')->group(function (): void {
        Route::get('/studio', [ReelController::class, 'index'])->name('reels.index');
        Route::get('/wizard', [ReelController::class, 'wizard'])->name('reels.wizard');

        Route::middleware('studio.admin')->group(function (): void {
            Route::get('/settings/provider-keys', [ProviderApiKeyController::class, 'index'])->name('settings.provider-keys');
            Route::get('/dashboard', [AnalyticsController::class, 'dashboard'])->name('analytics.dashboard');
            Route::post('/settings/provider-keys', [ProviderApiKeyController::class, 'store'])->name('settings.provider-keys.store');
            Route::patch('/settings/provider-keys/voice-ids', [ProviderApiKeyController::class, 'updateVoiceIds'])->name('settings.provider-keys.voice-ids');
            Route::patch('/settings/provider-keys/{providerApiKey}', [ProviderApiKeyController::class, 'update'])->name('settings.provider-keys.update');
            Route::post('/settings/provider-keys/{providerApiKey}/test', [ProviderApiKeyController::class, 'test'])->middleware('throttle:10,1')->name('settings.provider-keys.test');
            Route::patch('/settings/provider-keys/{providerApiKey}/toggle', [ProviderApiKeyController::class, 'toggle'])->name('settings.provider-keys.toggle');
            Route::delete('/settings/provider-keys/{providerApiKey}', [ProviderApiKeyController::class, 'destroy'])->name('settings.provider-keys.destroy');
            Route::get('/admin/invitations', [AdminStudioInvitationController::class, 'index'])->name('admin.invitations');
            Route::post('/admin/invitations', [AdminStudioInvitationController::class, 'store'])->name('admin.invitations.store');
        });

        Route::post('/reels/generate', [ReelController::class, 'store'])->middleware('throttle:studio-render')->name('reels.store');
        Route::post('/reels/scenario', [ReelController::class, 'scenario'])->middleware('throttle:studio-render')->name('reels.scenario');
        Route::get('/reels/collections/{collectionId}/download', [ReelController::class, 'downloadCollection'])->name('reels.collections.download');
        Route::get('/reels/{reel}/status', [ReelController::class, 'status'])->name('reels.status');
        Route::get('/reels/{reel}', [ReelController::class, 'show'])->name('reels.show');
        Route::post('/reels/{reel}/cancel', [ReelController::class, 'cancel'])->name('reels.cancel');
        Route::post('/reels/{reel}/retry', [ReelController::class, 'retry'])->middleware('throttle:studio-render')->name('reels.retry');
        Route::delete('/reels/{reel}', [ReelController::class, 'destroy'])->name('reels.destroy');

        Route::post('/api/preview', [ReelController::class, 'preview'])->middleware('throttle:studio-ai')->name('reels.preview');
        Route::post('/api/quality-check', [ReelController::class, 'qualityCheck'])->middleware('throttle:studio-ai')->name('reels.quality-check');
        Route::post('/api/ai/fact-check', [ReelController::class, 'factCheck'])->middleware('throttle:studio-ai')->name('ai.fact-check');
        Route::post('/api/ai/suggest-idea', [ReelController::class, 'suggestIdea'])->middleware('throttle:studio-ai')->name('ai.idea');
        Route::post('/api/ai/suggest-hook', [ReelController::class, 'suggestHook'])->middleware('throttle:studio-ai')->name('ai.hook');
        Route::post('/api/ai/refine-script', [ReelController::class, 'refineScript'])->middleware('throttle:studio-ai')->name('ai.script');
        Route::post('/api/ai/expert-advice', [ReelController::class, 'expertAdvice'])->middleware('throttle:studio-ai')->name('ai.advice');
        Route::post('/api/ai/generate-captions', [ReelController::class, 'generateCaptions'])->middleware('throttle:studio-ai')->name('ai.captions');
        Route::get('/video-stream/{reel}', [ReelController::class, 'stream'])->name('reels.stream');
        Route::get('/reels/{reel}/thumbnail', [ReelController::class, 'thumbnail'])->name('reels.thumbnail');
        Route::get('/reels/{reel}/subtitle', [ReelController::class, 'subtitle'])->name('reels.subtitle');
        Route::get('/previews/{previewId}', [ReelController::class, 'previewMedia'])->name('reels.preview-media');
    });
});

// Cron endpoint - process pending queue jobs directly (Hostinger shared hosting)
// exec/proc_open disabled in web context; use Artisan::call() instead
Route::get('/cron-run/{token}', function (string $token) {
    if ($token !== env('CRON_SECRET', 'aivideo_cron_2024_secure')) {
        abort(403);
    }
    // Check for pending jobs and process them
    $pending = \Illuminate\Support\Facades\DB::table('jobs')
        ->where('queue', 'default')
        ->whereNull('reserved_at')
        ->count();
    
    $processed = 0;
    if ($pending > 0) {
        // Run the queue worker synchronously (processes one batch)
        try {
            \Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--tries' => 2,
                '--max-time' => 110,
            ]);
            $processed = $pending;
        } catch (\Throwable $e) {
            \Log::error('Cron queue:work failed', ['error' => $e->getMessage()]);
        }
    }
    
    return response()->json([
        'status' => 'ok',
        'pending' => $pending,
        'triggered' => $processed > 0,
        'time' => now()->toISOString(),
    ]);
});
