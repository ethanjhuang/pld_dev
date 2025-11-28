<?php

namespace App\Console\Commands;

use App\Models\PointLog;
use App\Models\Course; 
use App\Models\Booking;
use App\Models\MembershipCard; 
use App\Models\SystemConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str; // 確保 Str 已引入
use Illuminate\Support\Facades\Log;

/**
 * 處理課程結束後的批量結算：
 * 1. 將所有 CONFIRMED (未點名) 預約設置為 NO_SHOW。
 * 2. 將所有 WAITING/HOLD 預約的鎖定點數釋放並取消。
 * 3. 將課程狀態設置為 COMPLETED。
 */
class FinalizeAttendanceCommand extends Command
{
    protected $signature = 'booking:finalize-course'; // CRITICAL FIX: 使用更明確的 signature
    protected $description = 'Finalizes all un-finalized bookings for courses that passed the attendance lock window.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Starting FinalizeAttendanceCommand (Course Finalization)...');

        // 1. 獲取點名鎖定時間 (例如 60 分鐘)
        $lockMinutes = (int) SystemConfig::where('key_name', 'ATTENDANCE_LOCK_MINUTES')->value('value') ?? 60; 
        
        // 課程結束時間 + 鎖定時間 = 結算截止時間
        $lockTime = Carbon::now()->subMinutes($lockMinutes); 

        $this->info("Starting course finalization for courses ended {$lockMinutes} minutes ago.");

        // 2. 查找需要結算的課程 (課程結束時間 < 鎖定時間)
        $expiredCourses = Course::where('end_time', '<', $lockTime)
            // 只查找尚未完成的課程 (避免重複結算已 COMPLETED 的課程)
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED']) 
            ->get();
        
        if ($expiredCourses->isEmpty()) {
            $this->info('No courses found that require finalization. Exiting.');
            return Command::SUCCESS;
        }
        
        $this->warn("Found {$expiredCourses->count()} courses to finalize.");

        foreach ($expiredCourses as $course) {
            $this->processCourseFinalization($course); 
        }

        $this->info('Attendance finalization completed.');
        return Command::SUCCESS;
    }

    /**
     * 對單個課程進行結算處理
     */
    private function processCourseFinalization(Course $course)
    {
        $courseId = $course->course_id;
        $unlockedCount = 0;
        $noShowCount = 0;

        // 由於整個課程的結算是一個批次操作，我們在外部包一個大事務
        try {
            DB::transaction(function () use ($courseId, $course, &$unlockedCount, &$noShowCount) {
                
                // 1. 查找所有需要結算的 Booking (CONFIRMED, WAITING, HOLD, WAITING_LOCKED)
                $unfinalizedBookings = Booking::where('course_id', $courseId)
                                             ->whereIn('status', ['CONFIRMED', 'WAITING', 'WAITING_LOCKED'])
                                             ->lockForUpdate() // CRITICAL: 鎖定所有 Booking
                                             ->get();

                $refundAmount = $course->required_points;
                
                foreach ($unfinalizedBookings as $booking) {
                    $memberId = $booking->member_id;

                    if ($booking->status === 'CONFIRMED') {
                        // A. CONFIRMED 狀態：點數已扣，設為 NO_SHOW
                        $booking->status = 'NO_SHOW';
                        $noShowCount++;
                        
                        // 紀錄 NO_SHOW Log (change_amount=0, 因為不涉及點數變動)
                        $card = MembershipCard::where('member_id', $memberId)->first(); // 只讀取，不需要鎖定
                        if ($card) {
                            PointLog::create([
                                'log_id' => Str::uuid(),
                                'membership_id' => $card->card_id,
                                'change_amount' => 0.00, 
                                'change_type' => 'NO_SHOW',
                                'related_id' => $booking->booking_id,
                            ]);
                        }
                        
                    } else { 
                        // B. WAITING/WAITING_LOCKED 狀態：解鎖點數並取消
                        
                        // CRITICAL: 在操作點數前，必須鎖定點數卡
                        $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->first();
                        
                        // 檢查鎖定點數是否足夠退還
                        if ($card && $booking->points_deducted > 0 && $card->locked_points >= $booking->points_deducted) {
                            $unlockAmount = $booking->points_deducted;

                            $card->locked_points -= $unlockAmount;
                            $card->remaining_points += $unlockAmount; 
                            $card->save(); // 保存點數卡變更

                            // 紀錄 UNLOCKED_FINALIZED Log
                            PointLog::create([
                                'log_id' => Str::uuid(),
                                'membership_id' => $card->card_id,
                                'change_amount' => $unlockAmount,
                                'change_type' => 'UNLOCKED_FINALIZED',
                                'related_id' => $booking->booking_id,
                            ]);
                            $unlockedCount++;
                        }
                        $booking->status = 'CANCELLED_BY_SYSTEM';
                    }
                    $booking->save();
                }

                // C. 更新課程狀態為 COMPLETED
                $course->status = 'COMPLETED';
                $course->save();

                $this->info("Finalized course {$courseId}. No-Shows: {$noShowCount}, Waiting List refunded: {$unlockedCount}.");
                Log::info("Course Finalization for {$courseId} completed.");

            }); // DB::transaction 結束
            
        } catch (\Exception $e) {
            \Log::error("FinalizeAttendance Job Failed for course {$courseId}: " . $e->getMessage() . ' on line ' . $e->getLine());
            $this->error("Failed to finalize course {$courseId}. Check logs.");
        }
    }
}