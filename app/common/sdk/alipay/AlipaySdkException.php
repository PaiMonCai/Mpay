<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

use RuntimeException;
use Throwable;

/**
 * 支付宝轻量 SDK 异常。
 *
 * SDK 内部配置缺失、签名失败、证书解析失败、HTTP 请求失败、返回验签失败等场景统一抛出该异常。
 * 后续支付插件接入时可以捕获该异常，并转换为项目统一的 PaymentException。
 */
class AlipaySdkException extends RuntimeException
{
    /**
     * 创建支付宝 SDK 异常。
     *
     * @param string $message 异常消息
     * @param string $category 异常类别
     * @param array<string, scalar|null> $context 脱敏诊断上下文
     * @param Throwable|null $previous 前置异常
     */
    public function __construct(
        string $message,
        private readonly string $category = 'sdk',
        private readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 获取异常类别。
     *
     * @return string 异常类别
     */
    public function category(): string
    {
        return $this->category;
    }

    /**
     * 获取脱敏诊断上下文。
     *
     * @return array<string, scalar|null> 诊断上下文
     */
    public function context(): array
    {
        return $this->context;
    }
}
