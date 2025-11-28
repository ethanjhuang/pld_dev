<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB; // CRITICAL FIX: 引入 DB Facade
// 由於我們使用 DB::table，可以移除 use App\Models\SystemConfig;
// use App\Models\SystemConfig; 

class SystemConfigSeeder extends Seeder
{
    /**
     * Run the database seeders.
     */
    public function run(): void
    {
        // 確保 system_configs 表有必要的業務參數
        $configs = [
            [
                'key_name' => 'TRANSFER_LOCK_HOURS',
                'value' => '2', // 點數轉讓鎖定時間：2 小時
                'description' => '轉移點數的鎖定時間。'
            ],
            [
                'key_name' => 'CANCELLATION_WINDOW_HOURS',
                'value' => '24', // 課程取消截止時間：24 小時
                'description' => '免費取消門檻：開課前多少小時。'
            ],
            [
                'key_name' => 'HOLD_TIME_MINUTES',
                'value' => '180', // 點數保留時限：點數不足的後補者，名額保留 180 分鐘
                'description' => '點數保留時限：點數不足的後補者，名額保留多少分鐘。'
            ],
            [
                'key_name' => 'MIN_CAPACITY_CHECK_HOUR',
                'value' => '22', // 最低開課人數檢查時間：前一天的小時數。
                'description' => '最低開課人數檢查時間：前一天的小時數。'
            ],
            [
                'key_name' => 'MIN_CAPACITY_CHECK_MINUTE',
                'value' => '00', // 最低開課人數檢查時間：分鐘數。
                'description' => '最低開課人數檢查時間：分鐘數。'
            ],
            [
                'key_name' => 'ATTENDANCE_LOCK_MINUTES',
                'value' => '60', // 點名鎖定時限：課程結束後 60 分鐘強制結算 NO_SHOW
                'description' => '點名鎖定時限：課程結束後多少分鐘，未標記狀態的 Booking 強制結算為 NO_SHOW。'
            ],
			[
                'key_name' => 'COURSE_CHECK_IN_MINUTES',
                'value' => '30', // 開放課前多早以前可以開始點名，系統預設是15分鐘。
                'description' => '開放課前多早以前可以開始點名，系統預設是15分鐘。'
            ],
        ];

        foreach ($configs as $config) {
            DB::table('system_configs')->updateOrInsert(
                // 檢查是否已存在
                ['key_name' => $config['key_name']],
                // 寫入/更新數據
                $config
            );
        }
    }
}