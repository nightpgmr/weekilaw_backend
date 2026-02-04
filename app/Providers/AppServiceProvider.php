<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Cache\CacheManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register CacheManager as singleton
        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager(config('cache.manager', []));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
