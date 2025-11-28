<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\SystemConfig;
use App\Services\BookingCancellationService; // 確保引入這個 Service
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // 用於記錄錯誤
use Illuminate\Support\Facades\Queue; // 用於發送通知

class CheckMinCapacity extends Command
{
    protected $signature = 'booking:check-min-capacity';
    protected $description = 'Checks tomorrow\'s courses for minimum capacity and cancels if necessary.';

    protected $bookingCancellationService;

    // 透過 Constructor Injection 取得 Service
    public function __construct(BookingCancellationService $bookingCancellationService)
    {
        parent::__construct();
        $this->bookingCancellationService = $bookingCancellationService; 
    }

    public function handle()
    {
        // 1. 讀取配置時間
        $checkHour = (int) SystemConfig::where('key_name', 'MIN_CAPACITY_CHECK_HOUR')->value('value');
        $checkMinute = (int) SystemConfig::where('key_name', 'MIN_CAPACITY_CHECK_MINUTE')->value('value');
        
        // 判斷是否為設定的運行時間
        if (Carbon::now()->hour !== $checkHour || Carbon::now()->minute !== $checkMinute) {
            return Command::SUCCESS; 
        }

        $tomorrowStart = Carbon::tomorrow()->startOfDay();
        $tomorrowEnd = Carbon::tomorrow()->endOfDay();
        
        // 2. 查找所有明日待確認的課程 (is_confirmed = false)
        // 必須鎖定資源，確保在檢查時沒有新的預約進來
        $coursesToConfirm = Course::where('is_confirmed', false)
            ->whereBetween('start_time', [$tomorrowStart, $tomorrowEnd])
            ->get();

        $this->info("Found {$coursesToConfirm->count()} courses for tomorrow's final check.");

        foreach ($coursesToConfirm as $course) {
            try {
                // 鎖定當前課程資源
                DB::transaction(function () use ($course) {
                    $lockedCourse = Course::lockForUpdate()->find($course->course_id);

                    if ($lockedCourse->current_bookings < $lockedCourse->min_capacity) {
                        // 3. 人數不足 - 執行取消、退點、通知 (原子操作)
                        
                        // 調用 Service 批量取消所有預約並退點
                        $this->bookingCancellationService->bulkCancel($lockedCourse->course_id, 'INSUFFICIENT_CAPACITY');
                        
                        // 更新課程狀態
                        $lockedCourse->is_confirmed = true; // 標記為已處理
                        $lockedCourse->is_active = false; // 設為非活動狀態
                        $lockedCourse->save();
                        
                        $this->warn("Course {$lockedCourse->name} cancelled due to low capacity ({$lockedCourse->current_bookings}/{$lockedCourse->min_capacity}).");
                        
                        // TODO: Queue::push(new NotifyParentsOfCancellation($lockedCourse->course_id)); // 發送 LINE 通知
                        
                    } else {
                        // 4. 人數達標 - 確認開課並通知
                        $lockedCourse->is_confirmed = true;
                        $lockedCourse->save();
                        
                        $this->info("Course {$lockedCourse->name} confirmed.");
                        
                        // TODO: Queue::push(new NotifyParentsOfConfirmation($lockedCourse->course_id)); // 發送 LINE 通知
                    }
                });
            } catch (\Exception $e) {
                // 記錄錯誤，避免一個課程的失敗導致所有課程檢查中斷
                Log::error("MinCapacity Check Failed for Course {$course->course_id}: " . $e->getMessage());
                $this->error("Failed to process course {$course->id}. Check logs.");
            }
        }

        return Command::SUCCESS;
    }
}