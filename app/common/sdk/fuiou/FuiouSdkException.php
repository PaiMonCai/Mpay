<?php

declare(strict_types=1);

namespace app\common\sdk\fuiou;

use RuntimeException;
use Throwable;

/**
 * 富友 SDK 异常。
 */
class FuiouSdkException extends RuntimeException
{
    /**
     * 保存已经解析和验签的渠道响应。
     *
     * @param array<string, mixed> $response 已通过 XML 解析和验签的富友响应
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly array $response = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取渠道异常响应。
     *
     * @return array<string, mixed>
     */
    public function response(): array
    {
        return $this->response;
    }

    /**
     * 获取渠道响应码。
     *
     * @return string 渠道响应码
     */
    public function resultCode(): string
    {
        return trim((string) ($this->response['result_code'] ?? ''));
    }
}
