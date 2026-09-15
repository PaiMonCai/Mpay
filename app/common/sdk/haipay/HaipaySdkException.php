<?php

declare(strict_types=1);

namespace app\common\sdk\haipay;

use RuntimeException;
use Throwable;

/**
 * 海科融通 SDK 异常。
 */
class HaipaySdkException extends RuntimeException
{
    /**
     * 构造海科融通 SDK 异常。
     */
    public function __construct(
        string $message,
        private readonly bool $uncertain,
        private readonly string $channelCode = '',
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
     * 获取不含报文与凭证的渠道错误码。
     */
    public function channelCode(): string
    {
        return $this->channelCode;
    }
}
