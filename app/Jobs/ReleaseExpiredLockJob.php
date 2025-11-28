<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\MembershipCard;
use App\Models\PointLog;
use App\Models\SystemConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 處理因用戶未在指定時間內確認或儲值而過期的 WAITING_LOCKED 預約。
 * 目的：將鎖定的點數退還給會員卡。
 */
class ReleaseExpiredLockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $bookingId;

    public function __construct(string $bookingId)
    {
        $this->bookingId = $bookingId;
    }

    public function handle(): void
    {
        try {
            DB::transaction(function () {
                
                // 1. 查找並鎖定 Booking
                $booking = Booking::lockForUpdate()->find($this->bookingId);
                
                // 檢查狀態必須是 WAITING_LOCKED
                if (!$booking || $booking->status !== 'WAITING_LOCKED') {
                    Log::info("ReleaseExpiredLockJob skipped: Booking {$this->bookingId} status is not WAITING_LOCKED.");
                    return;
                }
                
                $lockAmount = $booking->points_deducted;
                $memberId = $booking->member_id;
                
                if ($lockAmount <= 0) {
                     $booking->status = 'CANCELLED_BY_SYSTEM';
                     $booking->save();
                     return;
                }

                // 2. 鎖定會員卡
                $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->first();
                
                if (!$card) {
                    throw new \Exception("Membership card not found for member {$memberId}.");
                }

                // 3. 執行點數返還 (從 locked_points 退回 remaining_points)
                if ($card->locked_points < $lockAmount) {
                     throw new \Exception("Data inconsistency: Locked points less than lock amount for member {$memberId}.");
                }
                
                $card->locked_points -= $lockAmount; 
                $card->remaining_points += $lockAmount; 
                
                $card->save();

                // 4. 更新 Booking 狀態
                $booking->status = 'CANCELLED_BY_SYSTEM';
                $booking->cancellation_time = Carbon::now();
                $booking->save();
                
                // 5. 記錄點數日誌
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $card->card_id,
                    'change_amount' => $lockAmount, // 正數表示增加
                    'change_type' => 'LOCKED_TIMEOUT_RELEASE', 
                    'related_id' => $this->bookingId, 
                ]);

                Log::info("Locked points {$lockAmount} released for booking {$this->bookingId} (Expired Timeout).");

            });
        } catch (\Exception $e) {
            Log::error("ReleaseExpiredLockJob failed for booking {$this->bookingId}: " . $e->getMessage());
            $this->fail($e); 
        }
    }
}