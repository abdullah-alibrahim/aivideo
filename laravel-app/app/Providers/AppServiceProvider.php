<?php

namespace App\Providers;

use App\Services\FfmpegReelRenderer;
use App\Services\PythonReelRenderer;
use App\Services\RenderEngine;
use App\Services\SecretManager;
use App\Services\SecretStore;
use App\Support\StudioPaths;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SecretStore::class, fn () => new (SecretManager::defaultStoreClass()));
        $this->app->scoped(SecretManager::class);

        $this->app->bind(RenderEngine::class, function ($app): RenderEngine {
            return match (config('studio.engine.driver', 'ffmpeg')) {
                'python' => $app->make(PythonReelRenderer::class),
                'ffmpeg' => $app->make(FfmpegReelRenderer::class),
                default => throw new InvalidArgumentException('Unsupported render driver: '.config('studio.engine.driver')),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        StudioPaths::ensure();

        RateLimiter::for('studio-ai', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('studio-render', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
    }
}
