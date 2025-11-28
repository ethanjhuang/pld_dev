<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards (關鍵修正區塊)
    |--------------------------------------------------------------------------
    |
    | 我們在此處配置 'sanctum' Guard，並將其連結到 'members' provider。
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        // 核心修正 1: 新增 Sanctum Guard
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'members', // <--- 確保指向 'members' provider
            'hash' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers (關鍵修正區塊)
    |--------------------------------------------------------------------------
    |
    | 我們在此定義 'members' provider，指向我們的 App\Models\Member 模型。
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ],

        // 核心修正 2: 新增 members provider
        'members' => [ 
            'driver' => 'eloquent',
            'model' => App\Models\Member::class, // <--- 確保使用 Member Model 處理 API 認證
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];