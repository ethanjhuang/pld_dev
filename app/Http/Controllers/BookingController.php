<?php

namespace App\Http\Controllers;

use App\Jobs\CheckWaitlistJob;
use App\Models\PointLog;
use App\Models\Course;
use App\Models\Booking;
use App\Models\MembershipCard;
use App\Models\SystemConfig;
use App\Exceptions\ConflictException; // 引入自定義的例外

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    // API 7: 處理預約、批次預約、後補、點數鎖定 (包含覆蓋邏輯)
    public function createBooking(Request $request)
    {
        // ... (createBooking 邏輯保持不變，因為邏輯已修正)
        $userId = auth()->user()->member_id;

        $validated = $request->validate([
            'courseId' => 'required|uuid|exists:courses,course_id',
            'participants' => 'required|array|min:1', 
            'participants.*.childId' => 'nullable|uuid|exists:children,child_id',
            'participants.*.guestName' => 'nullable|string',
            'participants.*.guestAge' => 'nullable|integer',
            'forceOverride' => 'nullable|boolean', 
        ]);

        $courseId = $validated['courseId'];
        $participants = $validated['participants'];
        $forceOverride = $validated['forceOverride'] ?? false;
        
        $results = [];
        $totalPointsToLock = 0;

        try {
            DB::transaction(function () use ($userId, $courseId, $participants, $forceOverride, &$results, &$totalPointsToLock) {
                
                $course = Course::findOrFail($courseId);

                // 步驟 1: 衝突檢查
                $conflicts = $this->checkConflicts($userId, $course);
                
                if (!empty($conflicts)) {
                    if (!$forceOverride) {
                        throw new ConflictException('Scheduling conflict detected. Please confirm to override old bookings.', $conflicts);
                    }
                    foreach ($conflicts as $conflictingBookingId) {
                        $this->processCancellation($conflictingBookingId, $userId, true); 
                    }
                }

                // 步驟 2: 鎖定資源
                $card = MembershipCard::where('member_id', $userId)->lockForUpdate()->firstOrFail();
                $lockedCourse = Course::lockForUpdate()->find($course->course_id);

                $neededPoints = $lockedCourse->required_points;
                
                // 步驟 3: 處理每個參與者的預約狀態
                foreach ($participants as $participant) {
                    $childId = $participant['childId'] ?? null;
                    
                    $pointsForBooking = 0; 
                    
                    $currentAvailable = $lockedCourse->max_capacity - $lockedCourse->current_bookings;
                    $status = ($currentAvailable > 0) ? 'CONFIRMED' : 'WAITING';
                    $isPaid = false;
                    
                    // 點數硬性門檻檢查
                    if ($card->remaining_points < $neededPoints) {
                        $results[] = ['status' => 'REJECTED', 'reason' => 'Insufficient remaining points.'];
                        continue; 
                    }
                    
                    if ($status === 'CONFIRMED') {
                        // A: 確認預約 (扣除點數)
                        $card->remaining_points -= $neededPoints;
                        $lockedCourse->current_bookings += 1;
                        $isPaid = true;
                        $pointsForBooking = $neededPoints; 

                        // 記錄 PointLog (CONFIRMED)
                        PointLog::create([
                            'log_id' => Str::uuid(),
                            'membership_id' => $card->card_id,
                            'change_amount' => -$pointsForBooking, 
                            'change_type' => 'BOOKING_CONFIRMED',
                            'related_id' => $courseId,
                        ]);
                    } else {
                        // C: 排隊且鎖點
                        $status = 'WAITING';
                        $pointsForBooking = $neededPoints; 
                        $totalPointsToLock += $pointsForBooking;
                    }
                    
                    $isMember = ($childId === null && ($participant['guestName'] ?? null) === null);

                    // 創建 Booking 紀錄
                    $booking = Booking::create([
                        'booking_id' => Str::uuid(),
                        'member_id' => $userId,
                        'course_id' => $courseId,
                        'child_id' => $childId,
                        'status' => $status,
                        'points_deducted' => $pointsForBooking, 
                        'is_paid' => $isPaid,
                        'guest_child_name' => $participant['guestName'] ?? null,
                        'is_member_participant' => $isMember, 
                        'waiting_list_rank' => ($status === 'WAITING') ? Booking::where('course_id', $courseId)->where('status', 'WAITING')->count() + 1 : null,
                    ]);

                    $results[] = [
                        'status' => $status, 
                        'childId' => $childId, 
                        'guestName' => $participant['guestName'] ?? null,
                        'bookingId' => $booking->booking_id
                    ];
                }

                // 步驟 4: 執行最終點數鎖定 (原子操作)
                if ($totalPointsToLock > 0) {
                    $card->remaining_points -= $totalPointsToLock; 
                    $card->locked_points += $totalPointsToLock;

                    // 記錄 PointLog (WAITING)
                    PointLog::create([
                        'log_id' => Str::uuid(),
                        'membership_id' => $card->card_id,
                        'change_amount' => -$totalPointsToLock, 
                        'change_type' => 'BOOKING_LOCKED',
                        'related_id' => $courseId,
                    ]);
                }
                
                $card->save();
                $lockedCourse->save();
            });

        } catch (ConflictException $e) {
            return response()->json(['message' => $e->getMessage(), 'conflicts' => $e->getConflicts()], 409);
        } catch (\Exception $e) {
            Log::error("Booking failed: " . $e->getMessage());
            return response()->json(['message' => 'Booking failed: ' . $e->getMessage()], 500); 
        }

        // 步驟 5: 返回最終結果
        return response()->json([
            'message' => 'Booking process completed.',
            'results' => $results,
        ], 200);
    }
    
    // API 8: 取消預約邏輯 (Request-Response 封裝)
    public function cancelBooking(string $bookingId)
    {
        $userId = auth()->user()->member_id;
        $courseId = null; 

        try {
            DB::transaction(function () use ($bookingId, $userId, &$courseId) {
                $courseId = $this->processCancellation($bookingId, $userId, false);
            });

            // 5. 【V1.1 核心】取消成功後，觸發遞補隊列任務
            if ($courseId) {
                // --- CRITICAL FIX: 使用明確的 dispatch 語法，確保推入隊列 ---
                dispatch(new CheckWaitlistJob($courseId))->onQueue('waitlist');
            }

        } catch (\Exception $e) {
            return response()->json(['message' => 'Cancellation failed: ' . $e->getMessage()], 400); 
        }

        return response()->json([
            'message' => 'Booking successfully cancelled.',
            'refunded' => true,
        ], 200);
    }
    
    /**
     * 核心取消處理邏輯 (供 API 7 和 API 8 內部調用)
     * @param bool $isForceOverride 是否是 API 7 覆蓋強制取消。
     * @return string course_id
     */
    private function processCancellation(string $bookingId, string $userId, bool $isForceOverride): string
    {
        // 1. 鎖定預約紀錄和點數卡
        $booking = Booking::lockForUpdate()
            ->where('booking_id', $bookingId)
            ->where('member_id', $userId)
            ->firstOrFail();

        // 記錄取消前的狀態
        $wasConfirmed = ($booking->status === 'CONFIRMED'); 
        
        if (in_array($booking->status, ['CANCELLED', 'ATTENDED', 'NO_SHOW', 'CANCELLED_BY_ADMIN', 'CANCELLED_BY_SYSTEM'])) {
            throw new \Exception("Booking is already finalized or cancelled.");
        }

        $course = Course::lockForUpdate()->find($booking->course_id);
        $card = MembershipCard::where('member_id', $userId)->lockForUpdate()->firstOrFail();
        
        // 2. 檢查 T-24H 門檻 (API 8 外部請求檢查，覆蓋時略過)
        if (!$isForceOverride) {
            $config = SystemConfig::where('key_name', 'CANCELLATION_WINDOW_HOURS')->first();
            $cancellationHours = $config ? (int)$config->value : 24;
            $cancellationDeadline = Carbon::parse($course->start_time)->subHours($cancellationHours);

            if (Carbon::now()->greaterThan($cancellationDeadline)) {
                throw new \Exception("Cancellation deadline passed. Points are non-refundable.");
            }
        }

        $refundAmount = 0.00;
        
        // 3. 執行點數返還/解除鎖定
        if ($wasConfirmed) { // 使用取消前的狀態判斷是否已支付
            // A. 已扣點預約 (CONFIRMED) - 執行退點
            $refundAmount = $booking->points_deducted;
            $card->remaining_points += $refundAmount;
            
            // 記錄 PointLog (CANCELLATION_REFUND)
            PointLog::create([
                'log_id' => Str::uuid(),
                'membership_id' => $card->card_id,
                'change_amount' => $refundAmount, 
                'change_type' => 'CANCELLATION_REFUND',
                'related_id' => $bookingId,
            ]);
            
        } elseif ($booking->status === 'WAITING' && $booking->points_deducted > 0) {
            // B. 點數被鎖定預約 (WAITING) - 解除鎖定
            $unlockAmount = $booking->points_deducted; 
            
            if ($card->locked_points < $unlockAmount) {
                throw new \Exception("Data inconsistency: Locked points less than unlock amount.");
            }
            
            $card->locked_points -= $unlockAmount; 
            $card->remaining_points += $unlockAmount; 
            $refundAmount = $unlockAmount;
            
            // 記錄 PointLog (UNLOCKED_CANCELLATION)
            PointLog::create([
                'log_id' => Str::uuid(),
                'membership_id' => $card->card_id,
                'change_amount' => $unlockAmount, 
                'change_type' => 'UNLOCKED_CANCELLATION',
                'related_id' => $bookingId, 
            ]);
        }

        // 4. 更新 Booking 狀態
        $booking->status = 'CANCELLED';
        $booking->cancellation_time = Carbon::now();
        $booking->save();

        // 5. 釋放名額計數器 (僅 CONFIRMED 狀態會佔名額)
        if ($wasConfirmed) { // 使用取消前的狀態判斷是否需要釋放名額
            $course->current_bookings -= 1;
            $course->save();
        }
        
        $card->save();
        
        return $course->course_id;
    }
    
    /**
     * 檢查預約的課程是否與用戶名下任一參與者的現有預約衝突。
     */
    private function checkConflicts(string $userId, Course $course)
    {
        $courseStartTime = $course->start_time;
        $courseEndTime = $course->end_time;

        $conflictingBookings = Booking::select('booking_id')
            ->where('member_id', $userId)
            ->whereNotIn('status', ['CANCELLED', 'ATTENDED', 'NO_SHOW', 'CANCELLED_BY_ADMIN', 'CANCELLED_BY_SYSTEM'])
            ->whereHas('course', function ($query) use ($courseStartTime, $courseEndTime) {
                $query->where('start_time', '<', $courseEndTime)
                      ->where('end_time', '>', $courseStartTime);
            })
            ->get();

        return $conflictingBookings->pluck('booking_id')->toArray();
    }
}