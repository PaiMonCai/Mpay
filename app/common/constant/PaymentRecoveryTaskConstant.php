<?php

namespace app\common\constant;

/**
 * 定义支付恢复任务的类型和执行状态。
 *
 * 任务类型用于绑定恢复处理器，任务状态用于数据库租约、重试调度和终态收口；
 * Redis 只保存到期索引，不定义新的任务类型或状态。
 */
final class PaymentRecoveryTaskConstant
{
    /**
     * 补偿支付成功后的商户通知和清算等后续动作。
     */
    public const TYPE_PAY_SUCCESS_SIDE_EFFECT = 'PAY_SUCCESS_SIDE_EFFECT';

    /**
     * 主动查询尚未进入终态的退款单。
     */
    public const TYPE_REFUND_ACTIVE_QUERY = 'REFUND_ACTIVE_QUERY';

    /**
     * 追偿退款成功后尚未完成的本地账户冲减。
     */
    public const TYPE_REFUND_ACCOUNT_REVERSE = 'REFUND_ACCOUNT_REVERSE';

    /**
     * 补偿尚未可靠投递到转账执行队列的转账单。
     */
    public const TYPE_TRANSFER_DISPATCH = 'TRANSFER_DISPATCH';

    /**
     * 主动查询结果仍不确定的转账单。
     */
    public const TYPE_TRANSFER_QUERY = 'TRANSFER_QUERY';

    /**
     * 任务等待达到下次执行时间。
     */
    public const STATUS_WAITING = 0;

    /**
     * 任务已被工作进程认领且租约尚未结束。
     */
    public const STATUS_RUNNING = 1;

    /**
     * 任务已经完成，无需继续调度。
     */
    public const STATUS_SUCCESS = 2;

    /**
     * 任务达到最大重试次数，暂停自动调度。
     */
    public const STATUS_SUSPENDED = 3;

    /**
     * 任务已经取消，不再参与自动调度。
     */
    public const STATUS_CANCELLED = 4;

    /**
     * 获取可调度的恢复任务类型。
     *
     * @return array<int, string> 恢复任务类型列表
     */
    public static function taskTypes(): array
    {
        return [
            self::TYPE_PAY_SUCCESS_SIDE_EFFECT,
            self::TYPE_REFUND_ACTIVE_QUERY,
            self::TYPE_REFUND_ACCOUNT_REVERSE,
            self::TYPE_TRANSFER_DISPATCH,
            self::TYPE_TRANSFER_QUERY,
        ];
    }

    /**
     * 获取恢复任务状态名称映射。
     *
     * @return array<int, string> 恢复任务状态名称表
     */
    public static function statusMap(): array
    {
        return [
            self::STATUS_WAITING => '等待执行',
            self::STATUS_RUNNING => '执行中',
            self::STATUS_SUCCESS => '已完成',
            self::STATUS_SUSPENDED => '已暂停',
            self::STATUS_CANCELLED => '已取消',
        ];
    }
}
