<?php

declare(strict_types=1);

namespace app\common\interface;

use support\Request;
use support\Response;

/**
 * 支付插件基础协议。
 *
 * 插件负责上游协议适配，核心服务只消费本接口约定的标准字段。渠道不支持某项
 * 操作时仍需实现对应方法，并抛出 UnsupportedPaymentOperationException。
 */
interface PaymentInterface
{
    /**
     * 创建渠道支付单。
     *
     * 返回 status、pay_no、paid_amount、chan_order_no、chan_trade_no；待支付时还需
     * 返回 presentation。status 只描述交易事实，presentation 只描述收银台承接方式。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array;

    /**
     * 查询渠道支付状态。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @return array<string, mixed> 标准查单结果
     */
    public function query(array $order): array;

    /**
     * 关闭渠道支付单。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array;

    /**
     * 请求渠道退款。
     *
     * @param array<string, mixed> $order 标准退款参数
     * @return array<string, mixed> 标准退款请求结果
     */
    public function refund(array $order): array;

    /**
     * 验签并解析渠道支付通知。
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array;

    /**
     * 返回渠道要求的支付通知成功应答。
     */
    public function notifySuccess(): string|Response;

    /**
     * 返回渠道要求的支付通知失败应答。
     */
    public function notifyFail(): string|Response;
}

