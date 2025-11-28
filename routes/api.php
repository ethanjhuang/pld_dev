<?php

use Illuminate\Support\Facades\Route;

// --- 引入所有控制器 ---
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\MembershipController; 
use App\Http\Controllers\CourseController; 
use App\Http\Controllers\Admin\CoachController;
use App\Http\Controllers\Admin\ClassroomController;
use App\Http\Controllers\Admin\CourseManagementController;
use App\Http\Controllers\Coach\AttendanceController; 
use App\Http\Controllers\Admin\ReportingController;
use App\Http\Controllers\Admin\AdminPointController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('line/login', [AuthController::class, 'lineLogin']);    // API 1: 登入檢查
    Route::post('register', [AuthController::class, 'register']);        // API 2: 註冊
});


/*
|--------------------------------------------------------------------------
| Authenticated Client Routes (核心交易與會員功能)
|--------------------------------------------------------------------------
*/
// --- Public/Member Routes (需要認證) ---
Route::middleware(['auth:sanctum'])->group(function () {
    
    // 課程查詢
    Route::get('course/list', [CourseController::class, 'listCourses']);            // API 6: 課程列表查詢
    
    // 預約與取消 (新增 booking 前綴群組)
    Route::prefix('booking')->group(function () { 
        Route::post('create', [BookingController::class, 'createBooking']);      // API 7: 預約/鎖點核心
        Route::post('cancel/{bookingId}', [BookingController::class, 'cancelBooking']); // API 8: 取消預約 
    });

    // 點數與轉讓
    Route::prefix('membership')->group(function () {
        Route::get('log', [MembershipController::class, 'getPointLog']);                // API 11: 點數異動紀錄
        Route::post('transfer/initiate', [MembershipController::class, 'initiateTransfer']); // API 13: 啟動轉讓 (鎖點)
        Route::post('transfer/execute', [MembershipController::class, 'executeTransfer']);    // API 14: 最終執行轉出
        Route::post('transfer/cancel', [MembershipController::class, 'cancelTransfer']);     // API 15: 手動取消鎖定
    });

    });


/*
|--------------------------------------------------------------------------
| Admin & Coach Routes (管理權限)
|--------------------------------------------------------------------------
*/

// --- 1. ADMIN 專用路由群組 ---
Route::middleware(['auth:sanctum', 'can:is-admin'])->prefix('admin')->group(function () {
    
    // V1.3 A3.1: 教練管理 (CRUD)
    Route::get('coach', [CoachController::class, 'index']);           // <-- 新增: R (查詢列表)
    Route::post('coach', [CoachController::class, 'store']);         // C (創建) - 已存在
    Route::put('coach/{coachId}', [CoachController::class, 'update']);   // <-- 新增: U (更新)
    Route::delete('coach/{coachId}', [CoachController::class, 'destroy']); // <-- 新增: D (刪除)
    
    // V1.3 A3.2: 教室管理 (CRUD)
    Route::get('classroom', [ClassroomController::class, 'index']);           // <-- 新增: R (查詢列表)
    Route::post('classroom', [ClassroomController::class, 'store']);         // C (創建) - 已存在
    Route::put('classroom/{classroomId}', [ClassroomController::class, 'update']);   // <-- 新增: U (更新)
    Route::delete('classroom/{classroomId}', [ClassroomController::class, 'destroy']); // <-- 新增: D (刪除)

    // 課程管理 (Course Management) - API 17
    Route::get('course', [CourseManagementController::class, 'index']);      // <-- 新增: R (查詢列表)
    Route::post('course', [CourseManagementController::class, 'store']);     // C (創建) - 已存在
    Route::put('course/{courseId}', [CourseManagementController::class, 'update']); // U (更新) - 已存在
    Route::delete('course/{courseId}', [CourseManagementController::class, 'delete']); // D (刪除) - 已存在
    
    // 報表系統 (Reporting) - API 21
    Route::get('reports/revenue', [ReportingController::class, 'getRevenueReport']); 
    
    // V1.2 A5.1: Admin 手動調整點數 (點數進出)
    Route::post('adjust-points', [AdminPointController::class, 'adjustPoints']);
    
    // V1.2 A5.2: Admin 點數 Log 查詢 
    Route::get('point-logs', [AdminPointController::class, 'getAdminPointLogs']); 
    
    // V1.2 A5.3: Admin 會員卡屬性管理 (創建/效期/強制修正)
    Route::post('membership-card', [AdminPointController::class, 'manageCard']);
    
    // V1.2 A5.3 (NEW): Admin 點數包購買/續費 (點數疊加和金額累加)
    Route::post('adjust-card-total', [AdminPointController::class, 'adjustCardTotalPoints']); 

});


// --- 2. COACH 專用路由群組 ---
Route::middleware(['auth:sanctum', 'can:is-coach'])->prefix('coach')->group(function () {
    
    // 點名狀態更新 - API 18 (批次模式)
    // CRITICAL FIX: 雖然 Controller 在 Admin 目錄，但由於上面的 use 已經修正，這裡可以直接引用
    Route::post('course/{courseId}/attendance', [AttendanceController::class, 'updateStatus']);
    
});