<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course; 
use App\Models\Booking; // 這裡需要引入 Booking Model
use App\Models\MembershipCard; // 這裡需要引入 MembershipCard Model
use App\Models\PointLog; // 這裡需要引入 PointLog Model
use App\Models\Coach; // 必須引入 Coach Model 進行檢查
use App\Models\Classroom; // 必須引入 Classroom Model 進行檢查
use Illuminate\Support\Carbon; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CourseManagementController extends Controller
{
    /**
     * Admin API 17: 創建新課程 (Store)
     */
    public function store(Request $request)
    {
        // 1. 數據驗證
        $validated = $request->validate([
            // CRITICAL FIX: 修正驗證規則，直接檢查 coaches 表格
            'coachId' => ['required', 'uuid', 'exists:coaches,coach_id'], 
            
            'classroomId' => 'required|uuid|exists:classrooms,classroom_id',
            'name' => 'required|string|max:255',
            'startTime' => 'required|date_format:Y-m-d H:i:s', 
            'endTime' => 'required|date_format:Y-m-d H:i:s|after:startTime', 
            'maxCapacity' => 'required|integer|min:1',
            'minCapacity' => 'required|integer|min:1|lte:maxCapacity', 
            'requiredPoints' => 'required|numeric|min:0',
            'minChildLevel' => 'nullable|string',
            // CRITICAL FIX: 新增 target_audience 驗證
            'targetAudience' => 'nullable|in:CHILD,ADULT', 
        ]);
        
        $startTime = Carbon::parse($validated['startTime']);
        $endTime = Carbon::parse($validated['endTime']);
        
        // 檢查最小容量是否超過最大容量 (防止 race condition 導致的驗證失敗)
        if ($validated['minCapacity'] > $validated['maxCapacity']) {
             return response()->json(['message' => 'Minimum capacity cannot be greater than maximum capacity.'], 400);
        }

        // 檢查教練和教室是否處於 ACTIVE 狀態 (防止 inactive 的資源被排課)
        $coach = Coach::find($validated['coachId']);
        $classroom = Classroom::find($validated['classroomId']);

        if (!$coach->is_active || !$classroom->is_active) {
            return response()->json(['message' => 'Cannot create course: Coach or Classroom is inactive.'], 400);
        }

        // 2. 呼叫核心衝突檢查 (新增課程時)
        if ($this->checkResourceConflict($startTime, $endTime, $validated['coachId'], $validated['classroomId'])) {
            return response()->json([
                'message' => 'Resource conflict detected.', 
                'details' => 'The selected coach or classroom is already booked during this time slot.'
            ], 409); // 返回 409 Conflict
        }

        // 3. 執行 DB 寫入 (原子操作)
        try {
            $course = null;
            DB::transaction(function () use ($validated, $startTime, $endTime, &$course) {
                
                $course = Course::create([
                    'course_id' => Str::uuid(),
                    'coach_id' => $validated['coachId'], 
                    'classroom_id' => $validated['classroomId'],
                    'name' => $validated['name'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'max_capacity' => $validated['maxCapacity'],
                    'min_capacity' => $validated['minCapacity'],
                    'required_points' => $validated['requiredPoints'],
                    'min_child_level' => $validated['minChildLevel'] ?? null,
                    // CRITICAL FIX: 寫入 target_audience 欄位
                    'target_audience' => $validated['targetAudience'] ?? 'CHILD', 
                    'is_active' => true, 
                ]);
            });
            
            // 由於 store 成功了，我們返回創建的課程
            return response()->json(['message' => 'Course created successfully.', 'course' => $course], 201);
            
        } catch (\Exception $e) {
            \Log::error("Course creation failed: " . $e->getMessage());
            return response()->json(['message' => 'Course creation failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Admin API A3.3 (R): 查詢課程列表 (附帶篩選和分頁)
     */
    public function index(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can view courses.'], 403);
        }

        // 由於 index 方法通常用於簡單查詢，驗證規則可以簡化
        $validated = $request->validate([
            'search' => 'nullable|string',
            'coachId' => 'nullable|uuid|exists:coaches,coach_id',
            'classroomId' => 'nullable|uuid|exists:classrooms,classroom_id',
            'status' => ['nullable', Rule::in(['PENDING', 'SCHEDULED', 'ACTIVE', 'COMPLETED', 'CANCELLED'])],
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Course::query();

        // 篩選：按課程名稱/描述搜索
        if (isset($validated['search'])) {
            $query->where(function($q) use ($validated) {
                $q->where('name', 'ILIKE', '%' . $validated['search'] . '%')
                  ->orWhere('description', 'ILIKE', '%' . $validated['search'] . '%');
            });
        }
        
        // 篩選：按教練 ID
        if (isset($validated['coachId'])) {
            $query->where('coach_id', $validated['coachId']);
        }

        // 篩選：按教室 ID
        if (isset($validated['classroomId'])) {
            $query->where('classroom_id', $validated['classroomId']);
        }

        // 篩選：按狀態
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        
        $perPage = $validated['perPage'] ?? 20;

        // 確保關聯的教練和教室信息也被載入，減少 N+1 查詢
        $courses = $query->with(['coach', 'classroom'])
                         ->orderByDesc('start_time') // 預設按開始時間倒序排列
                         ->paginate($perPage);

        return response()->json($courses);
    }
    
    /**
     * API 17: Admin 更新課程 (核心原子性邏輯)
     */
    public function update(Request $request, string $courseId)
    {
        // 1. 驗證輸入
        $validated = $request->validate([
            'coachId' => 'nullable|uuid|exists:coaches,coach_id',
            'classroomId' => 'nullable|uuid|exists:classrooms,classroom_id',
            'seriesId' => 'nullable|uuid|exists:course_series,series_id',
            'name' => 'nullable|string',
            'startTime' => 'nullable|date_format:Y-m-d H:i:s', // 修正：確保時間格式正確
            'endTime' => 'nullable|date_format:Y-m-d H:i:s|after:startTime', // 修正：確保時間格式正確
            // CRITICAL FIX: 允許 maxCapacity 為 0 (用於關閉預約並觸發取消)
            'maxCapacity' => 'nullable|integer|min:0', 
            'minCapacity' => 'nullable|integer|min:1', // minCapacity 保持至少為 1
            'requiredPoints' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:SCHEDULED,CANCELLED,COMPLETED,DRAFT',
            'targetAudience' => 'nullable|in:CHILD,ADULT', // <--- 新增更新驗證
        ]);
        
        $course = Course::findOrFail($courseId);
        $updateData = $validated;
        
        // 準備時間/資源檢查所需數據
        $currentStartTime = $course->start_time;
        $currentEndTime = $course->end_time;
        $currentCoachId = $course->coach_id;
        $currentClassroomId = $course->classroom_id;

        // 如果傳入了新時間/教練/教室，則更新檢查用的變數
        $newStartTime = isset($validated['startTime']) ? Carbon::parse($validated['startTime']) : $currentStartTime;
        $newEndTime = isset($validated['endTime']) ? Carbon::parse($validated['endTime']) : $currentEndTime;
        $newCoachId = $validated['coachId'] ?? $currentCoachId;
        $newClassroomId = $validated['classroomId'] ?? $currentClassroomId;
        
        // --- CRITICAL FIX: A3.3 U 資源衝突檢查 (TODO 實作) ---
        // 只有當時間、教練或教室有變動時才需要檢查
        $hasResourceChanged = (isset($validated['startTime']) || isset($validated['endTime']) || isset($validated['coachId']) || isset($validated['classroomId']));

        if ($hasResourceChanged) {
            // 檢查教練/教室狀態
            if (isset($validated['coachId'])) {
                if (!Coach::findOrFail($validated['coachId'])->is_active) {
                    return response()->json(['message' => 'Cannot assign inactive coach.'], 400);
                }
            }
            if (isset($validated['classroomId'])) {
                if (!Classroom::findOrFail($validated['classroomId'])->is_active) {
                    return response()->json(['message' => 'Cannot assign inactive classroom.'], 400);
                }
            }

            // 執行衝突檢查
            if ($this->checkResourceConflict($newStartTime, $newEndTime, $newCoachId, $newClassroomId, $courseId)) {
                 return response()->json([
                    'message' => 'Resource conflict detected.', 
                    'details' => 'The selected resources are already booked during the new time slot.'
                ], 409); // 返回 409 Conflict
            }
        }
        // --- END CRITICAL FIX ---


        try {
            DB::transaction(function () use ($updateData, $course) {
                
                $oldMaxCapacity = $course->max_capacity;
                $newMaxCapacity = $updateData['maxCapacity'] ?? $oldMaxCapacity;
                
                // 檢查名額縮減 (核心業務邏輯)
                if ($newMaxCapacity < $oldMaxCapacity) {
                    // 1. 鎖定課程並檢查名額是否會低於 current_bookings
                    $lockedCourse = Course::lockForUpdate()->find($course->course_id);
                    
                    if ($newMaxCapacity < $lockedCourse->current_bookings) {
                        // 如果新名額少於已確認預約人數，則必須取消超額部分的預約
                        $excessBookingsCount = $lockedCourse->current_bookings - $newMaxCapacity;
                        
                        // 2. 查找並鎖定要取消的 CONFIRMED 預約 (通常是最後預約的)
                        $bookingsToCancel = Booking::where('course_id', $course->course_id)
                            ->where('status', 'CONFIRMED')
                            ->orderBy('created_at', 'desc') // 取消最新預約的用戶
                            ->take($excessBookingsCount)
                            ->lockForUpdate()
                            ->get();

                        foreach ($bookingsToCancel as $booking) {
                            $memberId = $booking->member_id;
                            $refundAmount = $booking->points_deducted;
                            
                            // 3. 原子退款：鎖定 MembershipCard 行並退還點數
                            $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->firstOrFail();
                            $card->remaining_points += $refundAmount;
                            $card->save();
                            
                            // 4. 更新預約和課程計數
                            $booking->status = 'CANCELLED_BY_ADMIN'; // 新狀態：管理員強制取消
                            $booking->cancellation_time = now();
                            $booking->save();
                            
                            $lockedCourse->current_bookings -= 1; // 名額釋放
                            
                            // 5. 實作 PointLog 紀錄
                            PointLog::create([
                                'log_id' => Str::uuid(),
                                'membership_id' => $card->card_id,
                                'change_amount' => $refundAmount,
                                'change_type' => 'REFUND_ADMIN_CANCEL',
                                'related_id' => $course->course_id,
                            ]);
                        }
                    }
                }
                
                // 處理欄位映射
                // 注意：由於 $updateData = $validated;，我們只需要處理映射和名稱不一致的欄位
                
                if (isset($updateData['targetAudience'])) {
                    $updateData['target_audience'] = $updateData['targetAudience'];
                    unset($updateData['targetAudience']);
                }

                if (isset($updateData['maxCapacity'])) {
                    $updateData['max_capacity'] = $updateData['maxCapacity'];
                    unset($updateData['maxCapacity']);
                }

                if (isset($updateData['minCapacity'])) {
                    $updateData['min_capacity'] = $updateData['minCapacity'];
                    unset($updateData['minCapacity']);
                }

                if (isset($updateData['requiredPoints'])) {
                    $updateData['required_points'] = $updateData['requiredPoints'];
                    unset($updateData['requiredPoints']);
                }

                if (isset($updateData['coachId'])) {
                    $updateData['coach_id'] = $updateData['coachId'];
                    unset($updateData['coachId']);
                }

                if (isset($updateData['classroomId'])) {
                    $updateData['classroom_id'] = $updateData['classroomId'];
                    unset($updateData['classroomId']);
                }

                if (isset($updateData['seriesId'])) {
                    $updateData['series_id'] = $updateData['seriesId'];
                    unset($updateData['seriesId']);
                }
                
                // 6. 應用所有更新的欄位
                $course->update($updateData);
                
                // 7. 保存更新後的課程 (如果 LockedCourse 被修改，則保存它)
                if (isset($lockedCourse)) {
                    $lockedCourse->save();
                }
                
            });

        } catch (\Exception $e) {
            // 返回 500 錯誤，並記錄日誌
            \Log::error("Course update failed for ID {$courseId}: " . $e->getMessage());
            return response()->json(['message' => 'Course update failed: ' . $e->getMessage()], 500);
        }

        // 8. 返回成功響應
        return response()->json([
            'message' => 'Course updated successfully.'
        ], 200);
    }

    /**
     * Admin API 17: 刪除課程 (Delete) - 邏輯刪除
     */
    public function delete(string $courseId)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can delete courses.'], 403);
        }

        $course = Course::findOrFail($courseId);
        
        // 核心邏輯：將狀態設為 CANCELLED
        if ($course->status === 'SCHEDULED' || $course->status === 'DRAFT') {
            try {
                DB::transaction(function () use ($course) {
                    // 如果有預約，則需先觸發原子性取消退點 (類似 update 邏輯，但更簡單)
                    if ($course->current_bookings > 0) {
                        // 1. 查找所有 CONFIRMED 預約
                        $bookingsToCancel = Booking::where('course_id', $course->course_id)
                            ->where('status', 'CONFIRMED')
                            ->lockForUpdate()
                            ->get();

                        foreach ($bookingsToCancel as $booking) {
                            $memberId = $booking->member_id;
                            $refundAmount = $booking->points_deducted;
                            
                            // 2. 退款並記錄 Log
                            $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->firstOrFail();
                            $card->remaining_points += $refundAmount;
                            $card->save();
                            
                            PointLog::create([
                                'log_id' => Str::uuid(),
                                'membership_id' => $card->card_id,
                                'change_amount' => $refundAmount,
                                'change_type' => 'REFUND_COURSE_DELETED', // 新 Log 類型
                                'related_id' => $course->course_id,
                            ]);
                            
                            // 3. 更新預約狀態
                            $booking->status = 'CANCELLED_BY_ADMIN';
                            $booking->cancellation_time = now();
                            $booking->save();
                        }
                    }
                    
                    // 4. 更新課程狀態
                    $course->current_bookings = 0;
                    $course->status = 'CANCELLED';
                    $course->save();
                });
            } catch (\Exception $e) {
                \Log::error("Course deletion failed for ID {$courseId}: " . $e->getMessage());
                return response()->json(['message' => 'Course deletion failed: ' . $e->getMessage()], 500);
            }
        } else {
            // 如果課程已經是 COMPLETED 或 CANCELLED，則直接設為 CANCELLED
            $course->status = 'CANCELLED';
            $course->save();
        }

        return response()->json([
            'message' => 'Course successfully cancelled and removed from list.'
        ], 200);
    }
    /**
     * 核心衝突檢查方法 (由 store 和 update 調用)
     * 檢查給定時間段內，教練和教室是否與任何現有課程衝突。
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @param string $coachId
     * @param string $classroomId
     * @param string|null $excludeCourseId (編輯時排除自己)
     * @return bool
     */
    private function checkResourceConflict(
        Carbon $startTime, 
        Carbon $endTime, 
        string $coachId, 
        string $classroomId,
        ?string $excludeCourseId = null
    ): bool {
        // 1. 建立基礎查詢：查找所有時間段重疊的課程
        $query = Course::where(function ($q) use ($startTime, $endTime) {
            // 時間重疊的核心判斷邏輯：新課程開始時間 < 舊課程結束時間 AND 新課程結束時間 > 舊課程開始時間
            $q->where('start_time', '<', $endTime)
              ->where('end_time', '>', $startTime);
        });

        // 2. 排除正在編輯的課程本身 (如果是更新操作)
        if ($excludeCourseId) {
            $query->where('course_id', '!=', $excludeCourseId);
        }
        
        // 3. 結合資源衝突條件 (OR 邏輯：只要任一資源被佔用即算衝突)
        $query->where(function ($q) use ($coachId, $classroomId) {
            // 教練衝突：新的教練 ID 已經在重疊時間內被其他課程佔用
            $q->where('coach_id', $coachId)
            
              // 教室衝突：新的教室 ID 已經在重疊時間內被其他課程佔用
              ->orWhere('classroom_id', $classroomId);
        });

        // 4. 執行查詢：檢查是否存在任何衝突的課程
        return $query->exists();
    }
}