<?php

declare(strict_types=1);

namespace app\common\sdk\easypay;

use RuntimeException;

/**
 * 易生 SDK 异常。
 */
class EasypaySdkException extends RuntimeException
{
    /**
     * 构造易生 SDK 异常。
     */
    public function __construct(
        string $message,
        private readonly bool $uncertain = false,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 请求是否可能已被渠道受理但当前无法确认结果。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}
