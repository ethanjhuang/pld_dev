<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipCard extends Model
{
    use HasFactory;

    protected $table = 'membership_cards'; // 確保表名正確
    protected $primaryKey = 'card_id';
    public $incrementing = false;
    protected $keyType = 'string';

    // *** CRITICAL FIX: 確保所有 Admin Point Controller 會寫入的屬性都加入 $fillable ***
    protected $fillable = [
        'card_id',
        'member_id',
        'total_points',    // [FIX] 必須加入，否則 total_points 創建時為 0
        'remaining_points', 
        'locked_points',
        'purchase_amount',
        'card_status',     // 必須加入
        'expiry_date',     // 必須加入
    ];

    protected $casts = [
        'total_points' => 'decimal:2', // 確保點數格式化
        'remaining_points' => 'decimal:2',
        'locked_points' => 'decimal:2',
        'purchase_amount' => 'decimal:2',
        'expiry_date' => 'datetime',
    ];

    /**
     * 關聯到會員 (Member)
     */
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id', 'member_id');
    }
}