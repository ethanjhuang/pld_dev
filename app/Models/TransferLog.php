<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferLog extends Model
{
    use HasFactory;

    protected $table = 'transfer_logs';
    protected $primaryKey = 'log_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'log_id',
        'sender_id',
        'recipient_id',
        'amount',
        'status', 
        'expiry_time', // <--- 修正 1: 必須允許此欄位被賦值
        'cancellation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expiry_time' => 'datetime', // <--- 修正 2: 必須將其轉換為 Carbon 物件
    ];
    
    public function sender()
    {
        return $this->belongsTo(Member::class, 'sender_id', 'member_id');
    }
    
    public function recipient()
    {
        return $this->belongsTo(Member::class, 'recipient_id', 'member_id');
    }
}