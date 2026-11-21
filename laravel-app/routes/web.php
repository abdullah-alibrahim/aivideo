<?php

use App\Http\Controllers\Admin\StudioInvitationController as AdminStudioInvitationController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CronRunController;
use App\Http\Controllers\FirstRunController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\ProviderApiKeyController;
use App\Http\Controllers\ReelController;
use App\Http\Controllers\StudioInvitationController;
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

Route::middleware(['auth', 'account.active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::middleware('verified')->group(function (): void {
        Route::get('/studio', [ReelController::class, 'index'])->name('reels.index');
        Route::get('/wizard', [ReelController::class, 'wizard'])->name('reels.wizard');

        Route::middleware('studio.admin')->group(function (): void {
            Route::get('/settings/provider-keys', [ProviderApiKeyController::class, 'index'])->name('settings.provider-keys');
            Route::get('/dashboard', [AnalyticsController::class, 'dashboard'])->name('analytics.dashboard');
            Route::post('/settings/provider-keys', [ProviderApiKeyController::class, 'store'])->name('settings.provider-keys.store');
            Route::post('/settings/provider-keys/purge', [ProviderApiKeyController::class, 'purgeSecrets'])->name('settings.provider-keys.purge');
            Route::patch('/settings/provider-keys/voice-ids', [ProviderApiKeyController::class, 'updateVoiceIds'])->name('settings.provider-keys.voice-ids');
            Route::patch('/settings/provider-keys/{providerApiKey}', [ProviderApiKeyController::class, 'update'])->name('settings.provider-keys.update');
            Route::post('/settings/provider-keys/{providerApiKey}/test', [ProviderApiKeyController::class, 'test'])->middleware('throttle:10,1')->name('settings.provider-keys.test');
            Route::patch('/settings/provider-keys/{providerApiKey}/toggle', [ProviderApiKeyController::class, 'toggle'])->name('settings.provider-keys.toggle');
            Route::delete('/settings/provider-keys/{providerApiKey}', [ProviderApiKeyController::class, 'destroy'])->name('settings.provider-keys.destroy');
            Route::get('/admin/invitations', [AdminStudioInvitationController::class, 'index'])->name('admin.invitations');
            Route::post('/admin/invitations', [AdminStudioInvitationController::class, 'store'])->name('admin.invitations.store');
            Route::get('/admin/users', [UserManagementController::class, 'index'])->name('admin.users');
            Route::post('/admin/users', [UserManagementController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('admin.users.store');
            Route::patch('/admin/users/{user}/suspension', [UserManagementController::class, 'toggleSuspension'])
                ->name('admin.users.suspension');
            Route::delete('/admin/users/{user}', [UserManagementController::class, 'destroy'])
                ->name('admin.users.destroy');
            Route::patch('/admin/users/{userId}/restore', [UserManagementController::class, 'restore'])
                ->name('admin.users.restore');
        });

        Route::post('/reels/generate', [ReelController::class, 'store'])->middleware('throttle:studio-render')->name('reels.store');
        Route::post('/reels/scenario', [ReelController::class, 'scenario'])->middleware('throttle:studio-render')->name('reels.scenario');
        Route::post('/reels/clone', [ReelController::class, 'cloneFromReference'])->middleware('throttle:studio-render')->name('reels.clone');
        Route::get('/reels/collections/{collectionId}/download', [ReelController::class, 'downloadCollection'])->name('reels.collections.download');
        Route::get('/reels/{reel}/status', [ReelController::class, 'status'])->name('reels.status');
        Route::get('/reels/{reel}', [ReelController::class, 'show'])->name('reels.show');
        Route::post('/reels/{reel}/cancel', [ReelController::class, 'cancel'])->name('reels.cancel');
        Route::post('/reels/{reel}/retry', [ReelController::class, 'retry'])->middleware('throttle:studio-render')->name('reels.retry');
        Route::post('/reels/{reel}/export-aspect', [ReelController::class, 'exportAspect'])->middleware('throttle:studio-render')->name('reels.export-aspect');
        Route::delete('/reels/{reel}', [ReelController::class, 'destroy'])->name('reels.destroy');

        Route::post('/api/preview', [ReelController::class, 'preview'])->middleware('throttle:studio-ai')->name('reels.preview');
        Route::post('/api/preview-video', [ReelController::class, 'previewVideo'])->middleware('throttle:studio-ai')->name('reels.preview-video');
        Route::post('/studio/profile/voice', [ReelController::class, 'saveVoiceProfile'])->name('reels.profile.voice');
        Route::post('/api/quality-check', [ReelController::class, 'qualityCheck'])->middleware('throttle:studio-ai')->name('reels.quality-check');
        Route::post('/api/ai/fact-check', [ReelController::class, 'factCheck'])->middleware('throttle:studio-ai')->name('ai.fact-check');
        Route::post('/api/ai/suggest-idea', [ReelController::class, 'suggestIdea'])->middleware('throttle:studio-ai')->name('ai.idea');
        Route::post('/api/ai/suggest-hook', [ReelController::class, 'suggestHook'])->middleware('throttle:studio-ai')->name('ai.hook');
        Route::post('/api/ai/generate-script', [ReelController::class, 'generateScript'])->middleware('throttle:studio-ai')->name('ai.generate-script');
        Route::post('/api/ai/refine-script', [ReelController::class, 'refineScript'])->middleware('throttle:studio-ai')->name('ai.script');
        Route::post('/api/ai/expert-advice', [ReelController::class, 'expertAdvice'])->middleware('throttle:studio-ai')->name('ai.advice');
        Route::post('/api/ai/generate-captions', [ReelController::class, 'generateCaptions'])->middleware('throttle:studio-ai')->name('ai.captions');
        Route::post('/api/ai/regenerate-part', [ReelController::class, 'regeneratePart'])->middleware('throttle:studio-ai')->name('ai.regenerate-part');
        Route::get('/video-stream/{reel}', [ReelController::class, 'stream'])->name('reels.stream');
        Route::get('/reels/{reel}/thumbnail', [ReelController::class, 'thumbnail'])->name('reels.thumbnail');
        Route::get('/reels/{reel}/subtitle', [ReelController::class, 'subtitle'])->name('reels.subtitle');
        Route::get('/previews/{previewId}', [ReelController::class, 'previewMedia'])->name('reels.preview-media');
    });
});

// Web-based first-run onboarding helpers. Locked once a verified account exists.
Route::middleware(['throttle:10,1', 'first-run.access'])->group(function (): void {
    Route::get('/first-run', [FirstRunController::class, 'index'])->name('first-run');
    Route::get('/api/first-run/check', [FirstRunController::class, 'check'])->name('first-run.check');
    Route::post('/api/first-run/fix', [FirstRunController::class, 'fix'])->name('first-run.fix');
    Route::post('/api/first-run/keys', [FirstRunController::class, 'storeKeys'])->name('first-run.keys');
});

// Cron endpoint - process pending queue jobs directly (Hostinger shared hosting).
// Rendering needs a process-spawning function. When the web PHP pool disables
// them the jobs stay queued for the CLI worker instead of being marked failed.
Route::get('/cron-run/{token}', CronRunController::class)->name('cron.run');
