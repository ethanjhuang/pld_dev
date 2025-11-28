<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus; // 用於批次處理或動態調用 Job
use Illuminate\Validation\ValidationException;
use App\Jobs\CheckWaitlistJob; // 引入 Job
use App\Jobs\ReleaseExpiredLockJob; // 引入 Job

class AdminJobController extends Controller
{
    /**
     * API 19: Admin 手動強制觸發後台 Job
     * 路由: POST /api/admin/job/trigger
     */
    public function triggerJob(Request $request)
    {
        $validated = $request->validate([
            'jobName' => 'required|string|in:CheckWaitlist,ReleaseExpiredLock,FinalizeAttendance', // 限定可觸發的 Job 名稱
            'jobParams' => 'nullable|array', // 傳給 Job 的參數
        ]);

        $jobName = $validated['jobName'];
        $params = $validated['jobParams'] ?? [];
        $jobInstance = null;
        $message = "Job queued successfully.";
        
        try {
            switch ($jobName) {
                case 'CheckWaitlist':
                    // 如果有傳入 courseId 則只檢查該課程，否則檢查所有課程
                    $courseId = $params['courseId'] ?? null;
                    if ($courseId) {
                        $jobInstance = new CheckWaitlistJob($courseId);
                        $message = "CheckWaitlistJob for Course ID [{$courseId}] queued.";
                    } else {
                        $jobInstance = new CheckWaitlistJob(null);
                        $message = "CheckWaitlistJob (All Courses) queued.";
                    }
                    break;
                    
                case 'ReleaseExpiredLock':
                    // 釋放過期的點數轉讓鎖定
                    $jobInstance = new ReleaseExpiredLockJob();
                    $message = "ReleaseExpiredLockJob queued.";
                    break;

                // 註釋：FinalizeAttendance 是由排程系統自動在課程結束後運行，通常不建議手動觸發。
                // 如果需要，可以手動創建 FinalizeAttendanceCommand 實例並執行，但我們目前只處理 Job。
                    
                default:
                    throw new \Exception("Invalid Job name provided.");
            }
            
            // 將 Job 推送到隊列中
            if ($jobInstance) {
                dispatch($jobInstance);
            }

            return response()->json(['message' => $message], 200);

        } catch (ValidationException $e) {
            throw $e; // 讓 Laravel 處理驗證錯誤
        } catch (\Exception $e) {
            \Log::error("Admin Job Trigger Failed: " . $e->getMessage());
            return response()->json(['message' => 'Failed to queue job: ' . $e->getMessage()], 500);
        }
    }
}