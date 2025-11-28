<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking; // 核心數據源
use App\Models\Course; // 新增引入
use App\Models\Coach; // 新增引入
use App\Models\Classroom; // 新增引入
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule; // 新增 Rule 引入 (雖然目前沒用到，但 Admin Controller 常用)

class ReportingController extends Controller
{
    /**
     * Admin API 21: 獲取教練和場域的收入報表
     * 報表計算基於 Booking 狀態為 ATTENDED (已出席)
     */
    public function getRevenueReport(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can access reports.'], 403);
        }

        // 1. 數據驗證與時間範圍設定
        $validated = $request->validate([
            'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
        ]);

        $startDate = Carbon::parse($validated['startDate'])->startOfDay();
        $endDate = Carbon::parse($validated['endDate'])->endOfDay();

        // 2. 獲取 教練收入 報表
        $coachRevenue = $this->getCoachRevenue($startDate, $endDate);

        // 3. 獲取 場域收入 報表
        $classroomRevenue = $this->getClassroomRevenue($startDate, $endDate);

        // 4. 返回結果
        return response()->json([
            'message' => 'Revenue report successfully generated.',
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'coachRevenue' => $coachRevenue,
            'classroomRevenue' => $classroomRevenue,
        ], 200);
    }
    
    /**
     * 輔助方法：計算教練收入
     */
    private function getCoachRevenue(Carbon $startDate, Carbon $endDate)
    {
        // 核心邏輯：JOIN Booking, Course, Coach，並按 Coach 分組求和
        return Booking::join('courses', 'bookings.course_id', '=', 'courses.course_id')
            ->join('coaches', 'courses.coach_id', '=', 'coaches.coach_id')
            // 收入計算標準：僅計算狀態為 ATTENDED (已出席) 的點數扣除
            ->where('bookings.status', 'ATTENDED') 
            // 篩選範圍：課程開始時間
            ->whereBetween('courses.start_time', [$startDate, $endDate])
            // PostgreSQL 要求所有 SELECT 欄位都需在 GROUP BY 中 (或使用聚合函數)
            ->groupBy('coaches.coach_id', 'coaches.name') 
            ->select(
                'coaches.name as coachName',
                DB::raw('SUM(bookings.points_deducted) as totalRevenuePoints'),
                DB::raw('COUNT(bookings.booking_id) as totalStudents')
            )
            ->get()
            ->map(function($item) {
                // 確保輸出為 camelCase
                return [
                    'coachName' => $item->coachName,
                    'totalRevenuePoints' => (float) $item->totalRevenuePoints,
                    'totalStudents' => (int) $item->totalStudents
                ];
            })
            ->toArray();
    }

    /**
     * 輔助方法：計算場域收入
     */
    private function getClassroomRevenue(Carbon $startDate, Carbon $endDate)
    {
        // 核心邏輯：JOIN Booking, Course, Classroom，並按 Classroom 分組求和
        return Booking::join('courses', 'bookings.course_id', '=', 'courses.course_id')
            ->join('classrooms', 'courses.classroom_id', '=', 'classrooms.classroom_id')
            ->where('bookings.status', 'ATTENDED')
            ->whereBetween('courses.start_time', [$startDate, $endDate])
            ->groupBy('classrooms.classroom_id', 'classrooms.name')
            ->select(
                'classrooms.name as classroomName',
                DB::raw('SUM(bookings.points_deducted) as totalRevenuePoints'),
                DB::raw('COUNT(bookings.booking_id) as totalStudents')
            )
            ->get()
            ->map(function($item) {
                // 確保輸出為 camelCase
                return [
                    'classroomName' => $item->classroomName,
                    'totalRevenuePoints' => (float) $item->totalRevenuePoints,
                    'totalStudents' => (int) $item->totalStudents
                ];
            })
            ->toArray();
    }
}