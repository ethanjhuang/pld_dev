<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\Booking; 
use App\Models\Course; 
use App\Models\SystemConfig; 
use App\Models\Coach; // 引入 Coach Model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log; // <-- 引入 Log Facade

class AttendanceController extends Controller
{
    /**
     * Coach API 18: 教練批次點名 (更新多筆 Booking 的出席狀態)。
     * 路由參數為 {courseId}
     */
    public function updateStatus(Request $request, string $courseId)
    {
        $authenticatedUser = auth()->user();

        $validated = $request->validate([
            // 批次更新陣列驗證
            'updates' => 'required|array|min:1',
            'updates.*.bookingId' => 'required|uuid|exists:bookings,booking_id',
            'updates.*.status' => ['required', Rule::in(['ATTENDED', 'NO_SHOW'])],
        ]);

        $updates = $validated['updates'];
        $results = [];

        try {
            DB::transaction(function () use ($courseId, $updates, $authenticatedUser, &$results) {
                
                $course = Course::lockForUpdate()->findOrFail($courseId);

                // A. 權限檢查：管理員不得使用點名功能
                if ($authenticatedUser->role === 'ADMIN') {
                    throw new \Exception("Access Denied: Admin role cannot perform attendance actions.");
                }

                // B. 【點名歸屬檢查】教練只能點名自己的課
                if ($authenticatedUser->role === 'COACH') {
                    // 根據 Member ID 找到對應的 Coach ID
                    $coachRecord = Coach::where('member_id', $authenticatedUser->member_id)->first();
                    
                    // 課程的 coach_id 必須匹配當前認證教練的 coach_id
                    if (!$coachRecord || $course->coach_id !== $coachRecord->coach_id) {
                         throw new \Exception("Unauthorized: You can only take attendance for your own class.");
                    }
                }

                // C. 狀態與時間檢查 (課程點名窗口：開始時間前 X 分鐘)
                $configCheckIn = SystemConfig::where('key_name', 'COURSE_CHECK_IN_MINUTES')->first();
                $checkInMinutes = $configCheckIn ? (int)$configCheckIn->value : 15; // 預設 15 分鐘
                
                $checkInStart = Carbon::parse($course->start_time)->subMinutes($checkInMinutes);
                
                // --- 診斷日誌：列印伺服器時間和檢查邊界 ---
                Log::warning('*** ATTENDANCE CHECK DIAGNOSIS ***');
                Log::warning('Server Time (Carbon::now): ' . Carbon::now()->toDateTimeString());
                Log::warning('Check-in Starts (Boundary C): ' . $checkInStart->toDateTimeString());
                Log::warning('Check-in Deadline (Boundary D): ' . Carbon::parse($course->end_time)->addMinutes(60)->toDateTimeString());
                Log::warning('*** END DIAGNOSIS ***');
                // --- 診斷日誌結束 ---

                // 點名窗口開啟檢查 (C)
                if (Carbon::now()->lessThan($checkInStart)) {
                    throw new \Exception("Attendance window not yet open. Check-in starts {$checkInMinutes} minutes before the course.");
                }
                
                // D. 點名鎖定時間檢查 (讀取 SystemConfig 配置)
                $configLock = SystemConfig::where('key_name', 'ATTENDANCE_LOCK_MINUTES')->first();
                $lockMinutes = $configLock ? (int)$configLock->value : 60;
                $lockDeadline = Carbon::parse($course->end_time)->addMinutes($lockMinutes);

                // 檢查點名鎖定時間是否已過
                if (Carbon::now()->greaterThan($lockDeadline)) {
                    throw new \Exception("Attendance window closed. Cannot modify status.");
                }

                // 批次處理所有預約
                foreach ($updates as $update) {
                    $bookingId = $update['bookingId'];
                    $newStatus = $update['status'];
                    // ... (省略核心更新邏輯，保持不變)
                    
                    $booking = Booking::where('booking_id', $bookingId)
                                      ->where('course_id', $courseId) 
                                      ->lockForUpdate()
                                      ->first();
                    
                    if (!$booking) {
                        $results[] = ['bookingId' => $bookingId, 'status' => 'FAILED', 'message' => 'Booking does not exist or does not belong to this course.'];
                        continue;
                    }

                    if ($booking->status !== 'CONFIRMED') {
                        $results[] = ['bookingId' => $bookingId, 'status' => 'FAILED', 'message' => "Cannot change status from {$booking->status}. Only CONFIRMED bookings can be updated."];
                        continue;
                    }

                    $booking->status = $newStatus;
                    $booking->attendance_time = Carbon::now();
                    $booking->save();
                    
                    $results[] = ['bookingId' => $bookingId, 'status' => $newStatus, 'message' => 'Success'];
                }
                
            });
        } catch (\Exception $e) {
            $statusCode = 400;
            if (Str::contains($e->getMessage(), 'Unauthorized') || Str::contains($e->getMessage(), 'Admin role')) {
                 $statusCode = 403;
            } 

            \Log::error("Attendance update failed for course {$courseId}: " . $e->getMessage());
            return response()->json(['message' => 'Attendance update failed: ' . $e->getMessage()], $statusCode);
        }

        return response()->json([
            'message' => 'Attendance status updated successfully in batch.',
            'results' => $results,
        ], 200);
    }
}