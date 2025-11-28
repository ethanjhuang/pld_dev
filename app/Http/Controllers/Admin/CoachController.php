<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coach;
use App\Models\Course;
use App\Models\Member; // 新增引入 Member 模型
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // 新增引入 DB
use Illuminate\Support\Facades\Hash; // 新增引入 Hash
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CoachController extends Controller
{
    /**
     * Admin API A3.1 (R): 查詢教練列表
     */
    public function index(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can view coach list.'], 403);
        }

        $perPage = $request->get('perPage', 20);
        $search = $request->get('search'); // 允許按名稱或 Email 搜索

        $query = Coach::query();

        if ($search) {
            // PostgreSQL often uses ILIKE for case-insensitive search
            $query->where(function($q) use ($search) {
                $q->where('name', 'ILIKE', '%' . $search . '%')
                  ->orWhere('email', 'ILIKE', '%' . $search . '%');
            });
        }

        // 預設按名稱排序
        $coaches = $query->orderBy('name')->paginate($perPage);

        return response()->json($coaches);
    }

    /**
     * Admin API A3.1 (C): 創建新教練 (同步處理 Member 帳號)
     */
    public function store(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can create coaches.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20', 
            'email' => 'required|email|max:255', // 移除 unique:coaches，改由邏輯控制
            'bio' => 'nullable|string',                      
            'imageUrl' => 'nullable|string',                 
            'is_active' => 'required|boolean',               
        ]);

        try {
            $coach = null;
            $tempPassword = null;

            DB::transaction(function () use ($validated, &$coach, &$tempPassword) {
                
                // 1. 同步 Member 邏輯：檢查 Member 是否已存在
                $member = Member::where('email', $validated['email'])->first();

                if (!$member) {
                    // 如果 Member 不存在，創建一個新的
                    $tempPassword = Str::random(12); // 使用隨機字串作為預設密碼
                    
                    $member = Member::create([
                        'member_id' => Str::uuid(),
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                        'phone' => $validated['phone'] ?? null,
                        'password' => Hash::make($tempPassword),
                        'role' => 'COACH', // 設定角色為教練
                        'referral_code' => Str::random(10),
                    ]);
                } else {
                    // 如果 Member 已存在，確保角色正確
                    if ($member->role !== 'COACH' && $member->role !== 'ADMIN') {
                        $member->role = 'COACH';
                        $member->save();
                    }
                }

                // 2. 處理 Coach Profile (關鍵修正：優先檢查 Email 是否已存在於 coaches 表)
                $existingCoachByEmail = Coach::where('email', $validated['email'])->first();

                if ($existingCoachByEmail) {
                    // 如果該 Email 已經有 Coach Profile
                    
                    // 檢查是否已被其他 Member 綁定
                    if ($existingCoachByEmail->member_id && $existingCoachByEmail->member_id !== $member->member_id) {
                         throw new \Exception("This email is already associated with another coach record linked to a different member.");
                    }

                    // 執行 "綁定/更新" 操作
                    // *** 修正：直接設置屬性以避開 fillable 限制 ***
                    $existingCoachByEmail->member_id = $member->member_id; // 強制綁定
                    $existingCoachByEmail->name = $validated['name'];
                    if (array_key_exists('phone', $validated)) $existingCoachByEmail->phone = $validated['phone'];
                    if (array_key_exists('bio', $validated)) $existingCoachByEmail->bio = $validated['bio'];
                    if (array_key_exists('imageUrl', $validated)) $existingCoachByEmail->image_url = $validated['imageUrl'];
                    $existingCoachByEmail->is_active = $validated['is_active'];
                    
                    $existingCoachByEmail->save();
                    
                    $coach = $existingCoachByEmail;

                } else {
                    // 如果 Email 沒有對應的 Coach Profile，再檢查該 Member 是否已有其他 Coach Profile
                    $existingCoachByMember = Coach::where('member_id', $member->member_id)->first();
                    if ($existingCoachByMember) {
                         throw new \Exception("Coach profile already exists for this member (but with a different email).");
                    }

                    // 3. 創建新的 Coach Profile
                    // *** 修正：使用 new Coach() 並直接設置屬性，避開 fillable 限制 ***
                    $coach = new Coach();
                    $coach->coach_id = Str::uuid();
                    $coach->member_id = $member->member_id; // 強制綁定
                    $coach->email = $validated['email'];
                    $coach->name = $validated['name'];
                    $coach->phone = $validated['phone'] ?? null;
                    $coach->bio = $validated['bio'] ?? null;
                    $coach->image_url = $validated['imageUrl'] ?? null;
                    $coach->is_active = $validated['is_active'];
                    
                    $coach->save();
                }
                
                // 暫時將密碼掛載到物件上以便回傳
                if ($tempPassword) {
                    $coach->temp_password = $tempPassword;
                }
            });

            // 重新整理物件以確保回傳最新數據
            if ($coach) {
                $coach->refresh();
            }

            return response()->json([
                'message' => 'Coach successfully created/linked.',
                'coachId' => $coach->coach_id,
                'coach' => $coach,
                'note' => isset($coach->temp_password) ? "New member account created. Default password: {$coach->temp_password}" : "Linked to existing member account or updated existing coach profile."
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Coach creation failed: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Admin API A3.1 (U): 更新教練資訊 (同步更新 Member 帳號)
     */
    public function update(Request $request, string $coachId)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can update coaches.'], 403);
        }

        $coach = Coach::findOrFail($coachId);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:20',
            // 忽略當前教練的 email 進行 unique 檢查
            'email' => ['nullable', 'email', Rule::unique('coaches', 'email')->ignore($coach->coach_id, 'coach_id')],
            'bio' => 'nullable|string',                      
            'imageUrl' => 'nullable|string',                 
            'is_active' => 'sometimes|required|boolean',     
        ]);
        
        try {
            DB::transaction(function () use ($coach, $validated) {
                // 處理 API 請求名稱到 DB 欄位的映射
                $data = [];
                
                if (isset($validated['name'])) $data['name'] = $validated['name'];
                if (array_key_exists('phone', $validated)) $data['phone'] = $validated['phone'];
                if (array_key_exists('email', $validated)) $data['email'] = $validated['email'];
                if (array_key_exists('bio', $validated)) $data['bio'] = $validated['bio'];
                if (array_key_exists('imageUrl', $validated)) $data['image_url'] = $validated['imageUrl']; 
                if (isset($validated['is_active'])) $data['is_active'] = $validated['is_active']; 

                // 1. 更新 Coach
                $coach->update($data);

                // 2. 同步更新底層 Member 資料 (如果有关联)
                if ($coach->member_id) {
                    $member = Member::find($coach->member_id);
                    if ($member) {
                        $memberData = [];
                        if (isset($validated['name'])) $memberData['name'] = $validated['name'];
                        if (array_key_exists('email', $validated)) $memberData['email'] = $validated['email'];
                        if (array_key_exists('phone', $validated)) $memberData['phone'] = $validated['phone'];
                        
                        if (!empty($memberData)) {
                            $member->update($memberData);
                        }
                    }
                }
            });

        } catch (\Exception $e) {
            return response()->json(['message' => 'Coach update failed: ' . $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Coach successfully updated.',
            'coach' => $coach
        ], 200);
    }

    /**
     * Admin API A3.1 (D): 刪除教練
     */
    public function destroy(string $coachId)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can delete coaches.'], 403);
        }

        $coach = Coach::findOrFail($coachId);

        // *** CRITICAL FIX: 業務完整性檢查 (Check 1) ***
        // 檢查是否有課程正在使用這個教練
        $hasCourses = \App\Models\Course::where('coach_id', $coachId)->exists();

        if ($hasCourses) {
            return response()->json(['message' => 'Cannot delete coach: Coach is currently assigned to one or more courses. Please unassign the courses first.'], 400);
        }
        
        try {
            DB::transaction(function () use ($coach) {
                // 1. 刪除 Coach Profile
                $coach->delete();
                
                // 2. 恢復 Member 身份：將其角色從 COACH 降級為 MEMBER
                if ($coach->member_id) {
                    $member = Member::find($coach->member_id);
                    // 安全檢查：只有當目前角色確實是 'COACH' 時才降級
                    // 這樣可以防止意外將 'ADMIN' 降級
                    if ($member && $member->role === 'COACH') {
                        $member->role = 'MEMBER';
                        $member->save();
                    }
                }
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Coach deletion failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Coach successfully deleted.'], 200);
    }
}