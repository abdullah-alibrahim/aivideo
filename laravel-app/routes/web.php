<?php

use App\Http\Controllers\ProviderApiKeyController;
use App\Http\Controllers\ReelController;
use App\Models\Reel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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
    Route::get('/reels/{reel}', [ReelController::class, 'show'])->name('reels.show');
    Route::get('/reels/{reel}/status', [ReelController::class, 'status'])->name('reels.status');
    Route::delete('/reels/{reel}', [ReelController::class, 'destroy'])->name('reels.destroy');

    Route::post('/api/ai/suggest-idea', [ReelController::class, 'suggestIdea'])->middleware('throttle:studio-ai')->name('ai.idea');
    Route::post('/api/ai/suggest-hook', [ReelController::class, 'suggestHook'])->middleware('throttle:studio-ai')->name('ai.hook');
    Route::post('/api/ai/refine-script', [ReelController::class, 'refineScript'])->middleware('throttle:studio-ai')->name('ai.script');
    Route::post('/api/ai/expert-advice', [ReelController::class, 'expertAdvice'])->middleware('throttle:studio-ai')->name('ai.advice');
    Route::post('/api/ai/generate-captions', [ReelController::class, 'generateCaptions'])->middleware('throttle:studio-ai')->name('ai.captions');

    Route::get('/video-stream/{reel}', function (Request $request, Reel $reel) {
    $disk = Storage::disk('public');
    $relativePath = $reel->output_video_path;

    if (! $relativePath || ! $disk->exists($relativePath)) {
        abort(404);
    }

    $path = $disk->path($relativePath);
    $size = filesize($path);
    $start = 0;
    $end = max(0, $size - 1);
    $status = 200;
    $range = $request->header('Range');

    if ($range !== null) {
        if (! preg_match('/^bytes=(\d*)-(\d*)$/D', trim($range), $matches)
            || ($matches[1] === '' && $matches[2] === '')
            || $size === 0) {
            return response('', 416, [
                'Content-Range' => sprintf('bytes */%d', $size),
                'Accept-Ranges' => 'bytes',
            ]);
        }

        if ($matches[1] === '') {
            $suffixLength = (int) $matches[2];
            if ($suffixLength === 0) {
                return response('', 416, [
                    'Content-Range' => sprintf('bytes */%d', $size),
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            $start = max(0, $size - $suffixLength);
            $end = $size - 1;
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
        }

        if ($start >= $size || $start > $end) {
            return response('', 416, [
                'Content-Range' => sprintf('bytes */%d', $size),
                'Accept-Ranges' => 'bytes',
            ]);
        }

        $status = 206;
    }

    $length = $end - $start + 1;
    $headers = [
        'Content-Type' => 'video/mp4',
        'Accept-Ranges' => 'bytes',
        'Cache-Control' => 'no-cache, private',
        'Content-Length' => (string) $length,
    ];

    if ($status === 206) {
        $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
    }

    return response()->stream(function () use ($path, $start, $length): void {
        $file = fopen($path, 'rb');
        if ($file === false) {
            return;
        }

        if ($start > 0) {
            fseek($file, $start);
        }

        $remaining = $length;
        while ($remaining > 0 && ! feof($file)) {
            $chunk = fread($file, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $remaining -= strlen($chunk);
        }

        fclose($file);
    }, $status, $headers);
    })->name('reels.stream');
});
