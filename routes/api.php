<?php

use Illuminate\Support\Facades\Route;

// --- 引入所有控制器 ---
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\MembershipController; 
use App\Http\Controllers\CourseController; 
use App\Http\Controllers\CampBookingController;

use App\Http\Controllers\Admin\CoachController;
use App\Http\Controllers\Admin\ClassroomController;
use App\Http\Controllers\Admin\CourseManagementController;
use App\Http\Controllers\Admin\AdminJobController;
use App\Http\Controllers\Admin\ReportingController;
use App\Http\Controllers\Admin\AdminPointController;

use App\Http\Controllers\Coach\AttendanceController; 

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('line/login', [AuthController::class, 'lineLogin']);    // API 1: 登入檢查
    Route::post('register', [AuthController::class, 'register']);        // API 2: 註冊
});

// 【新增】API 16: 查詢單一課程詳情 (Public)
Route::get('/course/{courseId}', [CourseController::class, 'show']); // API 16
Route::get('/camp/list', [CampController::class, 'getPublicList']); // API 22

// API 23.2: 金流回調 (通常是 Public 路由)
Route::post('/camp/booking/confirm', [CampBookingController::class, 'confirmPayment']); 

/*
|--------------------------------------------------------------------------
| Authenticated Client Routes (核心交易與會員功能)
|--------------------------------------------------------------------------
*/
// --- Public/Member Routes (需要認證) ---
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Auth 相關 (Logout)
    Route::post('auth/logout', [AuthController::class, 'logout']); // API 3: 登出
    
    // 課程查詢
    Route::get('course/list', [CourseController::class, 'index']); // API 6: 課程列表查詢

    // 會員資料管理 (API 4 & 5)
    Route::prefix('member')->group(function () {
        Route::get('profile', [MembershipController::class, 'getProfile']); // API 4: 查詢個人資料
        Route::put('profile', [MembershipController::class, 'updateProfile']); // API 5: 更新個人資料
    });
    
    // 預約與取消 (新增 booking 前綴群組)
    Route::prefix('booking')->group(function () { 
        Route::post('create', [BookingController::class, 'createBooking']);      // API 7: 預約/鎖點核心
        Route::post('cancel/{bookingId}', [BookingController::class, 'cancelBooking']); // API 8: 取消預約
        Route::get('history', [BookingController::class, 'getBookingHistory']); // API 9: 查詢預約歷史
    });

    // 點數與轉讓
    Route::prefix('membership')->group(function () {
        Route::get('balance', [MembershipController::class, 'getBalance']); // API 10: 查詢點數餘額 
        Route::get('log', [MembershipController::class, 'getPointLog']);    // API 11: 點數異動紀錄
        Route::post('transfer/initiate', [MembershipController::class, 'initiateTransfer']); // API 13: 啟動轉讓 (鎖點)
        Route::post('transfer/execute', [MembershipController::class, 'executeTransfer']);    // API 14: 最終執行轉出
        Route::post('transfer/cancel', [MembershipController::class, 'cancelTransfer']);     // API 15: 手動取消鎖定
    });

    // 營隊預約核心 (Camp Booking) 【新增】
    Route::prefix('camp/booking')->group(function () {
        Route::post('initiate', [CampBookingController::class, 'initiateBooking']); // API 23.1: 預鎖名額，發起金流
        Route::post('cancel/{bookingId}', [CampBookingController::class, 'cancelBooking']); // API 24: 營隊取消與退款
    });
});


/*
|--------------------------------------------------------------------------
| Admin & Coach Routes (管理權限)
|--------------------------------------------------------------------------
*/

// --- 1. ADMIN 專用路由群組 ---
Route::middleware(['auth:sanctum', 'can:is-admin'])->prefix('admin')->group(function () {
    
    // V1.3 A3.1: 教練管理 (CRUD) - 替換為 apiResource
    Route::apiResource('coach', CoachController::class)->except(['show']); // 涵蓋 index, store, update, destroy
    
    // V1.3 A3.2: 教室管理 (CRUD) - 替換為 apiResource
    Route::apiResource('classroom', ClassroomController::class)->except(['show']); // 涵蓋 index, store, update, destroy

    // 課程管理 (Course Management) - API 17 (應為 A3.3) - 替換為 apiResource
    Route::apiResource('course', CourseManagementController::class)->except(['show']); // 涵蓋 index, store, update, destroy
    
    // 營隊管理
    Route::apiResource('camp', CampController::class)->except(['show']); // A3.4: 營隊管理
    
    // 報表系統 (Reporting) - API 21
    Route::get('reports/revenue', [ReportingController::class, 'getRevenueReport']); // (使用 revenueReport)
    
    // V1.2 A5.1: Admin 手動調整點數 (點數進出)
    Route::post('adjust-points', [AdminPointController::class, 'adjustPoints']);
    
    // A5.x 點數管理
    Route::post('adjust-points', [AdminPointController::class, 'adjustPoints']); // A5.1: 單筆點數調整
    Route::get('point-logs', [AdminPointController::class, 'getAllPointLogs']); // A5.2: 點數日誌查詢
    Route::post('manage-card', [AdminPointController::class, 'manageCard']); // A5.3.1 & A5.3.2: 會籍卡元數據管理 
    Route::post('adjust-total-points', [AdminPointController::class, 'adjustCardTotalPoints']); // A5.3.3: 點數包購買累加 【新增】

    // 系統維護 (DevOps) 【新增】
    Route::post('job/trigger', [AdminJobController::class, 'triggerJob']); // API 19: 手動觸發 Job
});


// --- 2. COACH 專用路由群組 ---
Route::middleware(['auth:sanctum', 'can:is-coach'])->prefix('coach')->group(function () {
    
    // 教練課程列表查詢 - API 17 【修正/補上】
    Route::get('courses', [CourseController::class, 'getCoachCourses']);

    // 點名狀態更新 - API 18 (批次模式)
    Route::post('course/{courseId}/attendance', [AttendanceController::class, 'updateStatus']);
    
});