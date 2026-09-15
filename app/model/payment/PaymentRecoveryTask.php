<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付恢复任务模型。
 *
 * 数据库记录是可靠事实，Redis 仅保存到期任务索引。
 */
class PaymentRecoveryTask extends BaseModel
{
    protected $table = 'ma_payment_recovery_task';

    protected $fillable = [
        'task_no',
        'task_type',
        'ref_no',
        'status',
        'priority',
        'next_retry_at',
        'retry_count',
        'max_retry_count',
        'execution_seq',
        'lease_owner',
        'lease_token',
        'lease_expired_at',
        'last_attempt_at',
        'last_error',
    ];

    protected $casts = [
        'status' => 'integer',
        'priority' => 'integer',
        'next_retry_at' => 'datetime',
        'retry_count' => 'integer',
        'max_retry_count' => 'integer',
        'execution_seq' => 'integer',
        'lease_expired_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
