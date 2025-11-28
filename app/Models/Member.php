<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable; 
use Illuminate\Foundation\Auth\Access\Authorizable;
use Laravel\Sanctum\HasApiTokens; 
use Illuminate\Database\Eloquent\Relations\HasOne; 

class Member extends Authenticatable
{
    // 使用 Authorizable (用於 can() 授權檢查) 和 HasApiTokens (用於 Sanctum)
    use HasFactory, HasApiTokens, Authorizable; 
    
    protected $table = 'members';
    protected $primaryKey = 'member_id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    // 啟用 Laravel 預設的時間戳欄位
    public $timestamps = true; 

    protected $fillable = [
        'member_id', 
        'wp_user_id', 
        'line_id', 
        'referral_code', 
        'name', 
        'phone', 
        'email', 
        'role',
        'password'
    ];

    /**
     * 關聯：一個 Member (如果他是教練) 擁有一個 Coach 紀錄。
     * 用途：教練點名時，確認該 Member 是否關聯到課程指定的 Coach ID。
     * * 關聯邏輯：Coaches table 必須有一個 member_id 欄位對應到這裡的 member_id。
     */
    public function coach(): HasOne
    {
        return $this->hasOne(Coach::class, 'member_id', 'member_id');
    }

    /**
     * 關聯：一個 Member 擁有一張 MembershipCard。
     * 用途：查詢點數、扣點、儲值。
     */
    public function membershipCard(): HasOne
    {
        return $this->hasOne(MembershipCard::class, 'member_id', 'member_id');
    }
}