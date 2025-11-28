<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MembershipCard;
use App\Services\LineService; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    protected $lineService;

    // 恢復：使用建構子注入 LineService
    public function __construct(LineService $lineService)
    {
        $this->lineService = $lineService;
    }

    /**
     * API 1: LINE 登入檢查 (檢查用戶是否已註冊)
     */
    public function lineLogin(Request $request)
    {
        $validated = $request->validate([
            'line_access_token' => 'required|string',
        ]);

        // 關鍵修正：移除 LineService 依賴，直接使用傳入的 token 作為 line_id
        // 這是為了在測試環境中，直接使用數據庫中的 line_id 登入
        $lineId = $validated['line_access_token']; 
        // -----------------------------------------------------------

        // 步驟 2: 檢查用戶是否已在 'members' 表中註冊
        $member = Member::where('line_id', $lineId)->first();

        if ($member) {
            // 已註冊: 返回 Token
            $token = $member->createToken('auth-token')->plainTextToken;
            return response()->json([
                'isRegistered' => true,
                'userToken' => $token,
                'memberId' => $member->member_id,
                'role' => $member->role,
            ]);
        }

        // 未註冊: 返回 line_id
        return response()->json([
            'isRegistered' => false,
            'lineId' => $lineId,
        ]);
    }

    /**
     * API 1: LINE 登入檢查 (檢查用戶是否已註冊)
     */
    /*
    public function lineLogin(Request $request)
    {
        $validated = $request->validate([
            'line_access_token' => 'required|string',
        ]);

        // 透過 Service 獲取 lineId
        try {
            $lineId = $this->lineService->getLineIdFromToken($validated['line_access_token']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'LINE Token is invalid or expired.'], 401);
        }

        // 步驟 2: 檢查用戶是否已在 'members' 表中註冊
        $member = Member::where('line_id', $lineId)->first();

        if ($member) {
            // 已註冊: 返回 Token
            $token = $member->createToken('auth-token')->plainTextToken;
            return response()->json([
                'isRegistered' => true,
                'userToken' => $token,
                'memberId' => $member->member_id,
                'role' => $member->role,
            ]);
        }

        // 未註冊: 返回 line_id
        return response()->json([
            'isRegistered' => false,
            'lineId' => $lineId,
        ]);
    }
*/
    /**
     * API 2: 新用戶註冊 (原子操作)
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'line_id' => 'required|string|unique:members,line_id', 
            'name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'required|email|unique:members,email',
            'referralCode' => 'nullable|string',
            'role' => 'nullable|in:ADMIN,COACH,MEMBER', // <--- 修正：新增 role 驗證
        ]);
        
        $member = null; // 初始化為 null，將通過引用在事務內賦值

        try {
            DB::transaction(function () use ($validated, &$member) {

                // A. 寫入 Member (家長) 資料
                $member = Member::create([ // <-- $member 在這裡被正確賦值
                    'member_id' => Str::uuid(),
                    'line_id' => $validated['line_id'],
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'],
                    'referral_code' => Str::random(10), 
                    // 關鍵修正：使用傳入的 role，如果沒有則預設為 MEMBER
                    'role' => $validated['role'] ?? 'MEMBER',
                ]);

                // B. 創建 MembershipCard (會籍) 紀錄
                MembershipCard::create([
                    'card_id' => Str::uuid(),
                    'member_id' => $member->member_id, // <--- 關鍵修正：使用正確的 $member 變數
                    'remaining_points' => 500.00, // 初始點數 (根據業務決定)
                    'locked_points' => 0.00,
                    'type' => 'POINTS', // 必須提供 NOT NULL 欄位的值
                    'purchase_amount' => 0.00,
                ]);
            });

        } catch (\Exception $e) {
            \Log::error("Registration Failed: " . $e->getMessage() . " in file " . $e->getFile() . " on line " . $e->getLine());
            return response()->json(['message' => 'Registration failed due to database error.'], 500);
        }

        $token = $member->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'userToken' => $token,
        ], 201);
    }

    /**
     * API 3: 登出 (撤銷當前 Token)
     * 路由: POST /api/auth/logout
     */
    public function logout(Request $request)
    {
        // 確保用戶已認證，並刪除當前使用的 Token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out.'
        ], 200);
    }
    
}