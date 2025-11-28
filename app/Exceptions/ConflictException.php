<?php

namespace App\Exceptions;

use Exception;

/**
 * 用於處理預約時的時間/名額衝突。
 * 當 API 7 偵測到衝突時，拋出此例外，
 * 允許前端接收 conflicts 列表並決定是否使用 forceOverride 覆蓋。
 */
class ConflictException extends Exception
{
    protected $conflicts;

    public function __construct(string $message = "", array $conflicts = [], int $code = 409, ?Throwable $previous = null)
    {
        $this->conflicts = $conflicts;
        parent::__construct($message, $code, $previous);
    }

    /**
     * 獲取所有衝突的 Booking ID 列表
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * 渲染例外響應 (供 Laravel 處理)
     */
    public function render($request)
    {
        return response()->json([
            'message' => $this->message,
            'conflicts' => $this->conflicts,
        ], $this->code);
    }
}