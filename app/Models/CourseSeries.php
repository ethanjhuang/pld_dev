<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseSeries extends Model
{
    use HasFactory;

    protected $table = 'course_series';
    protected $primaryKey = 'series_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'series_id',
        'name',
        'recurrence_pattern',
    ];
    
    protected $casts = [
        'recurrence_pattern' => 'json', // 儲存為 JSON 格式
    ];
}