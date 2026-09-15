<?php

declare(strict_types=1);

namespace app\service\payment\order;

use support\validation\Validator;

/**
 * 插件关单结果验证器。
 */
class PaymentPluginCloseResultValidator extends Validator
{
    protected array $rules = [
        'status' => 'required|string|in:closed,pending,unknown',
        'pay_no' => 'required|string|max:64',
        'chan_order_no' => 'nullable|string|max:64',
        'chan_trade_no' => 'nullable|string|max:64',
        'channel_status' => 'nullable|string|max:128',
        'message' => 'nullable|string',
    ];

    protected array $scenes = [
        'close_result' => [
            'status',
            'pay_no',
            'chan_order_no',
            'chan_trade_no',
            'channel_status',
            'message',
        ],
    ];
}
