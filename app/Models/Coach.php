<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coach extends Model
{
    use HasFactory;

    protected $table = 'coaches';
    protected $primaryKey = 'coach_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'coach_id',
        'name',
        'phone',     // [FIX] 必須加入
        'email',     // [FIX] 必須加入
        'bio',
        'image_url',
        'is_active',
    ];


    protected $casts = [
        'is_active' => 'boolean',
    ];
}