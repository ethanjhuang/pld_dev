<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClassroomController extends Controller
{
    /**
     * Admin API A3.2 (R): 查詢教室列表
     */
    public function index(Request $request)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can view classroom list.'], 403);
        }

        $perPage = $request->get('perPage', 20);
        $search = $request->get('search'); // 允許按名稱或地址搜索

        $query = Classroom::query();

        if ($search) {
            // PostgreSQL often uses ILIKE for case-insensitive search
            $query->where('name', 'ILIKE', '%' . $search . '%')
                  ->orWhere('address', 'ILIKE', '%' . $search . '%');
        }

        // 預設按名稱排序
        $classrooms = $query->orderBy('name')->paginate($perPage);

        return response()->json($classrooms);
    }

    /**
     * Admin API A3.2 (C): 創建教室紀錄
     */
    public function store(Request $request)
    {
        // 確保只有 Admin 可以訪問 (已在路由中配置)
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can create classrooms.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:classrooms,name',
            'address' => 'required|string|max:255', 
            'max_capacity' => 'required|integer|min:1', 
            'description' => 'nullable|string',
            'imageUrl' => 'nullable|string', // 映射到 image_url 欄位
        ]);

        $classroom = Classroom::create([
            'classroom_id' => Str::uuid(),
            'name' => $validated['name'],
            'address' => $validated['address'], 
            'max_capacity' => $validated['max_capacity'], 
            
            'description' => $validated['description'] ?? null,
            'image_url' => $validated['imageUrl'] ?? null, 
            'is_active' => true, // 預設為 true (假設數據庫有預設值，這裡也顯式設定)
        ]);

        return response()->json([
            'message' => 'Classroom created successfully.',
            'classroomId' => $classroom->classroom_id,
            'classroom' => $classroom, // 返回完整物件方便後續測試
        ], 201);
    }
    
    /**
     * Admin API A3.2 (U): 更新教室資訊
     */
    public function update(Request $request, string $classroomId)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can update classrooms.'], 403);
        }

        $classroom = Classroom::findOrFail($classroomId);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('classrooms', 'name')->ignore($classroom->classroom_id, 'classroom_id')],
            'address' => 'sometimes|required|string|max:255', 
            'max_capacity' => 'sometimes|required|integer|min:1', 
            'description' => 'nullable|string',
            'imageUrl' => 'nullable|string', // 映射到 image_url 欄位
            'is_active' => 'sometimes|required|boolean', // 允許更新狀態
        ]);

        // 處理 API 請求名稱到 DB 欄位的映射 (避免 $validated 中沒有的鍵影響 update)
        $data = $validated;
        if (isset($data['imageUrl'])) {
            $data['image_url'] = $data['imageUrl']; // 映射 image_url
            unset($data['imageUrl']);
        }
        
        $classroom->update($data);

        return response()->json([
            'message' => 'Classroom successfully updated.',
            'classroom' => $classroom
        ], 200);
    }

    /**
     * Admin API A3.2 (D): 刪除教室
     */
    public function destroy(string $classroomId)
    {
        // 確保只有管理員可以執行此操作
        if (!auth()->user()->can('is-admin')) {
            return response()->json(['message' => 'Forbidden: Only administrators can delete classrooms.'], 403);
        }

        $classroom = Classroom::findOrFail($classroomId);

        // *** CRITICAL FIX: 業務完整性檢查 (Check 1) ***
        // 檢查是否有課程正在使用這個教室
        $hasCourses = Course::where('classroom_id', $classroomId)->exists();

        if ($hasCourses) {
            // 由於教室已被排課，拒絕刪除
            return response()->json(['message' => 'Cannot delete classroom: It is currently assigned to one or more courses. Please unassign the courses first.'], 400);
        }
        
        $classroom->delete();

        return response()->json(['message' => 'Classroom successfully deleted.'], 200);
    }
}