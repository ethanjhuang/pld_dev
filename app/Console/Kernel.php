<?php

namespace App\Console;

use App\Console\Commands\CheckMinCapacityCommand; // 修正：使用完整的 Command 名稱
use App\Console\Commands\FinalizeAttendanceCommand; // 修正：使用完整的 Command 名稱
use App\Console\Commands\CheckLockExpirationCommand; // V1.1 鎖定檢查 Command
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        // 註冊 Command
        CheckMinCapacityCommand::class,
        FinalizeAttendanceCommand::class, // <-- 修正：註冊完整的 Command 名稱
        CheckLockExpirationCommand::class, // <-- 註冊 V1.1 鎖定檢查 Command
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 定義排程任務 (V1.1 藍圖)
        
        // A4.7: 點數鎖定超時檢查 (每 5 分鐘)
        $schedule->command('booking:check-lock-expiration')->everyFiveMinutes();
        
        // A4.8: 課程結算 (每小時)
        $schedule->command('booking:finalize-course')->hourly(); 

        // 最低開課人數檢查 (每晚 22:00)
        $schedule->command('course:check-min-capacity')->dailyAt('22:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}