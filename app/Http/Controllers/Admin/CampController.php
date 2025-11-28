<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Camp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon; // 【新增】引入 Carbon 類別

class CampController extends Controller
{
    /**
     * R: 查詢營隊列表 (Admin A3.4 R)
     */
    public function index(Request $request)
    {
        $camps = Camp::orderBy('start_date', 'desc')->paginate(20);
        
        return response()->json($camps);
    }

    /**
     * C: 創建新營隊 (Admin A3.4 C)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i:s', 
            'end_time' => 'nullable|date_format:H:i:s|after:start_time',
            'price' => 'required|numeric|min:0', 
            'capacity' => 'required|integer|min:1',
            'is_active' => 'required|boolean',
            'coachId' => 'required|uuid|exists:coach,coach_id', // 保持單數兼容
            'classroomId' => 'required|uuid|exists:classroom,classroom_id', // 保持單數兼容
            'cancellationPolicy' => 'nullable|json',
        ]);

        $camp = Camp::create([
            'camp_id' => Str::uuid(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
            'price' => $validated['price'],
            'max_capacity' => $validated['capacity'],
            'is_active' => $validated['is_active'],
            'coach_id' => $validated['coachId'],
            'classroom_id' => $validated['classroomId'],
            'cancellation_policy' => $validated['cancellationPolicy'] ?? null,
        ]);

        return response()->json([
            'message' => 'Camp successfully created.',
            'campId' => $camp->camp_id,
        ], 201);
    }

    /**
     * U: 更新營隊資訊 (Admin A3.4 U)
     */
    public function update(Request $request, string $campId)
    {
        $camp = Camp::findOrFail($campId);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'coachId' => 'sometimes|required|uuid|exists:coach,coach_id', 
            'classroomId' => 'sometimes|required|uuid|exists:classroom,classroom_id', 
            // ... (其他驗證)
        ]);
        
        // 確保不會更新 ID
        $updateData = collect($validated)->except(['coachId', 'classroomId'])->toArray();
        if (isset($validated['coachId'])) { $updateData['coach_id'] = $validated['coachId']; }
        if (isset($validated['classroomId'])) { $updateData['classroom_id'] = $validated['classroomId']; }

        $camp->update($updateData);

        return response()->json([
            'message' => 'Camp successfully updated.',
        ], 200);
    }

    /**
     * D: 刪除營隊 (Admin A3.4 D)
     */
    public function destroy(string $campId)
    {
        $camp = Camp::findOrFail($campId);
        
        if ($camp->bookings()->exists()) {
             return response()->json(['message' => 'Camp has existing bookings and cannot be deleted.'], 409);
        }
        
        $camp->delete();

        return response()->json(['message' => 'Camp successfully deleted.'], 200);
    }

    // --- 【補齊】Public 查詢 (API 22) ---
    
    /**
     * API 22: 查詢營隊公開列表
     * 路由: GET /api/camp/list
     */
    public function getPublicList(Request $request)
    {
        $perPage = $request->get('perPage', 10);
        
        // 篩選條件：
        // 1. 必須是 active (is_active = true)
        // 2. 必須是未來或當前營隊 (end_date >= 當前日期)
        $camps = Camp::where('is_active', true)
                     ->where('end_date', '>=', Carbon::today())
                     ->orderBy('start_date', 'asc')
                     ->paginate($perPage);

        return response()->json($camps);
    }
}