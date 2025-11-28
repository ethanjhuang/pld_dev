<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Camp extends Model
{
    use HasFactory;

    protected $table = 'camps';
    protected $primaryKey = 'camp_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'camp_id',
        'name',
        'coach_id',
        'classroom_id',
        'price_points',
        'total_sessions',
        'registration_start_date',
        'registration_end_date',
        'cancellation_rules_json',
        'max_capacity',
        'current_bookings',
    ];

    protected $casts = [
        'registration_start_date' => 'datetime',
        'registration_end_date' => 'datetime',
        'cancellation_rules_json' => 'json',
        'price_points' => 'decimal:2',
    ];
}