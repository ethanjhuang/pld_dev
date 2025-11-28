<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemConfig extends Model
{
    // 1. 告訴 Laravel 這是正確的表格名稱 (因為 Migration 檔案中我們使用了複數 system_configs)
    protected $table = 'system_configs'; 

    // 2. 核心修正：告訴 Laravel 主鍵不是 'id'，而是 'key_name'
    protected $primaryKey = 'key_name';

    // 3. 核心修正：告訴 Laravel 主鍵不是自增長的
    public $incrementing = false;

    // 4. 允許 mass assignment (批量賦值)
    protected $fillable = [
        'key_name', 
        'value', 
        'description',
    ];
}