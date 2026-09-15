<?php

declare(strict_types=1);

namespace app\common\sdk\zhangyishou;

use RuntimeException;
use Throwable;

/**
 * 掌易收 SDK 异常。
 */
class ZhangyishouSdkException extends RuntimeException
{
    /**
     * 构造掌易收 SDK 异常。
     */
    public function __construct(
        string $message,
        private readonly bool $uncertain = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 判断上游是否可能已经受理本次变更请求。
     */
    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}
