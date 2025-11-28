<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Booking;
use App\Models\MembershipCard;
use App\Models\PointLog; // 確保引入
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CheckMinimumCapacity extends Command
{
    protected $signature = 'course:check-min-capacity';
    protected $description = 'Checks courses for minimum capacity requirements and cancels if necessary, issuing refunds.';

    public function handle()
    {
        // 1. 定義檢查視窗：查找明天開始的課程
        $tomorrow = Carbon::tomorrow();
        $startTime = $tomorrow->copy()->startOfDay();
        $endTime = $tomorrow->copy()->endOfDay();

        $this->info("Starting minimum capacity check for courses on {$tomorrow->toDateString()}...");

        // 2. 查找不符合最低開課人數的課程
        $underCapacityCourses = Course::where('status', 'SCHEDULED')
            ->where('start_time', '>=', $startTime)
            ->where('start_time', '<=', $endTime)
            ->whereColumn('current_bookings', '<', 'min_capacity')
            ->get();

        if ($underCapacityCourses->isEmpty()) {
            $this->info('No under-capacity courses found. Exiting.');
            return 0;
        }

        $this->warn("Found {$underCapacityCourses->count()} courses that require cancellation.");

        foreach ($underCapacityCourses as $course) {
            $this->processCourseCancellation($course);
        }

        $this->info('Minimum capacity check completed.');
        return 0;
    }

    private function processCourseCancellation(Course $course)
{
    $courseId = $course->course_id;

    try {
        DB::transaction(function () use ($courseId, $course) {
            
            $refundAmount = $course->required_points;

            // A. 取消課程
            $course->status = 'CANCELLED';
            $course->save();

            $confirmedBookings = Booking::where('course_id', $courseId)
                ->whereRaw('TRIM(status) = ?', ['CONFIRMED']) 
                ->get();

            $totalRefunds = 0;
            foreach ($confirmedBookings as $booking) {
                // C. 原子退款：鎖定 MembershipCard 行並退還點數
                $card = MembershipCard::where('member_id', $booking->member_id)
                    ->lockForUpdate() 
                    ->first();
                
                if ($card) {
                    $card->remaining_points += $refundAmount; 
                    $card->save();
                    $totalRefunds++;
                    
                    // D. 更新預約狀態
                    $booking->status = 'CANCELLED_BY_SYSTEM';
                    $booking->save();

                    // 實作 PointLog 紀錄 (REFUND_MIN_CAPACITY)
                    PointLog::create([
                        'log_id' => Str::uuid(),
                        'membership_id' => $card->card_id,
                        'change_amount' => $refundAmount,
                        'change_type' => 'REFUND_MIN_CAPACITY',
                        'related_id' => $courseId,
                    ]);
                }
            }
            
            // E. 處理所有 WAITING/HOLD 預約 (解鎖點數)
            $waitingBookings = Booking::where('course_id', $courseId)
                                      ->whereIn('status', ['WAITING', 'HOLD'])
                                      ->get();

            foreach ($waitingBookings as $booking) {
                $card = MembershipCard::where('member_id', $booking->member_id)
                                      ->lockForUpdate()
                                      ->first();
                
                if ($card && $card->locked_points >= $refundAmount) {
                    $card->locked_points -= $refundAmount; 
                    $card->remaining_points += $refundAmount; 
                    $card->save();
                    
                    // 實作 PointLog 紀錄 (UNLOCKED_CANCELLATION)
                    PointLog::create([
                        'log_id' => Str::uuid(),
                        'membership_id' => $card->card_id,
                        'change_amount' => $refundAmount,
                        'change_type' => 'UNLOCKED_CANCELLATION',
                        'related_id' => $courseId,
                    ]);
                }
                $booking->status = 'CANCELLED_BY_SYSTEM';
                $booking->save();
            }

            $this->info("Cancellation complete for course {$courseId}. Total confirmed refunded: {$totalRefunds}.");
            
        }, 5); 
        
    } catch (\Exception $e) {
        \Log::error("MinimumCapacity Check Failed for course {$courseId}: " . $e->getMessage());
    }
}
}