<?php

namespace App\Providers;

use App\Services\SecretManager;
use App\Services\SecretStore;
use App\Support\StudioPaths;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SecretStore::class, fn () => new (SecretManager::defaultStoreClass()));
        $this->app->scoped(SecretManager::class);
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
