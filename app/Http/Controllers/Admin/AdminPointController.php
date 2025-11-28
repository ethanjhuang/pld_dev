<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipCard;
use App\Models\PointLog;
use App\Models\Booking; // 必須引入 Booking Model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class AdminPointController extends Controller
{
    /**
     * Admin API A5.1: 執行手動點數調整 (增加/扣除)
     * 用於單筆糾正，非點數包購買。
     */
    public function adjustPoints(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can adjust points.'], 403);
        }

        $validated = $request->validate([
            'memberId' => 'required|uuid|exists:members,member_id',
            'amount' => 'required|numeric|min:0.01', 
            'operation' => ['required', Rule::in(['ADD', 'DEDUCT'])],
            'reason' => 'required|string|max:255',
        ]);

        $memberId = $validated['memberId'];
        $amount = $validated['amount'];
        $operation = $validated['operation'];
        $reason = $validated['reason'];
        $adminId = auth()->user()->member_id;

        try {
            DB::transaction(function () use ($memberId, $amount, $operation, $reason, $adminId) {
                
                // 1. 鎖定會員點數卡
                $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->firstOrFail();

                $changeAmount = 0;
                $logType = '';
                $deductedFromLocked = 0; // 追蹤是否從 locked_points 扣除

                if ($operation === 'ADD') {
                    // 2a. 增加點數
                    $card->total_points += $amount;
                    $card->remaining_points += $amount;
                    $changeAmount = $amount;
                    $logType = 'ADMIN_ADJUST_ADD';
                } elseif ($operation === 'DEDUCT') {
                    // 2b. 扣除點數
                    
                    // 計算需要從 locked_points 扣除的量
                    $neededFromLocked = max(0, $amount - $card->remaining_points);
                    
                    // 1. 檢查點數總和是否足夠
                    if (($card->remaining_points + $card->locked_points) < $amount) {
                            throw new \Exception("Cannot deduct {$amount}. Available points (remaining + locked) are insufficient.");
                    }
                    
                    // 2. 執行扣除
                    if ($neededFromLocked > 0) {
                            // 複雜扣除：remaining_points 歸零，從 locked_points 扣除餘額
                            $card->remaining_points = 0;
                            $card->locked_points -= $neededFromLocked;
                            $deductedFromLocked = $neededFromLocked; // 標記：locked_points 被觸及
                    } else {
                        // 簡單扣除：只從 remaining_points 扣除
                        $card->remaining_points -= $amount;
                    }
                    
                    $card->total_points -= $amount;
                    $changeAmount = -$amount;
                    $logType = 'ADMIN_ADJUST_DEDUCT';
                    
                    // --- 3. CRITICAL CLEANUP (服務完整性檢查) ---
                    if ($deductedFromLocked > 0) {
                        // 如果 Admin 扣點觸及了 locked_points，必須取消所有依賴這些鎖定資金的排隊預約
                        $bookingsToCancel = Booking::where('member_id', $memberId)
                            ->whereIn('status', ['WAITING', 'WAITING_LOCKED'])
                            ->where('points_deducted', '>', 0) 
                            ->lockForUpdate()
                            ->get();
                            
                        foreach ($bookingsToCancel as $booking) {
                            $booking->status = 'CANCELLED_BY_ADMIN';
                            $booking->cancellation_time = Carbon::now();
                            $booking->save();
                            
                            // 記錄清理 Log (變動量為 0，僅記錄動作)
                            PointLog::create([
                                'log_id' => Str::uuid(),
                                'membership_id' => $card->card_id,
                                'change_amount' => 0.00, 
                                'change_type' => 'ADMIN_WAITLIST_CLEANUP',
                                'related_id' => $booking->booking_id,
                                'notes' => 'Admin deduction removed underlying locked funds for course ' . $booking->course_id,
                            ]);
                        }
                    }
                    // --- END CLEANUP ---
                }

                $card->save();

                // 4. 記錄 PointLog (審計追蹤)
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $card->card_id,
                    'change_amount' => $changeAmount,
                    'change_type' => $logType,
                    'related_id' => $adminId, // 記錄是哪個管理員執行的操作
                    'notes' => $reason,
                ]);

                Log::info("Admin {$adminId} adjusted points for member {$memberId}: {$operation} {$amount} points.");

            });
        } catch (\Exception $e) {
            Log::error("Admin point adjustment failed: " . $e->getMessage());
            return response()->json(['message' => 'Point adjustment failed: ' . $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Points successfully adjusted.',
            'remainingPoints' => MembershipCard::where('member_id', $memberId)->value('remaining_points'),
        ], 200);
    }
    
    /**
     * Admin API A5.2: 點數 Log 查詢 (附帶篩選功能)
     */
    public function getAdminPointLogs(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can view all point logs.'], 403);
        }

        $validated = $request->validate([
            'memberId' => 'nullable|uuid|exists:members,member_id',
            'changeType' => ['nullable', 'string', Rule::in([
                'BOOKING_CONFIRMED', 'BOOKING_LOCKED', 'CANCELLATION_REFUND', 
                'UNLOCKED_CANCELLATION', 'WAITLIST_CONFIRMED', 'LOCKED_TIMEOUT_RELEASE',
                'ADMIN_ADJUST_ADD', 'ADMIN_ADJUST_DEDUCT', 'ADMIN_WAITLIST_CLEANUP',
                'FINALIZED_REFUND', 'NO_SHOW', 'UNLOCKED_FINALIZED', 'ADMIN_CARD_ADJUST', // A5.3 新增 Log Type
                'TRANSFER_COMPLETED_CONSUMED', 'TRANSFER_RECEIVED', 'TRANSFER_LOCKED', 'TRANSFER_CANCELLED_REFUND' // 轉讓 Log Type
            ])],
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $query = PointLog::query();

        // 篩選：按會員 ID
        if (isset($validated['memberId'])) {
            // 透過 memberId 找到 cardId
            $cardId = MembershipCard::where('member_id', $validated['memberId'])->value('card_id');
            if ($cardId) {
                $query->where('membership_id', $cardId);
            }
        }

        // 篩選：按變動類型
        if (isset($validated['changeType'])) {
            $query->where('change_type', $validated['changeType']);
        }
        
        // 篩選：按日期範圍
        if (isset($validated['startDate'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['startDate'])->startOfDay());
        }
        if (isset($validated['endDate'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['endDate'])->endOfDay());
        }

        $perPage = $validated['perPage'] ?? 20;

        // 排序：確保最新的 Log 在最上面
        $pointLogs = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'currentPage' => $pointLogs->currentPage(),
            'lastPage' => $pointLogs->lastPage(),
            'total' => $pointLogs->total(),
            'pointLogs' => $pointLogs->items(), 
        ]);
    }

    /**
     * Admin API A5.3: 會員卡屬性管理 (創建/更新效期/強制財務修正)
     * 專注於卡片元數據的創建和修正。
     */
    public function manageCard(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can manage membership cards.'], 403);
        }

        $validated = $request->validate([
            'memberId' => 'required|uuid|exists:members,member_id',
            'totalPoints' => 'nullable|numeric|min:0', // 用於創建時初始化，更新時用於修正 totalPoints
            'expiryDate' => 'nullable|date_format:Y-m-d', // 用於設置或延長效期
            'purchaseAmount' => 'nullable|numeric|min:0', // 用於創建時初始化或強制修正 total purchase amount
            'cardStatus' => ['nullable', 'string', Rule::in(['ACTIVE', 'EXPIRED', 'SUSPENDED'])], // 【新增】修正狀態
        ]);

        $memberId = $validated['memberId'];
        $totalPoints = $validated['totalPoints'] ?? null;
        $expiryDate = $validated['expiryDate'] ?? null;
        $purchaseAmount = $validated['purchaseAmount'] ?? null;
        $cardStatus = $validated['cardStatus'] ?? null; // 獲取卡片狀態
        $adminId = auth()->user()->member_id;
        $action = '';
        $card = null; 

        try {
            DB::transaction(function () use ($memberId, $totalPoints, $expiryDate, $purchaseAmount, $cardStatus, $adminId, &$action, &$card, $request) {
                
                // 1. 鎖定會員卡
                $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->first();
                $notes = [];
                
                if (!$card) {
                    // --- A5.3.1: CREATE 邏輯 (初始化卡片) ---
                    $card = MembershipCard::create([
                        'card_id' => Str::uuid(),
                        'member_id' => $memberId,
                        'total_points' => $totalPoints ?? 0,
                        'remaining_points' => $totalPoints ?? 0, 
                        'locked_points' => 0,
                        'purchase_amount' => $purchaseAmount ?? 0.00,
                        'status' => $cardStatus ?? 'ACTIVE', // 使用 status 欄位 (假設 MembershipCard Model 已修正)
                        'expiry_date' => $expiryDate ? Carbon::parse($expiryDate) : null,
                        'type' => 'POINTS', // 必須提供 NOT NULL 欄位的值
                    ]);
                    $action = 'CREATED';
                    $notes[] = "Card created with total points: {$card->total_points}, purchase amount: {$card->purchase_amount}, status: {$card->status}.";
                } else {
                    // --- A5.3.2: UPDATE 邏輯 (屬性修正) ---
                    
                    // 1. 修正 totalPoints (只允許修正，點數累加由 adjustCardTotalPoints 處理)
                    if ($totalPoints !== null && $totalPoints != $card->total_points) {
                        
                        $pointsDifference = $totalPoints - $card->total_points;
                        
                        // 只有增加點數時，才同步增加 remaining_points
                        if ($pointsDifference > 0) {
                            $card->remaining_points += $pointsDifference;
                        } 
                        
                        $notes[] = "Total points manually corrected from {$card->total_points} to {$totalPoints}.";

                        // 記錄 Log (用於審計 totalPoints 的修正變動)
                        PointLog::create([
                            'log_id' => Str::uuid(),
                            'membership_id' => $card->card_id,
                            'change_amount' => $pointsDifference, 
                            'change_type' => 'ADMIN_CARD_ADJUST',
                            'related_id' => $adminId,
                            'notes' => 'Card Total Points Correction: ' . ($pointsDifference > 0 ? '+' : '') . $pointsDifference,
                        ]);
                        
                        $card->total_points = $totalPoints; // 設置新的 totalPoints
                    }

                    // 2. 修正 Expiry Date
                    $currentExpiryDateString = $card->expiry_date ? $card->expiry_date->format('Y-m-d') : null;

                    if ($expiryDate && $expiryDate != $currentExpiryDateString) {
                        $card->expiry_date = Carbon::parse($expiryDate);
                        $notes[] = "Expiry date updated to {$expiryDate}.";
                    }
                    
                    // 3. 修正 Card Status (狀態修正)
                    if ($cardStatus && $cardStatus != $card->status) {
                        $card->status = $cardStatus;
                        $notes[] = "Card status manually changed to {$cardStatus}.";
                    }
                    
                    // 4. 強制修正 purchaseAmount (範例三)
                    if ($request->has('purchaseAmount') && $purchaseAmount !== null) {
                        $card->purchase_amount = $purchaseAmount;
                        $notes[] = "Purchase amount explicitly set/corrected to {$purchaseAmount}.";
                    }
                    
                    $card->save();
                    $action = 'UPDATED';
                }

                if (empty($notes)) {
                    $notes[] = "No effective changes applied.";
                }

                Log::info("Admin {$adminId} managed membership card for member {$memberId}: {$action}. Notes: " . implode('; ', $notes));

            });
        } catch (\Exception $e) {
            Log::error("Admin membership card management failed: " . $e->getMessage());
            return response()->json(['message' => 'Membership card management failed: ' . $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Membership card successfully managed.',
            'cardId' => $card->card_id,
            'action' => $action,
        ], 200);
    }

    /**
     * Admin API A5.3: 點數包購買/續費 (點數疊加和金額累加)
     * 處理點數增加和金額累加的交易，確保財務審計的正確性。
     */
    public function adjustCardTotalPoints(Request $request)
    {
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can adjust total points.'], 403);
        }

        $validated = $request->validate([
            'memberId' => 'required|uuid|exists:members,member_id',
            'newTotalPoints' => 'required|numeric|min:0', // 新的點數總量
            'purchaseAmount' => 'required|numeric|min:0', // 這次購買的金額
            'reason' => 'required|string|max:255',
        ]);

        $memberId = $validated['memberId'];
        $newTotalPoints = $validated['newTotalPoints'];
        $purchaseAmount = $validated['purchaseAmount'];
        $reason = $validated['reason'];
        $adminId = auth()->user()->member_id;
        $card = null;

        try {
            DB::transaction(function () use ($memberId, $newTotalPoints, $purchaseAmount, $reason, $adminId, &$card) {
                
                $card = MembershipCard::where('member_id', $memberId)->lockForUpdate()->firstOrFail();
                
                // 1. 計算點數差異 (必須是增加，如果減少應該使用 adjustPoints)
                $pointsDifference = $newTotalPoints - $card->total_points;

                if ($pointsDifference <= 0) {
                    throw new \Exception("New total points must be greater than current total points for this transaction type. Use adjustPoints for deduction/correction.");
                }

                // 2. 執行累加 (點數和金額)
                $card->total_points = $newTotalPoints;
                $card->remaining_points += $pointsDifference;
                $card->purchase_amount += $purchaseAmount; // CRITICAL FIX: 金額累加
                
                $card->save();

                // 3. 記錄 PointLog (審計追蹤)
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $card->card_id,
                    'change_amount' => $pointsDifference, // 使用差額作為變動量
                    'change_type' => 'ADMIN_CARD_ADJUST',
                    'related_id' => $adminId,
                    'notes' => 'Package Renewal/Purchase: +' . $pointsDifference . ' points; Amount: ' . $purchaseAmount . ' - Reason: ' . $reason,
                ]);

                Log::info("Admin {$adminId} processed purchase for member {$memberId}. Total points updated to {$newTotalPoints}.");

            });
        } catch (\Exception $e) {
            Log::error("Admin total points adjustment failed: " . $e->getMessage());
            return response()->json(['message' => 'Admin total points adjustment failed: ' . $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Total points and purchase amount successfully updated.',
            'cardId' => $card->card_id,
            'newTotalPoints' => $card->total_points,
            'newPurchaseAmount' => $card->purchase_amount,
        ], 200);
    }
}