<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Child extends Model
{
    use HasFactory;

    protected $table = 'children';
    protected $primaryKey = 'child_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'child_id',
        'parent_id',
        'name',
        'birth_date',
        'current_level',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function parent()
    {
        // 關聯到家長
        return $this->belongsTo(Member::class, 'parent_id', 'member_id');
    }
}