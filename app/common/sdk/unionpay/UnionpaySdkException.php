<?php

declare(strict_types=1);

namespace app\common\sdk\unionpay;

use RuntimeException;
use Throwable;

/**
 * 银联前置 SDK 异常。
 */
class UnionpaySdkException extends RuntimeException
{
    /**
     * 构造银联前置 SDK 异常。
     */
    public function __construct(
        string $message,
        private readonly bool $uncertain = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 判断请求结果是否无法确认。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}
