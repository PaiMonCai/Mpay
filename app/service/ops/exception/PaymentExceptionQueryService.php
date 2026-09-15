<?php

namespace app\service\ops\exception;

use app\common\base\BaseService;
use app\common\constant\PaymentExceptionConstant;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\repository\payment\runtime\PaymentExceptionRecordRepository;
use app\repository\payment\runtime\PaymentRecoveryTaskRepository;

/**
 * 支付异常中心只读查询服务。
 *
 * 本服务只展示已经落库的业务异常和恢复任务，不提供重试、忽略或强制完成等生命周期
 * 操作，避免管理页面绕过原业务服务修改可靠事实。
 */
class PaymentExceptionQueryService extends BaseService
{
    public function __construct(
        protected PaymentExceptionRecordRepository $exceptionRepository,
        protected PaymentRecoveryTaskRepository $recoveryTaskRepository
    ) {
    }

    /** 分页查询业务异常。 */
    public function paginateExceptions(array $filters, int $page, int $pageSize): array
    {
        $query = $this->exceptionRepository->query()
            ->from('ma_payment_exception as e')
            ->leftJoin('ma_merchant as m', 'e.merchant_id', '=', 'm.id')
            ->select(['e.*'])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name");

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('e.exception_no', 'like', '%' . $keyword . '%')
                    ->orWhere('e.subject_no', 'like', '%' . $keyword . '%')
                    ->orWhere('e.biz_no', 'like', '%' . $keyword . '%')
                    ->orWhere('e.summary', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
            });
        }

        $this->applyCommonExceptionFilters($query, $filters);
        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('e.status', (int) $filters['status']);
        } elseif (empty($filters['include_closed'])) {
            $query->whereIn('e.status', [PaymentExceptionConstant::STATUS_OPEN, PaymentExceptionConstant::STATUS_HANDLING]);
        }

        $paginator = $query->orderByDesc('e.detected_at')->orderByDesc('e.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        return [
            'list' => array_map(fn ($row): array => $this->formatException((array) $row->toArray()), $paginator->items()),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
            'type_options' => $this->mapOptions(PaymentExceptionConstant::typeMap()),
        ];
    }

    /** 查询单条业务异常。 */
    public function exceptionDetail(int $id): ?array
    {
        $row = $this->exceptionRepository->query()
            ->from('ma_payment_exception as e')
            ->leftJoin('ma_merchant as m', 'e.merchant_id', '=', 'm.id')
            ->where('e.id', $id)
            ->select(['e.*'])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->first();

        return $row ? $this->formatException((array) $row->toArray()) : null;
    }

    /** 分页查询恢复任务。 */
    public function paginateRecoveryTasks(array $filters, int $page, int $pageSize): array
    {
        $query = $this->recoveryTaskRepository->query()->from('ma_payment_recovery_task as r')->select(['r.*']);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('r.task_no', 'like', '%' . $keyword . '%')
                    ->orWhere('r.ref_no', 'like', '%' . $keyword . '%')
                    ->orWhere('r.last_error', 'like', '%' . $keyword . '%');
            });
        }

        $taskType = trim((string) ($filters['task_type'] ?? ''));
        if ($taskType !== '') {
            $query->where('r.task_type', $taskType);
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('r.status', (int) $filters['status']);
        } elseif (empty($filters['include_closed'])) {
            $query->whereIn('r.status', [
                PaymentRecoveryTaskConstant::STATUS_WAITING,
                PaymentRecoveryTaskConstant::STATUS_RUNNING,
                PaymentRecoveryTaskConstant::STATUS_SUSPENDED,
            ]);
        }
        $this->applyTimeRange($query, 'r.created_at', $filters);

        $paginator = $query->orderByDesc('r.priority')->orderByDesc('r.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        return [
            'list' => array_map(fn ($row): array => $this->formatRecoveryTask((array) $row->toArray()), $paginator->items()),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
            'type_options' => $this->mapOptions($this->recoveryTypeMap()),
        ];
    }

    /** 查询单条恢复任务。 */
    public function recoveryTaskDetail(int $id): ?array
    {
        $row = $this->recoveryTaskRepository->query()->whereKey($id)->first();
        return $row ? $this->formatRecoveryTask((array) $row->toArray()) : null;
    }

    /** 查询首页需要的未关闭异常和暂停任务数量。 */
    public function attentionSummary(): array
    {
        return [
            'open_exception_count' => (int) $this->exceptionRepository->query()
                ->whereIn('status', [PaymentExceptionConstant::STATUS_OPEN, PaymentExceptionConstant::STATUS_HANDLING])
                ->count(),
            'suspended_recovery_count' => (int) $this->recoveryTaskRepository->query()
                ->where('status', PaymentRecoveryTaskConstant::STATUS_SUSPENDED)
                ->count(),
        ];
    }

    private function applyCommonExceptionFilters($query, array $filters): void
    {
        foreach (['merchant_id' => 'e.merchant_id', 'severity' => 'e.severity'] as $key => $column) {
            if (($value = (int) ($filters[$key] ?? 0)) > 0) {
                $query->where($column, $value);
            }
        }
        foreach (['exception_type' => 'e.exception_type', 'subject_type' => 'e.subject_type'] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $query->where($column, $value);
            }
        }
        $this->applyTimeRange($query, 'e.detected_at', $filters);
    }

    private function applyTimeRange($query, string $column, array $filters): void
    {
        $start = trim((string) ($filters['start_time'] ?? ''));
        $end = trim((string) ($filters['end_time'] ?? ''));
        if ($start !== '') {
            $query->where($column, '>=', $start);
        }
        if ($end !== '') {
            $query->where($column, '<', $end);
        }
    }

    private function formatException(array $row): array
    {
        $row['exception_type_text'] = PaymentExceptionConstant::typeMap()[(string) ($row['exception_type'] ?? '')] ?? (string) ($row['exception_type'] ?? '未知');
        $row['status_text'] = PaymentExceptionConstant::statusMap()[(int) ($row['status'] ?? -1)] ?? '未知';
        $row['severity_text'] = [1 => '警告', 2 => '高风险', 3 => '严重'][(int) ($row['severity'] ?? 0)] ?? '未知';
        $row['detected_at_text'] = $this->formatDateTime($row['detected_at'] ?? null, '—');
        $row['resolved_at_text'] = $this->formatDateTime($row['resolved_at'] ?? null, '—');
        [$row['target_path'], $row['target_query']] = $this->exceptionTarget((string) ($row['subject_type'] ?? ''), (string) ($row['subject_no'] ?? ''));
        return $row;
    }

    private function formatRecoveryTask(array $row): array
    {
        $row['task_type_text'] = $this->recoveryTypeMap()[(string) ($row['task_type'] ?? '')] ?? (string) ($row['task_type'] ?? '未知');
        $row['status_text'] = PaymentRecoveryTaskConstant::statusMap()[(int) ($row['status'] ?? -1)] ?? '未知';
        $row['next_retry_at_text'] = $this->formatDateTime($row['next_retry_at'] ?? null, '—');
        $row['last_attempt_at_text'] = $this->formatDateTime($row['last_attempt_at'] ?? null, '—');
        $row['created_at_text'] = $this->formatDateTime($row['created_at'] ?? null, '—');
        [$row['target_path'], $row['target_query']] = $this->recoveryTarget((string) ($row['task_type'] ?? ''), (string) ($row['ref_no'] ?? ''));
        return $row;
    }

    private function exceptionTarget(string $subjectType, string $subjectNo): array
    {
        return match ($subjectType) {
            PaymentExceptionConstant::SUBJECT_PAY => ['/transaction/pay-order', ['search_field' => 'pay_no', 'keyword' => $subjectNo]],
            PaymentExceptionConstant::SUBJECT_REFUND => ['/transaction/refund-order', ['search_field' => 'refund_no', 'keyword' => $subjectNo]],
            PaymentExceptionConstant::SUBJECT_SETTLEMENT => ['/transaction/settlement-order', ['keyword' => $subjectNo]],
            PaymentExceptionConstant::SUBJECT_ACCOUNT => ['/funds/merchant-account', []],
            default => ['', []],
        };
    }

    private function recoveryTarget(string $taskType, string $refNo): array
    {
        if (str_starts_with($taskType, 'PAY_')) {
            return ['/transaction/pay-order', ['search_field' => 'pay_no', 'keyword' => $refNo]];
        }
        if (str_starts_with($taskType, 'REFUND_')) {
            return ['/transaction/refund-order', ['search_field' => 'refund_no', 'keyword' => $refNo]];
        }
        return ['', []];
    }

    /** @return array<string, string> */
    private function recoveryTypeMap(): array
    {
        return [
            PaymentRecoveryTaskConstant::TYPE_PAY_SUCCESS_SIDE_EFFECT => '支付成功后续补偿',
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY => '退款主动查询',
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACCOUNT_REVERSE => '退款账户冲减',
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_DISPATCH => '转账投递补偿',
            PaymentRecoveryTaskConstant::TYPE_TRANSFER_QUERY => '转账主动查询',
        ];
    }

    /** @param array<string|int, string> $map */
    private function mapOptions(array $map): array
    {
        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }
        return $options;
    }
}
