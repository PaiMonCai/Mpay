<?php

declare(strict_types=1);

namespace app\common\sdk\adapay;

use RuntimeException;
use Throwable;

/**
 * AdaPay SDK 异常。
 */
class AdapaySdkException extends RuntimeException
{
    /**
     * 构造 AdaPay SDK 异常。
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
     * 判断变更请求是否可能已经被上游受理。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }

    /**
     * 获取不含敏感报文的上游错误码。
     */
    public function channelErrorCode(): string
    {
        return $this->channelErrorCode;
    }
}
