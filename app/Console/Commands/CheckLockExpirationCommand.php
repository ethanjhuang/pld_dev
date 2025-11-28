<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\SystemConfig;
use App\Jobs\ReleaseExpiredLockJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log; // 確保引入 Log

class CheckLockExpirationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'booking:check-lock-expiration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks for expired WAITING_LOCKED bookings and releases the points.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Starting CheckLockExpirationCommand...');

        // 1. 獲取系統配置：鎖定超時時間 (例如 30 分鐘)
        $config = SystemConfig::where('key_name', 'WAITING_LOCK_TIMEOUT_MINUTES')->first();
        $timeoutMinutes = $config ? (int)$config->value : 30;

        $expirationTime = Carbon::now()->subMinutes($timeoutMinutes);

        // 2. 查找所有超時的 WAITING_LOCKED 預約
        $expiredBookings = Booking::where('status', 'WAITING_LOCKED')
            // CRITICAL FIX: 必須使用 created_at 進行判斷
            ->where('created_at', '<', $expirationTime) 
            ->get();

        $count = 0;
        
        // 3. 為每個超時預約分派 Job
        foreach ($expiredBookings as $booking) {
            // CRITICAL FIX: 使用明確的 dispatch 語法，並指定 queue 名稱
            dispatch(new ReleaseExpiredLockJob($booking->booking_id))->onQueue('high'); 
            Log::info("Dispatched ReleaseExpiredLockJob for expired booking: {$booking->booking_id}");
            $count++;
        }

        $this->info("Dispatched {$count} ReleaseExpiredLockJob(s) for expired WAITING_LOCKED bookings.");
        Log::info("CheckLockExpirationCommand finished. Dispatched {$count} jobs.");
        return 0;
    }
}