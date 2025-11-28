<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'bookings';
    protected $primaryKey = 'booking_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'booking_id',
        'member_id',
        'course_id',
        'child_id',
        'status',
        'points_deducted',
        'is_paid',
        'guest_child_name',
        'waiting_list_rank',
        'cancellation_time',
    ];

    protected $casts = [
        'points_deducted' => 'decimal:2',
        'is_paid' => 'boolean',
        'cancellation_time' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(Member::class, 'user_id', 'member_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id', 'course_id');
    }

    public function child()
    {
        return $this->belongsTo(Child::class, 'child_id', 'child_id');
    }
}