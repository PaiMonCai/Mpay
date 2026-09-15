<?php

declare(strict_types=1);

namespace app\common\sdk\xunhupay;

use RuntimeException;
use Throwable;

/**
 * 虎皮椒 SDK 异常。
 */
class XunhupaySdkException extends RuntimeException
{
    /**
     * 构造虎皮椒 SDK 异常。
     */
    public function __construct(
        string $message,
        private readonly bool $definitive = false,
        private readonly string $channelErrorCode = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 判断上游是否已经明确拒绝请求。
     */
    public function isDefinitive(): bool
    {
        return $this->definitive;
    }

    /**
     * 获取不含报文内容的上游错误码。
     */
    public function channelErrorCode(): string
    {
        return $this->channelErrorCode;
    }
}
