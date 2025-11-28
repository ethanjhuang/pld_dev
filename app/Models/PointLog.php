<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointLog extends Model
{
    use HasFactory;

    // 數據庫配置
    protected $table = 'point_logs';
    protected $primaryKey = 'log_id';
    public $incrementing = false;
    protected $keyType = 'string';

    // 允許批量賦值欄位
    protected $fillable = [
        'log_id', 
        'membership_id', 
        'change_amount', 
        'change_type', // 例如: SYSTEM_CANCEL_REFUND, BOOKING_DEDUCT
        'related_id',
    ];

    // 類型轉換
    protected $casts = [
        'change_amount' => 'decimal:2',
    ];

    // 關聯定義
    public function membershipCard()
    {
        return $this->belongsTo(MembershipCard::class, 'membership_id', 'card_id');
    }
}