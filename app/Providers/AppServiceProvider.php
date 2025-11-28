<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate; 
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // --- 這裡不應包含 Gate::define() 邏輯 ---
        // 這裡只保留 AppServiceProvider 本身需要的啟動代碼
    }
}