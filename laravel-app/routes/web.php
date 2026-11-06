<?php

use App\Http\Controllers\ProviderApiKeyController;
use App\Http\Controllers\ReelController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ReelController::class, 'index'])->name('reels.index');
Route::get('/wizard', [ReelController::class, 'wizard'])->name('reels.wizard');
Route::middleware('studio.access')->group(function (): void {
    Route::get('/settings/provider-keys', [ProviderApiKeyController::class, 'index'])->name('settings.provider-keys');
    Route::post('/settings/provider-keys', [ProviderApiKeyController::class, 'store'])->name('settings.provider-keys.store');
    Route::patch('/settings/provider-keys/voice-ids', [ProviderApiKeyController::class, 'updateVoiceIds'])->name('settings.provider-keys.voice-ids');
    Route::patch('/settings/provider-keys/{providerApiKey}', [ProviderApiKeyController::class, 'update'])->name('settings.provider-keys.update');
    Route::post('/settings/provider-keys/{providerApiKey}/test', [ProviderApiKeyController::class, 'test'])->middleware('throttle:10,1')->name('settings.provider-keys.test');
    Route::patch('/settings/provider-keys/{providerApiKey}/toggle', [ProviderApiKeyController::class, 'toggle'])->name('settings.provider-keys.toggle');
    Route::delete('/settings/provider-keys/{providerApiKey}', [ProviderApiKeyController::class, 'destroy'])->name('settings.provider-keys.destroy');

    Route::post('/reels/generate', [ReelController::class, 'store'])->middleware('throttle:studio-render')->name('reels.store');
    Route::get('/reels/{reel}/status', [ReelController::class, 'status'])->name('reels.status');
    Route::delete('/reels/{reel}', [ReelController::class, 'destroy'])->name('reels.destroy');

    Route::post('/api/ai/suggest-idea', [ReelController::class, 'suggestIdea'])->middleware('throttle:studio-ai')->name('ai.idea');
    Route::post('/api/ai/suggest-hook', [ReelController::class, 'suggestHook'])->middleware('throttle:studio-ai')->name('ai.hook');
    Route::post('/api/ai/refine-script', [ReelController::class, 'refineScript'])->middleware('throttle:studio-ai')->name('ai.script');
    Route::post('/api/ai/expert-advice', [ReelController::class, 'expertAdvice'])->middleware('throttle:studio-ai')->name('ai.advice');
    Route::post('/api/ai/generate-captions', [ReelController::class, 'generateCaptions'])->middleware('throttle:studio-ai')->name('ai.captions');

    Route::get('/video-stream/{reel}', [ReelController::class, 'stream'])->name('reels.stream');
});
