<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class OAuthService
{
    /**
     * 處理 LINE 登入回調，創建或更新 Member 紀錄
     *
     * @param array $lineUserData 來自 LINE API 的用戶資料
     * @return Member
     */
    public function findOrCreateMember(array $lineUserData): Member
    {
        $lineId = $lineUserData['line_id']; // 從 LINE Service 獲取的 line_id

        // 1. 查找現有會員
        // CRITICAL FIX: 確保 line_id 查詢是正確的
        $member = Member::where('line_id', $lineId)->first();

        if ($member) {
            // 會員已存在，直接返回
            return $member;
        }

        // 2. 新會員註冊：必須自動生成和賦予密碼
        
        // 確保密碼欄位符合 Authenticatable 契約
        $randomPassword = Str::random(32); // 生成一個複雜的隨機字串
        $hashedPassword = Hash::make($randomPassword); // 將隨機字串進行雜湊加密

        // 設置基本欄位值
        $memberId = Str::uuid();

        // 3. 創建新的 Member 紀錄
        $member = Member::create([
            'member_id' => $memberId,
            'line_id' => $lineId,
            // 使用 Line Service 返回的完整數據
            'name' => $lineUserData['name'] ?? '新會員', 
            'email' => $lineUserData['email'] ?? "line_{$memberId}@temp.com",
            'phone' => $lineUserData['phone'] ?? null,
            'role' => 'MEMBER', // 預設角色
            'password' => $hashedPassword, // 關鍵修正：自動賦予加密密碼
        ]);

        // 註冊時，同步創建會員點數卡 (假設初始點數為 0)
        // 應在此處或使用 Event/Listener 創建 MembershipCard 紀錄
        // 例如: $member->membershipCard()->create([...]);

        return $member;
    }
}