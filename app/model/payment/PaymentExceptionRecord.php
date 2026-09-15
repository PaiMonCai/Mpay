<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付业务异常当前状态模型。
 */
class PaymentExceptionRecord extends BaseModel
{
    protected $table = 'ma_payment_exception';

    protected $fillable = [
        'exception_no',
        'subject_type',
        'subject_no',
        'biz_no',
        'merchant_id',
        'exception_type',
        'severity',
        'status',
        'source_type',
        'source_id',
        'summary',
        'detail_json',
        'resolution',
        'resolution_ref_no',
        'resolver_id',
        'detected_at',
        'resolved_at',
    ];

    protected $casts = [
        'merchant_id' => 'integer',
        'severity' => 'integer',
        'status' => 'integer',
        'source_id' => 'integer',
        'detail_json' => 'array',
        'resolver_id' => 'integer',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
