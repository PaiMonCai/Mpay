<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/** 支付异常中心只读查询参数校验器。 */
class PaymentExceptionValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'exception_type' => 'sometimes|string|max:64',
        'subject_type' => 'sometimes|string|in:PAY,REFUND,TRANSFER,SETTLEMENT,ACCOUNT',
        'severity' => 'sometimes|integer|in:1,2,3',
        'status' => 'sometimes|integer|in:0,1,2,3,4',
        'task_type' => 'sometimes|string|max:64',
        'include_closed' => 'sometimes|boolean',
        'start_time' => 'sometimes|date_format:Y-m-d H:i:s',
        'end_time' => 'sometimes|date_format:Y-m-d H:i:s',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '记录ID',
        'keyword' => '关键字',
        'merchant_id' => '商户',
        'exception_type' => '异常类型',
        'subject_type' => '业务主体',
        'severity' => '严重级别',
        'status' => '状态',
        'task_type' => '恢复任务类型',
        'include_closed' => '包含已关闭记录',
        'start_time' => '开始时间',
        'end_time' => '结束时间',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'exceptionIndex' => ['keyword', 'merchant_id', 'exception_type', 'subject_type', 'severity', 'status', 'include_closed', 'start_time', 'end_time', 'page', 'page_size'],
        'exceptionShow' => ['id'],
        'recoveryIndex' => ['keyword', 'task_type', 'status', 'include_closed', 'start_time', 'end_time', 'page', 'page_size'],
        'recoveryShow' => ['id'],
    ];
}
