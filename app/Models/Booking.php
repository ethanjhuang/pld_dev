<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'bookings'; // 【修正】使用複數表名 'bookings'
    protected $primaryKey = 'booking_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'booking_id',
        'member_id',
        'course_id',
        'camp_id', // V1.7 Camp 預約關聯
        'transaction_id', // V1.7 Camp 金流交易關聯
        'child_id',
        'guest_name', // V1.7 統一的訪客名稱
        'guest_age', // V1.7 統一的訪客年齡
        'status',
        'is_member_participant',
        'attendance_time',
        
        // --- 合併舊版/課程預約所需欄位 ---
        'points_deducted', // 舊版課程扣點數額
        'is_paid', // 舊版/歷史支付狀態標記
        'guest_child_name', // 舊版訪客名稱 (暫時保留，但建議統一使用 guest_name)
        'waiting_list_rank', // 候補排名
        'cancellation_time', // 取消時間紀錄
    ];

    protected $casts = [
        'is_member_participant' => 'boolean',
        'attendance_time' => 'datetime',
        
        // --- 合併舊版/課程預約 Casts ---
        'points_deducted' => 'decimal:2',
        'is_paid' => 'boolean',
        'cancellation_time' => 'datetime',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }
    
    // Camp 關聯
    public function camp()
    {
        return $this->belongsTo(Camp::class, 'camp_id', 'camp_id');
    }

    // Transaction 關聯
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id', 'member_id');
    }

    public function child()
    {
        return $this->belongsTo(Child::class, 'child_id', 'child_id');
    }
}