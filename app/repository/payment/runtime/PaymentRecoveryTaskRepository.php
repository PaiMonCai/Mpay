<?php

namespace app\repository\payment\runtime;

use app\common\base\BaseRepository;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\model\payment\PaymentRecoveryTask;

/**
 * 查询支付恢复任务及其到期调度索引。
 *
 * 本仓库只负责按任务唯一键、主键和调度时间查询数据库记录；任务认领、租约校验、
 * 重试状态推进和 Redis 加速索引由服务层负责。
 */
class PaymentRecoveryTaskRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentRecoveryTask());
    }

    /**
     * 按任务类型和业务引用单号查询任务。
     *
     * @param string $taskType 恢复任务类型
     * @param string $refNo 业务引用单号
     * @param list<string> $columns 查询字段
     * @return PaymentRecoveryTask|null 任务不存在时返回 null
     */
    public function findByTypeRef(string $taskType, string $refNo, array $columns = ['*']): ?PaymentRecoveryTask
    {
        return $this->model->newQuery()
            ->where('task_type', $taskType)
            ->where('ref_no', $refNo)
            ->first($columns);
    }

    /**
     * 在当前事务中按任务唯一业务键锁定任务。
     *
     * 调用方必须已经开启数据库事务；行锁持续到事务结束，用于串行化同一任务的调度、
     * 认领和业务终态收口。任务不存在时返回 null。
     *
     * @param string $taskType 恢复任务类型
     * @param string $refNo 业务引用单号
     * @param list<string> $columns 查询字段
     * @return PaymentRecoveryTask|null 已锁定的任务记录
     */
    public function findForUpdateByTypeRef(string $taskType, string $refNo, array $columns = ['*']): ?PaymentRecoveryTask
    {
        return $this->model->newQuery()
            ->where('task_type', $taskType)
            ->where('ref_no', $refNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 在当前事务中按主键锁定任务。
     *
     * 调用方必须已经开启数据库事务；行锁持续到事务结束，供租约持有者提交成功或重试
     * 结果时校验数据库最新状态。任务不存在时返回 null。
     *
     * @param int $id 恢复任务 ID
     * @param list<string> $columns 查询字段
     * @return PaymentRecoveryTask|null 已锁定的任务记录
     */
    public function findForUpdateById(int $id, array $columns = ['*']): ?PaymentRecoveryTask
    {
        return $this->model->newQuery()
            ->whereKey($id)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 使用调度索引查询可认领的任务 ID。
     *
     * 查询范围包括下次执行时间不晚于指定时间的等待任务，以及租约到期时间不晚于指定
     * 时间的运行中任务。等待任务按优先级、执行时间和 ID 排序，租约过期任务按租约时间
     * 和 ID 排序；两组结果去重后共同受数量上限约束。本方法不加行锁，认领时必须再次
     * 锁定任务并校验状态。
     *
     * @param string $taskType 恢复任务类型
     * @param string $now 数据库时间比较上限，格式为 Y-m-d H:i:s
     * @param int $limit 最大返回数量
     * @return list<int> 可认领的任务 ID 列表
     */
    public function listDueIds(string $taskType, string $now, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $waitingIds = $this->model->newQuery()
            ->where('task_type', $taskType)
            ->where('status', PaymentRecoveryTaskConstant::STATUS_WAITING)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $now)
            ->orderByDesc('priority')
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $runningIds = $this->model->newQuery()
            ->where('task_type', $taskType)
            ->where('status', PaymentRecoveryTaskConstant::STATUS_RUNNING)
            ->whereNotNull('lease_expired_at')
            ->where('lease_expired_at', '<=', $now)
            ->orderBy('lease_expired_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_slice(array_values(array_unique([...$waitingIds, ...$runningIds])), 0, $limit);
    }
}
