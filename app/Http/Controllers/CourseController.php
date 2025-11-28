<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Booking; 
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB; 

class CourseController extends Controller
{
    /**
     * API 6/12: 課程列表查詢 (會員介面)
     * 查詢指定日期區間、特定條件下，用戶可預約的課程。
     */
    public function index(Request $request)
    {
        // 1. 數據驗證
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d', // 查找單一日期
            'classroomId' => 'nullable|uuid',
            'coachId' => 'nullable|uuid',
        ]);
        
        // 取得當前登入用戶 ID
        // 確保 auth() 檢查已在路由中間件中完成
        $userId = auth()->user()->member_id; 

        $queryDate = Carbon::parse($validated['date']);
        
        // CRITICAL FIX: 使用 StartOfDay 和 StartOfNextDay 來確保時區魯棒性
        $startDate = $queryDate->startOfDay()->toDateTimeString();
        // 結束日期為隔天的 00:00:00，確保包含當天 23:59:59 的所有紀錄
        $endDate = $queryDate->addDay()->startOfDay()->toDateTimeString(); 

        // 2. 建立查詢：預載入 Coach 和 Classroom
        $query = Course::with(['coach', 'classroom'])
            // 核心篩選：只顯示已排程的課程 (SCHEDULED)
            ->where('status', 'SCHEDULED')
            // 使用 >='START_DAY 00:00:00' AND < 'NEXT_DAY 00:00:00'
            ->where('start_time', '>=', $startDate)
            ->where('start_time', '<', $endDate); 

        // 3. 篩選邏輯
        if (!empty($validated['classroomId'])) {
            $query->where('classroom_id', $validated['classroomId']);
        }
        if (!empty($validated['coachId'])) {
            $query->where('coach_id', $validated['coachId']);
        }

        $courses = $query->orderBy('start_time')->get();

        // 4. 數據轉換和計算
        return response()->json([
            'message' => 'Courses retrieved successfully.',
            'courses' => $courses->map(function ($course) use ($userId) {
                $availableSpots = $course->max_capacity - $course->current_bookings;
                
                // 檢查當前用戶的預約狀態 (用於 UI 顯示)
                $userBooking = Booking::where('course_id', $course->course_id)
                    ->where('member_id', $userId)
                    // 只檢查尚未完成或取消的有效預約
                    ->whereNotIn('status', ['CANCELLED', 'CANCELLED_BY_SYSTEM', 'ATTENDED', 'NO_SHOW']) 
                    ->first();
                
                // 核心修正：使用 optional() 函數進行安全存取
                $coachName = optional($course->coach)->name ?? 'N/A';
                $classroomName = optional($course->classroom)->name ?? 'N/A';
                
                // N+1 Warning: 這裡為每個課程執行了數據庫查詢
                $averageAge = $this->calculateAverageAge($course->course_id); 
                
                return [
                    'courseId' => $course->course_id,
                    'name' => $course->name,
                    'startTime' => $course->start_time->format('Y-m-d H:i:s'),
                    'endTime' => $course->end_time->format('Y-m-d H:i:s'),
                    'coachName' => $coachName, 
                    'classroomName' => $classroomName, 
                    'requiredPoints' => (float) $course->required_points,
                    'availableSpots' => max(0, $availableSpots),
                    'isFull' => $availableSpots <= 0,
                    
                    // 新增：當前用戶的預約狀態
                    'userStatus' => $userBooking ? $userBooking->status : 'NONE',
                    'userBookingId' => $userBooking ? $userBooking->booking_id : null,
                    
                    'averageAge' => $averageAge, 
                ];
            })
        ], 200);
    }
    
    /**
     * API 16: 查詢單一課程詳情 (Public)
     * 路由: GET /api/course/{courseId}
     * 【補齊】實作 show 方法
     */
    public function show(string $courseId)
    {
        $course = Course::with(['coach', 'classroom', 'series'])
            ->findOrFail($courseId);

        return response()->json($course);
    }

    /**
     * API 17: 教練查詢自己負責的課程列表
     * 路由: GET /api/coach/courses
     * 【補齊】實作 getCoachCourses 方法
     */
    public function getCoachCourses(Request $request)
    {
        // 權限檢查已在路由中間件中處理 (can:is-coach)
        $authenticatedUser = auth()->user();
        
        // 1. 確保 Member 帳號有關聯的 Coach Profile
        $coach = $authenticatedUser->coach;

        if (!$coach) {
            return response()->json(['message' => 'Forbidden: Coach profile not linked or not found.'], 403);
        }

        // 2. 查詢該教練 ID 負責的所有課程，並預載入相關資料
        $courses = Course::where('coach_id', $coach->coach_id)
                         ->with(['classroom']) 
                         ->orderBy('start_time', 'asc')
                         ->get();

        return response()->json($courses);
    }
    
    /**
     * 輔助方法：計算已報名兒童的平均年齡 (U-3 需求)
     * NOTE: 此方法會導致 N+1 查詢問題，性能開銷較大。
     */
    private function calculateAverageAge(string $courseId): ?float
    {
        // 查找所有已確認且有 child_id 的 Booking
        $bookings = Booking::where('course_id', $courseId)
            ->where('status', 'CONFIRMED')
            ->whereNotNull('child_id')
            ->with('child') 
            ->get();
        
        $totalAgeMonths = 0;
        $validParticipants = 0;

        foreach ($bookings as $booking) {
            if ($booking->child && $booking->child->birth_date) {
                $birthDate = Carbon::parse($booking->child->birth_date);
                $ageInMonths = $birthDate->diffInMonths(Carbon::now());
                $totalAgeMonths += $ageInMonths;
                $validParticipants++;
            }
        }

        if ($validParticipants === 0) {
            return null;
        }

        // 轉換為年並四捨五入到一位小數
        $averageAge = ($totalAgeMonths / $validParticipants) / 12;
        
        return round($averageAge, 1);
    }
}