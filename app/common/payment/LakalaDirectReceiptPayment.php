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
 * 拉卡拉二维码牌直连流水监听插件。
 *
 * 与 lakala_receipt 独立配置、按精确账号键隔离 Redis Session。Go watcher 使用拉卡拉
 * 商户工作台私有 JSON 接口直连完成滑块登录和流水查询，不打开浏览器页面。
 */
class LakalaDirectReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'lakala_direct_receipt',
        'name' => '拉卡拉直连码牌收款',
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
