<?php

namespace App\Http\Controllers;

use App\Services\CampBookingService;
use App\Services\CampCancellationService;
use App\Exceptions\ConflictException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CampBookingController extends Controller
{
    protected $campBookingService;
    protected $campCancellationService;

    // 依賴注入兩個服務
    public function __construct(CampBookingService $campBookingService, CampCancellationService $campCancellationService)
    {
        $this->campBookingService = $campBookingService;
        $this->campCancellationService = $campCancellationService;
    }

    /**
     * API 23.1: 營隊預約發起 (預鎖名額，返回交易資訊)
     * 路由: POST /api/camp/booking/initiate
     */
    public function initiateBooking(Request $request)
    {
        $memberId = $request->user()->member_id;

        $validated = $request->validate([
            'campId' => 'required|uuid|exists:camps,camp_id', // 【修正】exists:camps
            'participants' => 'required|array|min:1',
            'participants.*.childId' => 'nullable|uuid|exists:child,child_id', // 修正為單數 child 表名
            'participants.*.guestName' => 'nullable|string',
            'participants.*.guestAge' => 'nullable|integer',
        ]);
        
        try {
            // 服務層執行原子性：鎖定 Camp 名額，創建 PENDING 交易和 PENDING_PAYMENT 預約
            $results = $this->campBookingService->initiatePayment($validated['campId'], $validated['participants'], $memberId);
            
            return response()->json([
                'message' => 'Booking spot reserved. Pending payment.',
                'paymentInfo' => $results, // 包含 Transaction ID 和應付金額
            ], 202); // 202 Accepted, 處理中
            
        } catch (ConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Booking initiation failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API 23.2: 金流回調/Webhook 模擬 (交易確認 -> 報名成功)
     * 路由: POST /api/camp/booking/confirm
     */
    public function confirmPayment(Request $request)
    {
        // 模擬金流系統的回調數據
        $validated = $request->validate([
            'transactionId' => 'required|uuid|exists:transaction,transaction_id',
            'status' => 'required|in:SUCCESS,FAILED', 
        ]);

        // 服務層執行原子性：更新 Transaction/Booking 狀態，並釋放或確認名額
        $isSuccess = $validated['status'] === 'SUCCESS';
        
        try {
            $results = $this->campBookingService->finalizeBooking($validated['transactionId'], $isSuccess);

            return response()->json($results, 200);

        } catch (ConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Booking confirmation failed: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * API 24: 營隊取消與退款 (原子交易)
     * 路由: POST /api/camp/booking/cancel/{bookingId}
     */
    public function cancelBooking(string $bookingId)
    {
         $memberId = auth()->user()->member_id;
         
         try {
             $refundAmount = $this->campCancellationService->cancelBooking($bookingId, $memberId);

             return response()->json([
                 'message' => 'Camp booking successfully cancelled.',
                 'refundAmount' => (float) $refundAmount,
                 'note' => 'Refund amount calculated based on Camp cancellation policy.',
             ], 200);
             
         } catch (\Exception $e) {
             return response()->json(['message' => 'Cancellation failed: ' . $e->getMessage()], 400);
         }
    }
}