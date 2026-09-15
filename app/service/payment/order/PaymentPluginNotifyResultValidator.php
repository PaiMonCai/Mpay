<?php

declare(strict_types=1);

namespace app\service\payment\order;

use support\validation\Validator;

/**
 * 插件支付通知结果验证器。
 */
class PaymentPluginNotifyResultValidator extends Validator
{
    protected array $rules = [
        'status' => 'required|string|in:success,failed,pending',
        'pay_no' => 'required|string|max:64',
        'paid_amount' => 'required_if:status,success|nullable|integer|min:0',
        'message' => 'nullable|string',
        'chan_order_no' => 'nullable|string|max:64',
        'chan_trade_no' => 'nullable|string|max:64',
        'channel_status' => 'nullable|string|max:128',
        'channel_error_code' => 'nullable|string|max:64',
        'channel_error_msg' => 'nullable|string',
        'paid_at' => 'nullable',
        'failed_at' => 'nullable',
    ];

    protected array $attributes = [
        'status' => '支付状态',
        'pay_no' => '支付单号',
        'paid_amount' => '实付金额',
        'message' => '通知说明',
        'chan_order_no' => '渠道订单号',
        'chan_trade_no' => '渠道交易号',
        'channel_status' => '渠道状态',
        'channel_error_code' => '渠道错误码',
        'channel_error_msg' => '渠道错误信息',
        'paid_at' => '支付成功时间',
        'failed_at' => '支付失败时间',
    ];

    protected array $scenes = [
        'notify_result' => [
            'status',
            'pay_no',
            'paid_amount',
            'message',
            'chan_order_no',
            'chan_trade_no',
            'channel_status',
            'channel_error_code',
            'channel_error_msg',
            'paid_at',
            'failed_at',
        ],
    ];
}
