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
 * 星驿付收款单直连流水监听插件。
 *
 * 与 postar_receipt 共用网页码牌收款匹配规则；Go watcher 使用主站
 * 加密接口和 yhk 子系统接口直连获取流水，不依赖浏览器 iframe 建立登录态。
 */
class PostarDirectReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'postar_direct_receipt',
        'name' => '星驿付直连收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_watcher' => [
            'runtime' => 'direct',
            'prelogin_supported' => true,
        ],
        'receipt_supports_remark' => true,
    ];
}
