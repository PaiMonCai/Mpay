<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\trait\WebReceiptPaymentTrait;

/**
 * 易宝老板管账二维码牌直连流水监听插件。
 *
 * 与 yeepay_boss_receipt 浏览器版独立配置，并按精确账号键隔离 Redis Session。Go watcher 使用
 * 易宝老板管账 HTTP 接口直连完成滑块登录和流水查询；浏览器版继续作为独立可选实现。
 */
class YeepayBossDirectReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * 当前平台码牌流水对账备注为空，后台配置仅开放金额变动模式。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'yeepay_boss_direct_receipt',
        'name' => '易宝老板管账直连码牌收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
        'receipt_watcher' => [
            'runtime' => 'direct',
            'prelogin_supported' => true,
        ],
        'receipt_supports_remark' => false,
    ];
}
