<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MembershipCard;
use App\Models\TransferLog;
use App\Models\SystemConfig;
use App\Models\PointLog;
use App\Models\Transaction; // 【新增】引入 Transaction Model (API 12 需要)
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule; // 【補齊】引入 Rule (您原本的 updateProfile 有使用到)

class MembershipController extends Controller
{
    /**
     * API 10: 查詢會員卡點數餘額
     */
    public function getBalance(Request $request)
    {
        $member = $request->user();
        $card = $member->membershipCard;

        if (!$card) {
            return response()->json(['message' => 'Membership card not found.'], 404);
        }

        return response()->json([
            'remainingPoints' => $card->remaining_points,
            'lockedPoints' => $card->locked_points,
            'totalPoints' => $card->total_points, 
            'status' => $card->status, 
        ]);
    }

    /**
     * API 11: 查詢會員點數異動紀錄 (PointLog)
     */
    public function getPointLog(Request $request)
    {
        $userId = auth()->user()->member_id;

        // 1. 查找會員的點數卡 ID (關鍵修正：使用 member_id)
        $card = MembershipCard::where('member_id', $userId)->firstOrFail();

        // 2. 查詢 PointLog 並分頁
        $logs = PointLog::where('membership_id', $card->card_id)
            ->orderBy('created_at', 'desc')
            ->paginate(20); 

        // 3. 數據轉換 (確保輸出為 camelCase)
        return response()->json([
            'currentPage' => $logs->currentPage(),
            'lastPage' => $logs->lastPage(),
            'total' => $logs->total(),
            'pointLogs' => $logs->map(function ($log) {
                return [
                    'logId' => $log->log_id,
                    'changeAmount' => (float) $log->change_amount,
                    'changeType' => $log->change_type,
                    'createdAt' => $log->created_at->format('Y-m-d H:i:s'),
                    'relatedId' => $log->related_id,
                ];
            })
        ], 200);
    }
    
    // --- 會員資料管理 (API 4 & 5) ---
    
    /**
     * API 4: 查詢會員個人資料 (Get Member Profile)
     * 路由: GET /api/member/profile
     */
    public function getProfile(Request $request)
    {
        $member = $request->user();
        
        // 排除敏感資訊 (如密碼 Hash)，只返回 Member 模型和 MembershipCard 關聯
        $profile = $member->only(['member_id', 'line_id', 'name', 'phone', 'email', 'role', 'referral_code', 'created_at']);
        
        // 如果 Member 模型有關聯 MembershipCard
        $cardData = [];
        if ($member->membershipCard) {
             $cardData = $member->membershipCard->only(['card_id', 'remaining_points', 'locked_points', 'status']);
        }
        
        return response()->json([
            'member' => $profile,
            'card' => $cardData, 
        ]);
    }

