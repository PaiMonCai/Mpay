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
 * 旺铺二维码牌直连流水监听插件。
 *
 * 该插件复用码牌通用配置表单，Go watcher 通过 HTTP Session 直连
 * 旺铺商户后台接口查询流水；浏览器版 wangpu_receipt 作为独立可选实现继续保留。
 */
class WangpuDirectReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'wangpu_direct_receipt',
        'name' => '旺铺直连码牌收款',
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
