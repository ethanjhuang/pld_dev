<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot(): void
    {
        // --- 核心授權 Gate 定義 (依賴 Token 內嵌的角色資訊 - 最高效) ---
        
        // 1. ADMIN 權限檢查 Gate
        Gate::define('is-admin', function ($user) {
            // 直接使用 Token 載入的用戶物件的 role 屬性
            return $user && $user->role === 'ADMIN';
        });

        // 2. COACH 權限檢查 Gate
        Gate::define('is-coach', function ($user) {
            // 直接使用 Token 載入的用戶物件的 role 屬性
            return $user && $user->role === 'COACH';
        });

        // 3. Admin OR Coach 權限檢查 Gate (用於廣泛訪問管理面板的權限)
        // 注意：這個 Gate 不會被我們的路由直接使用，但提供業務邏輯。
        Gate::define('is-admin-or-coach', function ($user) {
            return $user && in_array($user->role, ['ADMIN', 'COACH']);
        });
    }
}