    /**
     * API 5: 更新會員個人資料 (Update Member Profile)
     * 路由: PUT /api/member/profile
     */
    public function updateProfile(Request $request)
    {
        $member = $request->user();
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20',
            // 允許 email 欄位，但必須是唯一的，並忽略當前用戶的 email
            'email' => ['sometimes', 'required', 'email', Rule::unique('members', 'email')->ignore($member->member_id, 'member_id')],
        ]);

        $member->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'member' => $member->only(['member_id', 'name', 'phone', 'email']),
        ]);
    }
    
    // --- 【新增功能】API 12: 點數購買與金流整合 (V1.7 新增) ---

    /**
     * API 12.1: 發起點數購買 (Initiate Purchase)
     * 創建 PENDING 交易，返回付款連結。
     */
    public function purchasePoints(Request $request)
    {
        $memberId = auth()->user()->member_id;

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1', // 購買金額 (TWD)
            'points' => 'required|numeric|min:1', // 對應點數
            'planName' => 'required|string', // 方案名稱
        ]);

        $amount = $validated['amount'];
        $points = $validated['points'];
        $planName = $validated['planName'];
        
        $transactionId = Str::uuid();

        try {
            // 創建 PENDING 交易紀錄
            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'member_id' => $memberId,
                'amount' => $amount,
                'type' => 'POINT_PURCHASE',
                'status' => 'PENDING',
                'description' => "Purchase Plan: {$planName} ({$points} pts)",
            ]);

            return response()->json([
                'message' => 'Purchase initiated. Please proceed to payment.',
                'transactionId' => $transactionId,
                'amount' => $amount,
                'redirectUrl' => 'https://mock.payment.gateway/pay?txn=' . $transactionId, // 模擬金流
            ], 202);

        } catch (\Exception $e) {
            \Log::error("Point purchase initiation failed: " . $e->getMessage());
            return response()->json(['message' => 'Purchase failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API 12.2: 點數購買回調 (Payment Callback)
     * 接收金流通知，確認交易並入帳點數。
     */
    public function paymentCallback(Request $request)
    {
        // 模擬金流回調數據
        $validated = $request->validate([
            // 假設 Transaction 表名為單數 transaction
            'transactionId' => 'required|uuid|exists:transaction,transaction_id',
            'status' => 'required|in:SUCCESS,FAILED',
        ]);

        $transactionId = $validated['transactionId'];
        $isSuccess = $validated['status'] === 'SUCCESS';

        try {
            DB::transaction(function () use ($transactionId, $isSuccess) {
                
                // 1. 鎖定交易紀錄
                $transaction = Transaction::where('transaction_id', $transactionId)->lockForUpdate()->firstOrFail();

                // 防止重複處理 (Idempotency)
                if ($transaction->status === 'PAID' || $transaction->status === 'FAILED') {
                    return; 
                }

                if (!$isSuccess) {
                    $transaction->status = 'FAILED';
                    $transaction->save();
                    return;
                }

                // 2. 解析方案點數 (從 description 解析)
                preg_match('/\((\d+) pts\)/', $transaction->description, $matches);
                $pointsToAdd = isset($matches[1]) ? (float)$matches[1] : 0;

                if ($pointsToAdd <= 0) {
                    throw new \Exception("Failed to parse points from transaction description.");
                }

                // 3. 更新交易狀態
                $transaction->status = 'PAID';
                $transaction->save();

                // 4. 入帳：更新會員卡 (原子操作)
                $card = MembershipCard::where('member_id', $transaction->member_id)->lockForUpdate()->firstOrFail();
                
                $card->total_points += $pointsToAdd;
                $card->remaining_points += $pointsToAdd;
                $card->purchase_amount += $transaction->amount; // 累加總消費金額
                $card->save();

                // 5. 記錄 PointLog
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $card->card_id,
                    'change_amount' => $pointsToAdd,
                    'change_type' => 'POINT_PURCHASE',
                    'related_id' => $transaction->transaction_id,
                    'notes' => 'Online Purchase: ' . $transaction->description,
                ]);
            });

            return response()->json(['message' => 'Payment processed successfully.'], 200);

        } catch (\Exception $e) {
            \Log::error("Payment callback failed: " . $e->getMessage());
            return response()->json(['message' => 'Callback processing failed: ' . $e->getMessage()], 500);
        }
    }

    // --- 【V1.5 核心交易】點數轉讓 (保持原樣) ---

    /**
     * API 13: 點數轉讓 - 啟動階段 (鎖定點數)
     */
    public function initiateTransfer(Request $request)
    {
        $senderId = auth()->user()->member_id;

        $validated = $request->validate([
            'recipientCode' => 'required|string|exists:members,referral_code', 
            'amount' => 'required|numeric|min:1',
        ]);
        
        $recipient = Member::where('referral_code', $validated['recipientCode'])->firstOrFail();
        $amount = $validated['amount'];
        
        // 讀取配置，預設 24 小時
        $lockHours = (int) SystemConfig::where('key_name', 'TRANSFER_LOCK_HOURS')->value('value') ?? 2; 

        $logId = null; // 初始化 Log ID

        try {
            DB::transaction(function () use ($senderId, $recipient, $amount, $lockHours, &$logId) {
                
                // 關鍵修正：使用 member_id 鎖定發送方點數卡
                $senderCard = MembershipCard::where('member_id', $senderId)->lockForUpdate()->firstOrFail();
                
                if ($senderCard->remaining_points < $amount) {
                    throw new \Exception("Insufficient points to initiate transfer.");
                }

                // 鎖定點數：從 remaining 轉移到 locked
                $senderCard->remaining_points -= $amount;
                $senderCard->locked_points += $amount;
                $senderCard->save();
                
                // 創建 TransferLog 紀錄
                $logId = Str::uuid();
                TransferLog::create([
                    'log_id' => $logId,
                    'sender_id' => $senderId,
                    'recipient_id' => $recipient->member_id,
                    'amount' => $amount,
                    'status' => 'LOCKED', 
                    'expiry_time' => Carbon::now()->addHours($lockHours), 
                ]);

                // 創建 PointLog 紀錄 (點數被鎖定)
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $senderCard->card_id,
                    'change_amount' => -$amount,
                    'change_type' => 'TRANSFER_LOCKED',
                    'related_id' => $logId,
                ]);
            });

        } catch (\Exception $e) {
            \Log::error("Transfer initiation failed: " . $e->getMessage());
            return response()->json(['message' => 'Transfer initiation failed: ' . $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Transfer initiated. Points locked for ' . $lockHours . ' hours.',
            'transferLogId' => $logId,
        ], 200);
    }
    
    /**
     * API 14: 點數轉讓 - 執行階段 (由發送方 A 最終確認轉出)
     */
    public function executeTransfer(Request $request)
    {
        $senderId = auth()->user()->member_id;

        $validated = $request->validate([
            'transferLogId' => 'required|uuid|exists:transfer_logs,log_id',
        ]);

        try {
            DB::transaction(function () use ($validated, $senderId) {
                
                $log = TransferLog::lockForUpdate()
                    ->where('log_id', $validated['transferLogId'])
                    ->where('sender_id', $senderId)
                    ->firstOrFail();

                if ($log->status !== 'LOCKED' || Carbon::now()->greaterThan($log->expiry_time)) {
                    throw new \Exception("Transfer is not in a LOCKED state or has expired.");
                }
                
                $amount = $log->amount;
                
                // 關鍵修正：使用 member_id 鎖定發送方和接收方點數卡
                $senderCard = MembershipCard::where('member_id', $senderId)->lockForUpdate()->firstOrFail();
                $recipientCard = MembershipCard::where('member_id', $log->recipient_id)->lockForUpdate()->firstOrFail();
                
                // 執行轉移 (原子操作)
                $senderCard->locked_points -= $amount; 
                $senderCard->save();
                
                $recipientCard->remaining_points += $amount;
                $recipientCard->save();
                
                // 更新 Log 狀態
                $log->status = 'CONFIRMED';
                $log->save();
                
                // 創建 PointLog 紀錄 (發送方點數解鎖並消耗)
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $senderCard->card_id,
                    'change_amount' => -$amount, // 從 locked 轉為 consumed，不影響 R 
                    'change_type' => 'TRANSFER_COMPLETED_CONSUMED',
                    'related_id' => $log->log_id,
                ]);

                // 創建 PointLog 紀錄 (接收方點數增加)
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $recipientCard->card_id,
                    'change_amount' => $amount,
                    'change_type' => 'TRANSFER_RECEIVED',
                    'related_id' => $log->log_id,
                ]);
            });
        } catch (\Exception $e) {
            \Log::error("Transfer execution failed: " . $e->getMessage());
            return response()->json(['message' => 'Transfer execution failed: ' . $e->getMessage()], 500);
        }
        return response()->json(['message' => 'Points successfully transferred.'], 200);
    }

    /**
     * API 15: 點數轉讓 - 取消階段 (由發送方 A 手動取消)
     */
    public function cancelTransfer(Request $request)
    {
        // 只有發送方 (A) 才有權限呼叫此 API
        $senderId = auth()->user()->member_id;

        $validated = $request->validate([
            'transferLogId' => 'required|uuid|exists:transfer_logs,log_id',
        ]);

        try {
            DB::transaction(function () use ($validated, $senderId) {
                
                $log = TransferLog::lockForUpdate()
                    ->where('log_id', $validated['transferLogId'])
                    ->where('sender_id', $senderId)
                    ->firstOrFail();

                // 1. 狀態檢查 (只能取消 LOCKED 狀態的紀錄)
                if ($log->status !== 'LOCKED') {
                    throw new \Exception("Transfer is not in a LOCKED state and cannot be cancelled.");
                }
                
                $amount = $log->amount;
                
                // 2. 執行回滾 (原子操作)
                // 關鍵修正：使用 member_id 鎖定發送方點數卡
                $senderCard = MembershipCard::where('member_id', $senderId)->lockForUpdate()->firstOrFail();
                
                // 從 Locked 點數退回 Remaining 點數
                if ($senderCard->locked_points < $amount) {
                    throw new \Exception("Data inconsistency: Locked points insufficient for rollback.");
                }
                $senderCard->locked_points -= $amount; 
                $senderCard->remaining_points += $amount;
                $senderCard->save();
                
                // 3. 更新 Log 狀態
                $log->status = 'CANCELLED';
                $log->save();

                // 創建 PointLog 紀錄 (點數解鎖退還)
                PointLog::create([
                    'log_id' => Str::uuid(),
                    'membership_id' => $senderCard->card_id,
                    'change_amount' => $amount, 
                    'change_type' => 'TRANSFER_CANCELLED_REFUND',
                    'related_id' => $log->log_id,
                ]);

            });
        } catch (\Exception $e) {
            \Log::error("Transfer cancellation failed: " . $e->getMessage());
            return response()->json(['message' => 'Transfer cancellation failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Transfer successfully cancelled and points returned to available balance.'], 200);
    }
}