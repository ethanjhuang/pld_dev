<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Booking;
use App\Models\MembershipCard;
use App\Models\PointLog; // 確保 PointLog 存在
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class BookingCancellationService
{
    /**
     * 批量取消課程並退還所有已扣點或已鎖定的點數 (高權限操作)。
     * @param string $courseId 要取消的課程 ID
     * @param string $reason 取消原因 (例如: INSUFFICIENT_CAPACITY)
     */
    public function bulkCancel(string $courseId, string $reason): void
    {
        // 核心邏輯必須在 DB Transaction 內完成
        DB::transaction(function () use ($courseId, $reason) {
            
            $course = Course::lockForUpdate()->find($courseId);
            if (!$course) {
                return; // 課程不存在，直接退出
            }

            // 1. 查找所有有效的預約
            $bookings = Booking::lockForUpdate()
                ->where('course_id', $courseId)
                ->whereNotIn('status', ['CANCELLED', 'ATTENDED', 'NO_SHOW'])
                ->get();
            
            $pointsToRefund = []; // 儲存需要退還的點數總額 (按用戶分組)

            foreach ($bookings as $booking) {
                $refundAmount = 0.00;

                // 2. 獲取並鎖定 MembershipCard
                $card = MembershipCard::where('user_id', $booking->user_id)->lockForUpdate()->first();
                if (!$card) continue;

                // 3. 執行退點/解鎖邏輯
                if ($booking->is_paid && $booking->points_deducted > 0) {
                    // 已扣點預約 (CONFIRMED) - 退點
                    $refundAmount = $booking->points_deducted;
                    $card->remaining_points += $refundAmount;

                } elseif ($booking->status === 'WAITING' && $course->required_points > 0) {
                    // 鎖定點數預約 (WAITING) - 解鎖點
                    $unlockAmount = $course->required_points;
                    
                    // 確保鎖點數足夠扣除
                    if ($card->locked_points >= $unlockAmount) {
                         $card->locked_points -= $unlockAmount;
                         $card->remaining_points += $unlockAmount;
                         $refundAmount = $unlockAmount;
                    }
                }
                
                // 4. 記錄 PointLog (如果有點數變動)
                if ($refundAmount > 0) {
                    PointLog::create([
                        'log_id' => Str::uuid(),
                        'membership_id' => $card->card_id,
                        'change_amount' => $refundAmount,
                        'change_type' => 'SYSTEM_CANCEL_REFUND', 
                        'related_id' => $booking->booking_id,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                }

                // 5. 更新 Booking 狀態
                $booking->status = 'CANCELLED';
                $booking->cancellation_time = Carbon::now();
                $booking->save();
                $card->save();
            }

            // 6. 最終更新 Course 狀態
            $course->current_bookings = 0; // 名額清零
            $course->is_confirmed = true;  // 標記為已處理
            $course->is_active = false;    // 停用課程
            $course->save();
        });
    }
}