<?php

declare(strict_types=1);

namespace app\service\payment\order;

use support\validation\Validator;

/**
 * 插件退款结果验证器。
 */
class PaymentPluginRefundResultValidator extends Validator
{
    protected array $rules = [
        'status' => 'required|string',
        'refund_no' => 'required|string|max:64',
        'pay_no' => 'required|string|max:64',
        'refund_amount' => 'required|integer|min:1',
        'chan_refund_no' => 'nullable|string|max:128',
        'channel_status' => 'nullable|string|max:128',
        'message' => 'nullable|string',
    ];

    protected array $scenes = [
        'refund_result' => [
            'status',
            'refund_no',
            'pay_no',
            'refund_amount',
            'chan_refund_no',
            'channel_status',
            'message',
        ],
        'refund_status_result' => [
            'status',
            'refund_no',
            'pay_no',
            'refund_amount',
            'chan_refund_no',
            'channel_status',
            'message',
        ],
    ];

    /**
     * 获取退款结果校验规则。
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['status'] = $this->scene() === 'refund_status_result'
            ? 'required|string|in:success,failed,pending,unknown'
            : 'required|string|in:success,pending,unknown';

        return $rules;
    }
}
