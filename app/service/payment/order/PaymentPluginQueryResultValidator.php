<?php

declare(strict_types=1);

namespace app\service\payment\order;

use support\validation\Validator;

/**
 * 插件支付查询结果验证器。
 */
class PaymentPluginQueryResultValidator extends Validator
{
    protected array $rules = [
        'status' => 'required|string|in:success,failed,closed,pending,unknown',
        'pay_no' => 'required|string|max:64',
        'paid_amount' => 'required_if:status,success|nullable|integer|min:0',
        'chan_order_no' => 'nullable|string|max:64',
        'chan_trade_no' => 'nullable|string|max:64',
        'channel_status' => 'nullable|string|max:128',
        'message' => 'nullable|string',
        'channel_error_code' => 'nullable|string|max:64',
        'channel_error_msg' => 'nullable|string',
        'paid_at' => 'nullable',
        'failed_at' => 'nullable',
    ];

    protected array $scenes = [
        'query_result' => [
            'status',
            'pay_no',
            'paid_amount',
            'chan_order_no',
            'chan_trade_no',
            'channel_status',
            'message',
            'channel_error_code',
            'channel_error_msg',
            'paid_at',
            'failed_at',
        ],
    ];
}
