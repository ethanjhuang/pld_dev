<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * LINE API 服務層 (V1.0 藍圖中，AuthController 依賴此服務)
 */
class LineService
{
    /**
     * 從 Access Token 獲取用戶資料陣列。
     *
     * @param string $accessToken 來自前端的 LINE Access Token
     * @return array 包含 line_id, name, email 等資訊的陣列
     */
    public function getLineUserData(string $accessToken): array
    {
        // 實際生產環境中，這裡會調用 LINE 驗證 API，例如：
        // $response = Http::get('https://api.line.me/v2/profile', [
        //     'headers' => ['Authorization' => 'Bearer ' . $accessToken]
        // ]);
        
        // 為了測試，我們模擬返回一個包含所有關鍵欄位的陣列
        $lineId = 'TEST_LINE_ID_' . substr($accessToken, 0, 8); 
        
        return [
            'line_id' => $lineId,
            'name' => '測試會員_' . substr(Str::uuid(), 0, 6),
            'email' => "{$lineId}@test.com",
            'phone' => '0912345678',
            // 更多欄位...
        ];
    }
}