<?php

declare(strict_types=1);

namespace app\common\sdk\fubei;

use RuntimeException;
use Throwable;

/**
 * 付呗轻量 SDK 异常。
 */
class FubeiSdkException extends RuntimeException
{
    /**
     * 不含密钥、签名和完整原始报文的诊断信息。
     *
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * 保存可安全记录的渠道异常上下文。
     *
     * @param string $message 错误消息
     * @param int $code 错误码
     * @param Throwable|null $previous 前序异常
     * @param array<string, mixed> $data 脱敏诊断信息
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    /**
     * 获取渠道异常上下文。
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}
