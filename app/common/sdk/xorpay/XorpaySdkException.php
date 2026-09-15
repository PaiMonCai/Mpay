<?php

declare(strict_types=1);

namespace app\common\sdk\xorpay;

use RuntimeException;

/**
 * XorPay SDK 异常。
 */
class XorpaySdkException extends RuntimeException
{
    /**
     * 构造 XorPay SDK 异常。
     *
     * @param bool $uncertain 变更请求是否可能已被上游受理
     * @param string $channelStatus XorPay 正文状态码
     */
    public function __construct(
        string $message,
        private readonly bool $uncertain = false,
        private readonly string $channelStatus = ''
    ) {
        parent::__construct($message);
    }

    /**
     * 判断变更请求结果是否无法确认。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }

    /**
     * 获取 XorPay 原始状态码。
     */
    public function channelStatus(): string
    {
        return $this->channelStatus;
    }
}
