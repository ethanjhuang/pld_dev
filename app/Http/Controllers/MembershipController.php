<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MembershipCard;
use App\Models\TransferLog;
use App\Models\SystemConfig;
use App\Models\PointLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class MembershipController extends Controller
{
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