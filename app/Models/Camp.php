<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Camp extends Model
{
    use HasFactory;

    protected $table = 'camps'; // 【修正】使用複數表名 'camps'
    protected $primaryKey = 'camp_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'camp_id',
        'name',
        'description',
        'is_active',
        
        'coach_id',
        'classroom_id',
        'price',
        'max_capacity',
        'current_bookings',
        
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        
        'cancellation_policy',
        'registration_start_date',
        'registration_end_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'max_capacity' => 'integer',
        'current_bookings' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'registration_start_date' => 'datetime',
        'registration_end_date' => 'datetime',
        'cancellation_policy' => 'json',
    ];
    
    // --- 關聯 (用於衝突檢查) ---
    
    public function coach()
    {
        return $this->belongsTo(Coach::class, 'coach_id', 'coach_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'classroom_id', 'classroom_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'camp_id', 'camp_id');
    }
}