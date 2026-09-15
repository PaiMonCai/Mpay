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
 * 易生收款啦二维码牌直连流水监听插件。
 *
 * 与 yisheng_receipt 浏览器版独立配置，并按精确账号键隔离 Redis Session。Go watcher 使用
 * 易生商户服务平台 HTTP 接口直连完成验证码登录和流水查询；浏览器版继续作为独立可选实现。
 */
class YishengDirectReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * 当前平台流水未发现稳定付款备注字段，后台配置仅开放金额变动模式。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'yisheng_direct_receipt',
        'name' => '易生收款啦直连码牌收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '0.1.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_watcher' => [
            'runtime' => 'direct',
            'prelogin_supported' => true,
        ],
        'receipt_supports_remark' => false,
    ];
}
