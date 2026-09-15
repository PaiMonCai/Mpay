<?php

declare(strict_types=1);

namespace app\common\interface;

use support\Request;
use support\Response;

/**
 * 插件可选的退款异步通知能力。
 */
interface RefundNotifyInterface
{
    /**
     * 解析并校验退款通知。
     *
     * @param Request $request 渠道通知请求
     * @param array<string, mixed> $refund 标准退款单及原支付单参数
     * @return array<string, mixed> 标准退款状态结果
     */
    public function refundNotify(Request $request, array $refund): array;

    /**
     * 返回渠道要求的退款通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function refundNotifySuccess(): string|Response;

    /**
     * 返回渠道要求的退款通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function refundNotifyFail(): string|Response;
}
