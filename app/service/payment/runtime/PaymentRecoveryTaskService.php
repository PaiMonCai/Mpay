<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\model\payment\PaymentRecoveryTask;
use app\repository\payment\runtime\PaymentRecoveryTaskRepository;
use support\Log;
use support\Redis;

/**
 * 调度支付域可恢复任务。
 *
 * MySQL 保存任务事实、重试状态和执行租约，Redis ZSET 仅用于加速发现到期任务。
 * Redis 不可用或索引不一致时，数据库索引扫描负责补齐和纠正。
 *
 * @property PaymentRecoveryTaskRepository $taskRepository 支付恢复任务仓库
 */
class PaymentRecoveryTaskService extends BaseService
{
    /** Redis 到期任务索引的键前缀。 */
    private const REDIS_KEY_PREFIX = 'payment:recovery:due:';

    /**
     * 构造方法。
     *
     * @param PaymentRecoveryTaskRepository $taskRepository 支付恢复任务仓库
     */
    public function __construct(
        protected PaymentRecoveryTaskRepository $taskRepository
    ) {
    }

    /**
     * 创建或重新调度恢复任务。
     *
     * 本方法自行开启事务。正在执行且租约未过期的任务保持不变，其他同类型、同引用单号
     * 的任务恢复为等待状态。最大重试次数为 0 表示不限制重试次数。
     *
     * @param string $taskType 任务类型
     * @param string $refNo 业务引用单号
     * @param int $delaySeconds 首次或下次执行的延迟秒数
     * @param int $maxRetryCount 最大重试次数，0 表示不限制
     * @param int $priority 调度优先级，数值越大优先级越高
     * @param bool $resetAttempts 是否重置重试次数和执行序号
     * @return PaymentRecoveryTask 最新任务记录
     * @throws \InvalidArgumentException 任务类型无效或引用单号为空时抛出
     */
    public function schedule(
        string $taskType,
        string $refNo,
        int $delaySeconds = 0,
        int $maxRetryCount = 0,
        int $priority = 0,
        bool $resetAttempts = false
    ): PaymentRecoveryTask {
        return $this->transactionRetry(fn (): PaymentRecoveryTask => $this->scheduleInCurrentTransaction(
            $taskType,
            $refNo,
            $delaySeconds,
            $maxRetryCount,
            $priority,
            $resetAttempts
        ));
    }

    /**
     * 在当前事务中创建或重新调度恢复任务。
     *
     * 调用方负责事务提交或回滚。本方法与业务单据写入共享事务，确保恢复责任不会先于
     * 业务事实提交。最大重试次数为 0 表示不限制重试次数。
     *
     * @param string $taskType 任务类型
     * @param string $refNo 业务引用单号
     * @param int $delaySeconds 首次或下次执行的延迟秒数
     * @param int $maxRetryCount 最大重试次数，0 表示不限制
     * @param int $priority 调度优先级，数值越大优先级越高
     * @param bool $resetAttempts 是否重置重试次数和执行序号
     * @return PaymentRecoveryTask 最新任务记录
     * @throws \InvalidArgumentException 任务类型无效或引用单号为空时抛出
     */
    public function scheduleInCurrentTransaction(
        string $taskType,
        string $refNo,
        int $delaySeconds = 0,
        int $maxRetryCount = 0,
        int $priority = 0,
        bool $resetAttempts = false
    ): PaymentRecoveryTask {
        $taskType = trim($taskType);
        $refNo = trim($refNo);
        if (!in_array($taskType, PaymentRecoveryTaskConstant::taskTypes(), true) || $refNo === '') {
            throw new \InvalidArgumentException('恢复任务类型无效或引用单号为空');
        }

        $task = $this->taskRepository->findForUpdateByTypeRef($taskType, $refNo);
        $nextRetryAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
        if (!$task) {
            /** @var PaymentRecoveryTask $task */
            $task = $this->taskRepository->create([
                'task_no' => $this->generateNo('RTK'),
                'task_type' => $taskType,
                'ref_no' => $refNo,
                'status' => PaymentRecoveryTaskConstant::STATUS_WAITING,
                'priority' => max(0, $priority),
                'next_retry_at' => $nextRetryAt,
                'retry_count' => 0,
                'max_retry_count' => max(0, $maxRetryCount),
                'execution_seq' => 0,
                'lease_owner' => '',
                'lease_token' => '',
                'lease_expired_at' => null,
                'last_attempt_at' => null,
                'last_error' => '',
            ]);
        } else {
            $leaseExpiredAt = $task->lease_expired_at ? strtotime((string) $task->lease_expired_at) : false;
            if ((int) $task->status === PaymentRecoveryTaskConstant::STATUS_RUNNING
                && $leaseExpiredAt !== false
                && $leaseExpiredAt > time()) {
                return $task->refresh();
            }

            $task->status = PaymentRecoveryTaskConstant::STATUS_WAITING;
            $task->priority = max(0, $priority);
            $task->next_retry_at = $nextRetryAt;
            $task->max_retry_count = max(0, $maxRetryCount);
            $task->lease_owner = '';
            $task->lease_token = '';
            $task->lease_expired_at = null;
            $task->last_error = '';
            if ($resetAttempts) {
                $task->retry_count = 0;
                $task->execution_seq = 0;
                $task->last_attempt_at = null;
            }
            $task->save();
        }

        $task = $task->refresh();
        $this->publishBestEffort($task);

        return $task;
    }

