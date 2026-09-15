<?php

declare(strict_types=1);

namespace app\common\sdk\jeepay;

use RuntimeException;
use Throwable;

/**
 * Jeepay SDK 异常。
 */
class JeepaySdkException extends RuntimeException
{
    /**
     * 构造 Jeepay SDK 异常。
     */
    public function __construct(
        string $message,
        private readonly bool $uncertain = false,
        private readonly string $channelErrorCode = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 请求是否可能已被渠道受理。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }

    /**
     * 获取渠道错误码。
     */
    public function channelErrorCode(): string
    {
        return $this->channelErrorCode;
    }
}
