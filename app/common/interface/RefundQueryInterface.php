<?php

declare(strict_types=1);

namespace app\common\interface;

/**
 * 插件可选的退款主动查询能力。
 */
interface RefundQueryInterface
{
    /**
     * 查询退款状态。
     *
     * @param array<string, mixed> $refund 标准退款单及原支付单参数
     * @return array<string, mixed> 标准退款状态结果
     */
    public function queryRefund(array $refund): array;
}
