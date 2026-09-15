<?php

declare(strict_types=1);

namespace app\service\payment\order;

use support\validation\Validator;

/**
 * 插件支付结果验证器。
 */
class PaymentPluginPayResultValidator extends Validator
{
    protected array $rules = [
        'status' => 'required|string|in:pending,success',
        'pay_no' => 'required|string|max:64',
        'paid_amount' => 'required_if:status,success|nullable|integer|min:0',
        'chan_order_no' => 'nullable|string|max:64',
        'chan_trade_no' => 'nullable|string|max:64',
        'pay_type' => 'required|string|max:32',
        'pay_product' => 'required|string|max:64',
        'pay_action' => 'nullable|string|max:64',
        'channel_context' => 'nullable|array',
        'presentation' => 'required_if:status,pending|nullable|array',
        'presentation.pay_page' => 'required_if:status,pending|nullable|string|in:qrcode,html,jump,jsapi,urlscheme,page|max:32',
        'presentation.pay_type' => 'required_if:status,pending|nullable|string|max:32',
        'presentation.pay_product' => 'required_if:status,pending|nullable|string|max:64',
        'presentation.pay_action' => 'required_if:status,pending|nullable|string|max:64',
        'presentation.pay_params' => 'required_if:status,pending|nullable|array',
    ];

    protected array $attributes = [
        'status' => '支付状态',
        'pay_no' => '支付单号',
        'paid_amount' => '实付金额',
        'chan_order_no' => '渠道订单号',
        'chan_trade_no' => '渠道交易号',
        'pay_type' => '支付方式',
        'pay_product' => '渠道支付产品',
        'pay_action' => '渠道支付动作',
        'channel_context' => '渠道后续操作上下文',
        'presentation' => '收银台承接信息',
        'presentation.pay_page' => '承接页类型',
        'presentation.pay_type' => '支付方式',
        'presentation.pay_product' => '支付产品',
        'presentation.pay_action' => '支付动作',
        'presentation.pay_params' => '支付参数',
    ];

    protected array $scenes = [
        'pay_result' => [
            'status',
            'pay_no',
            'paid_amount',
            'chan_order_no',
            'chan_trade_no',
            'pay_type',
            'pay_product',
            'pay_action',
            'channel_context',
            'presentation',
            'presentation.pay_page',
            'presentation.pay_type',
            'presentation.pay_product',
            'presentation.pay_action',
            'presentation.pay_params',
        ],
    ];
}
