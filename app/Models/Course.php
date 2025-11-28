<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    // 1. 數據庫配置
    protected $table = 'courses';
    protected $primaryKey = 'course_id';
    public $incrementing = false;
    protected $keyType = 'string';

    // 2. 可批量賦值欄位 (對應 Migration 中的欄位)
    protected $fillable = [
        'course_id', 
        'coach_id', 
        'classroom_id', 
        'series_id', 
        'camp_id', // V1.1 預留欄位
        'name', 
        'start_time', 
        'end_time', 
        'max_capacity', 
        'min_capacity', 
        'required_points', 
        'min_child_level',
        'is_active',
        'current_bookings', // 允許在 seeder 或特殊情況下賦值
        'is_confirmed',
    ];

    // 3. 類型轉換 (確保時間欄位是 Carbon 實例)
    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_active' => 'boolean',
        'is_confirmed' => 'boolean',
        'required_points' => 'decimal:2',
    ];

    // 4. 關聯定義 (方便控制器使用 checkConflicts 查詢)
    public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id', 'coach_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'classroom_id', 'classroom_id');
    }
}