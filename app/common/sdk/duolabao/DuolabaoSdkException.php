<?php

declare(strict_types=1);

namespace app\common\sdk\duolabao;

use RuntimeException;
use Throwable;

/**
 * 哆啦宝 SDK 异常。
 */
class DuolabaoSdkException extends RuntimeException
{
    /**
     * 构造哆啦宝 SDK 异常。
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
     * 判断请求结果是否可能无法确认。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }

    /**
     * 获取不含敏感报文的渠道错误码。
     */
    public function channelErrorCode(): string
    {
        return $this->channelErrorCode;
    }
}
