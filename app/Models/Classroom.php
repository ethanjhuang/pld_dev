<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    use HasFactory;

    protected $table = 'classrooms';
    protected $primaryKey = 'classroom_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'classroom_id',
        'name',
        'address',
        'max_capacity',
        'description',
        'image_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}