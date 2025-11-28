<?php

namespace App\Jobs;

use App\Models\PointLog;
use App\Models\Course;
use App\Models\Booking;
use App\Models\MembershipCard;
use App\Models\SystemConfig; // 保持引入 SystemConfig
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // <-- 確保 Log 已引入
use Illuminate\Support\Str;

class CheckWaitlistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $courseId;

    /**
     * Create a new job instance.
     *
     * @param string $courseId 課程 UUID
     */
    public function __construct(string $courseId)
    {
        $this->courseId = $courseId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            DB::transaction(function () {
                
                // 1. 查找課程並鎖定
                $course = Course::lockForUpdate()->find($this->courseId);

                if (!$course || $course->current_bookings >= $course->max_capacity) {
                    \Log::info("Waitlist check skipped for course {$this->courseId}: Course full or not found.");
                    return;
                }

                // 2. 查找第一個等待中的預約 (使用 status = 'WAITING')
                $nextBooking = Booking::where('course_id', $this->courseId)
                    ->where('status', 'WAITING') 
                    ->orderBy('waiting_list_rank', 'asc')
                    ->lockForUpdate()
                    ->first();

                if (!$nextBooking) {
                    \Log::info("No waiting bookings found for course {$this->courseId}.");
                    return;
                }

                $memberId = $nextBooking->member_id;
                $neededPoints = $course->required_points; // 假設單一參與者只需 $required_points 點數

                // 3. 查找並鎖定會員點數卡
                $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->first();

                // 4. 點數卡和鎖定點數檢查
                // 檢查 locked_points 是否足夠支付課程 (這是 User B 轉正的硬性門檻)
                if (!$card || $card->locked_points < $neededPoints) {
                    // 如果點數不足，將 Booking 狀態設為失敗，並記錄
                    \Log::warning("Waitlist failed for member {$memberId}: Insufficient locked points ({$card->locked_points}). Needed: {$neededPoints}.");
                    
                    $nextBooking->status = 'WAITLIST_FAIL_POINTS';
                    $nextBooking->save();
                    
                    return;
                }
                
                // 5. 執行轉正：消耗鎖定的點數 (原子性操作)
                $card->locked_points -= $neededPoints; 
                
                // 6. 更新課程名額和預約狀態
                $course->current_bookings += 1;
                $nextBooking->status = 'CONFIRMED';
                $nextBooking->is_paid = true;
                $nextBooking->waiting_list_rank = null;
                
                // 7. 創建 PointLog 紀錄 (CRITICAL FIX: 必須記錄交易審計)
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $card->card_id,
                    'change_amount' => -$neededPoints, // 負數表示點數被消耗
                    'change_type' => 'WAITLIST_CONFIRMED', 
                    'related_id' => $this->courseId, 
                ]);

                // 8. 最終保存
                $card->save();
                $course->save();
                $nextBooking->save();
                
                \Log::info("Waitlist confirmed member {$memberId} for course {$this->courseId}. Locked points consumed.");

            }); // DB::transaction 結束
            
        } catch (\Exception $e) {
            // !!! 關鍵：捕獲所有錯誤並將其寫入日誌 !!!
            \Log::error("CheckWaitlistJob FATAL ERROR for course {$this->courseId}: " . $e->getMessage() . " on line " . $e->getLine());
            
            // 呼叫 fail 確保錯誤被記錄到 failed_jobs 表
            $this->fail($e); 
        }
    }
}