    /**
     * 查询到期任务的业务引用单号。
     *
     * Redis 候选和数据库索引结果会合并后再以数据库状态校验，过期的 Redis 成员会在
     * 本次查询中修正，因此返回结果不依赖 Redis 数据完整性。
     *
     * @param string $taskType 任务类型
     * @param int $limit 最大返回数量
     * @return array<int, string> 到期任务的业务引用单号列表
     */
    public function listDueRefs(string $taskType, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $now = $this->now();
        $ids = $this->redisDueIds($taskType, $limit);
        foreach ($this->taskRepository->listDueIds($taskType, $now, $limit) as $id) {
            $ids[] = $id;
        }
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, $limit);
        if ($ids === []) {
            return [];
        }

        $rows = $this->taskRepository->query()
            ->where('task_type', $taskType)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $refs = [];
        $nowTimestamp = time();
        foreach ($ids as $id) {
            /** @var PaymentRecoveryTask|null $task */
            $task = $rows->get($id);
            if (!$task || !$this->isDue($task, $nowTimestamp)) {
                if ($task) {
                    $this->publishBestEffort($task);
                } else {
                    $this->removeRedisBestEffort($taskType, $id);
                }
                continue;
            }
            $refs[] = (string) $task->ref_no;
        }

        return $refs;
    }

    /**
     * 原子认领到期任务并建立执行租约。
     *
     * 只有等待执行或运行中但租约已过期的任务可以认领。返回对象携带本次执行的租约
     * 令牌，后续成功或重试操作必须使用同一对象校验所有权。
     *
     * @param string $taskType 任务类型
     * @param string $refNo 业务引用单号
     * @param int $leaseSeconds 租约秒数，最少为 10 秒
     * @return PaymentRecoveryTask|null 已认领任务；任务不存在或未到期时返回 null
     */
    public function claim(string $taskType, string $refNo, int $leaseSeconds = 90): ?PaymentRecoveryTask
    {
        return $this->transactionRetry(function () use ($taskType, $refNo, $leaseSeconds): ?PaymentRecoveryTask {
            $task = $this->taskRepository->findForUpdateByTypeRef($taskType, $refNo);
            if (!$task || !$this->isDue($task, time())) {
                return null;
            }

            $task->status = PaymentRecoveryTaskConstant::STATUS_RUNNING;
            $task->execution_seq = (int) $task->execution_seq + 1;
            $task->lease_owner = $this->workerOwner();
            $task->lease_token = bin2hex(random_bytes(16));
            $task->lease_expired_at = date('Y-m-d H:i:s', time() + max(10, $leaseSeconds));
            $task->last_attempt_at = $this->now();
            $task->next_retry_at = null;
            $task->last_error = '';
            $task->save();
            $this->removeRedisBestEffort($taskType, (int) $task->id);

            return $task->refresh();
        });
    }

    /**
     * 将已认领任务标记为成功。
     *
     * 已成功任务按幂等成功处理；其他状态必须通过租约令牌校验，防止过期工作进程覆盖
     * 新认领者的执行结果。
     *
     * @param PaymentRecoveryTask $claimedTask 认领时返回的任务对象
     * @return bool 是否确认任务成功
     */
    public function succeed(PaymentRecoveryTask $claimedTask): bool
    {
        return $this->transactionRetry(function () use ($claimedTask): bool {
            $task = $this->taskRepository->findForUpdateById((int) $claimedTask->id);
            if (!$task) {
                return false;
            }
            if ((int) $task->status === PaymentRecoveryTaskConstant::STATUS_SUCCESS) {
                return true;
            }
            if (!$this->ownsLease($task, $claimedTask)) {
                return false;
            }

            $this->applyCompletedState($task);
            $task->save();
            $this->removeRedisBestEffort((string) $task->task_type, (int) $task->id);

            return true;
        });
    }

    /**
     * 在当前事务中完成指定业务引用的任务。
     *
     * 本方法供业务单据进入终态时调用，不要求执行租约；业务终态是比异步执行结果更强
     * 的事实，可以直接收口对应恢复任务。
     *
     * @param string $taskType 任务类型
     * @param string $refNo 业务引用单号
     */
    public function completeInCurrentTransaction(string $taskType, string $refNo): void
    {
        $task = $this->taskRepository->findForUpdateByTypeRef($taskType, $refNo);
        if (!$task) {
            return;
        }

        $this->applyCompletedState($task);
        $task->save();
        $this->removeRedisBestEffort($taskType, (int) $task->id);
    }

    /**
     * 在独立事务中完成指定业务引用的任务。
     *
     * @param string $taskType 任务类型
     * @param string $refNo 业务引用单号
     */
    public function complete(string $taskType, string $refNo): void
    {
        $this->transactionRetry(function () use ($taskType, $refNo): void {
            $this->completeInCurrentTransaction($taskType, $refNo);
        });
    }

    /**
     * 将已认领任务按退避时间重新调度。
     *
     * 仅当前租约持有者可以重试。达到非零最大重试次数后任务进入挂起状态，否则清除
     * 租约并按指定延迟重新进入等待状态。
     *
     * @param PaymentRecoveryTask $claimedTask 认领时返回的任务对象
     * @param int $delaySeconds 下次执行的延迟秒数，最少为 1 秒
     * @param string $error 本次失败原因
     * @return bool 是否完成重试状态更新
     */
    public function retry(PaymentRecoveryTask $claimedTask, int $delaySeconds, string $error = ''): bool
    {
        return $this->transactionRetry(function () use ($claimedTask, $delaySeconds, $error): bool {
            $task = $this->taskRepository->findForUpdateById((int) $claimedTask->id);
            if (!$task || !$this->ownsLease($task, $claimedTask)) {
                return false;
            }

            $task->retry_count = (int) $task->retry_count + 1;
            $task->lease_owner = '';
            $task->lease_token = '';
            $task->lease_expired_at = null;
            $task->last_error = mb_strcut($error, 0, 255, 'UTF-8');
            $maxRetryCount = (int) $task->max_retry_count;
            if ($maxRetryCount > 0 && (int) $task->retry_count >= $maxRetryCount) {
                $task->status = PaymentRecoveryTaskConstant::STATUS_SUSPENDED;
                $task->next_retry_at = null;
            } else {
                $task->status = PaymentRecoveryTaskConstant::STATUS_WAITING;
                $task->next_retry_at = date('Y-m-d H:i:s', time() + max(1, $delaySeconds));
            }
            $task->save();
            $task = $task->refresh();

            if ((int) $task->status === PaymentRecoveryTaskConstant::STATUS_WAITING) {
                $this->publishBestEffort($task);
            } else {
                $this->removeRedisBestEffort((string) $task->task_type, (int) $task->id);
            }

            return true;
        });
    }

    /**
     * 将任务对象收口为成功状态。
     *
     * @param PaymentRecoveryTask $task 待更新任务
     */
    private function applyCompletedState(PaymentRecoveryTask $task): void
    {
        $task->status = PaymentRecoveryTaskConstant::STATUS_SUCCESS;
        $task->next_retry_at = null;
        $task->lease_owner = '';
        $task->lease_token = '';
        $task->lease_expired_at = null;
        $task->last_error = '';
    }

    /**
     * 判断任务在指定时间是否可以认领。
     *
     * 等待任务按下次执行时间判断，运行中任务仅在租约过期后重新开放认领。
     *
     * @param PaymentRecoveryTask $task 待判断任务
     * @param int $nowTimestamp 当前 Unix 时间戳
     * @return bool 是否已到期
     */
    private function isDue(PaymentRecoveryTask $task, int $nowTimestamp): bool
    {
        $status = (int) $task->status;
        if ($status === PaymentRecoveryTaskConstant::STATUS_WAITING) {
            $nextRetryAt = $task->next_retry_at ? strtotime((string) $task->next_retry_at) : false;
            return $nextRetryAt !== false && $nextRetryAt <= $nowTimestamp;
        }
        if ($status === PaymentRecoveryTaskConstant::STATUS_RUNNING) {
            $leaseExpiredAt = $task->lease_expired_at ? strtotime((string) $task->lease_expired_at) : false;
            return $leaseExpiredAt !== false && $leaseExpiredAt <= $nowTimestamp;
        }

        return false;
    }

    /**
     * 校验认领快照是否仍持有当前任务租约。
     *
     * @param PaymentRecoveryTask $current 数据库中的当前任务
     * @param PaymentRecoveryTask $claimed 认领时返回的任务快照
     * @return bool 是否持有当前租约
     */
    private function ownsLease(PaymentRecoveryTask $current, PaymentRecoveryTask $claimed): bool
    {
        return (int) $current->status === PaymentRecoveryTaskConstant::STATUS_RUNNING
            && (string) $current->lease_token !== ''
            && hash_equals((string) $current->lease_token, (string) $claimed->lease_token);
    }

    /**
     * 尝试将等待任务写入 Redis 到期索引。
     *
     * Redis 仅为加速索引，写入失败只记录日志，不影响数据库事务结果。
     *
     * @param PaymentRecoveryTask $task 待发布任务
     */
    private function publishBestEffort(PaymentRecoveryTask $task): void
    {
        try {
            if ((int) $task->status !== PaymentRecoveryTaskConstant::STATUS_WAITING || !$task->next_retry_at) {
                $this->removeRedisBestEffort((string) $task->task_type, (int) $task->id);
                return;
            }
            $score = strtotime((string) $task->next_retry_at);
            if ($score === false) {
                return;
            }
            Redis::zAdd($this->redisKey((string) $task->task_type), $score, (string) $task->id);
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[PaymentRecoveryTask] Redis 调度写入失败 task_no=%s error=%s',
                (string) $task->task_no,
                $e->getMessage()
            ));
        }
    }

    /**
     * 从 Redis 到期索引读取任务 ID。
     *
     * @param string $taskType 任务类型
     * @param int $limit 最大读取数量
     * @return array<int, int> 到期任务 ID 列表
     */
    private function redisDueIds(string $taskType, int $limit): array
    {
        try {
            $rows = Redis::connection()->rawCommand(
                'ZRANGEBYSCORE',
                $this->redisKey($taskType),
                '-inf',
                (string) time(),
                'LIMIT',
                '0',
                (string) max(1, $limit)
            );

            return array_values(array_filter(array_map('intval', is_array($rows) ? $rows : [])));
        } catch (\Throwable $e) {
            Log::warning('[PaymentRecoveryTask] Redis 到期任务读取失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 尝试从 Redis 到期索引移除任务。
     *
     * @param string $taskType 任务类型
     * @param int $taskId 任务 ID
     */
    private function removeRedisBestEffort(string $taskType, int $taskId): void
    {
        if ($taskId <= 0) {
            return;
        }
        try {
            Redis::zRem($this->redisKey($taskType), (string) $taskId);
        } catch (\Throwable) {
            // Redis 不是事实源，删除失败由数据库状态在下次扫描时纠正。
        }
    }

    /**
     * 生成任务类型对应的 Redis 到期索引键。
     *
     * @param string $taskType 任务类型
     * @return string Redis 键
     */
    private function redisKey(string $taskType): string
    {
        return self::REDIS_KEY_PREFIX . strtolower($taskType);
    }

    /**
     * 生成当前工作进程的租约持有者标识。
     *
     * @return string 主机名和进程号组成的持有者标识
     */
    private function workerOwner(): string
    {
        return mb_strcut((gethostname() ?: 'worker') . ':' . getmypid(), 0, 64, 'UTF-8');
    }
}
