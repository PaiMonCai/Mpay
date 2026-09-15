<?php

declare(strict_types=1);

namespace app\common\sdk\ysepay;

use RuntimeException;
use Throwable;

/**
 * 银盛 SDK 异常。
 */
class YsepaySdkException extends RuntimeException
{
    /**
     * 构造银盛 SDK 异常。
     */
    public function __construct(
        string $message,
        private readonly bool $uncertain = false,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 请求是否可能已被渠道受理。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}
