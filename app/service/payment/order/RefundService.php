<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\model\payment\RefundOrder;

/**
 * 退款单服务。
 *
 * @property RefundQueryService $queryService 查询服务
 * @property RefundCreationService $creationService 创建服务
 * @property RefundLifecycleService $lifecycleService 生命周期服务
 */
class RefundService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param RefundQueryService $queryService 查询服务
     * @param RefundCreationService $creationService 创建服务
     * @param RefundLifecycleService $lifecycleService 生命周期服务
     * @return void
     */
    public function __construct(
        protected RefundQueryService $queryService,
        protected RefundCreationService $creationService,
        protected RefundLifecycleService $lifecycleService,
        protected RefundDispatchService $dispatchService
    ) {
    }

    /**
     * 分页查询退款订单列表。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param int|null $merchantId 商户ID
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, size: int, pay_types: array<int, array{label: string, value: int}>} 退款列表结构
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null): array
    {
        return $this->queryService->paginate($filters, $page, $pageSize, $merchantId);
    }

    /**
     * 查询退款订单详情。
     *
     * @param string $refundNo 退款单号
     * @param int|null $merchantId 商户ID
     * @return array{refund_order: array<string, mixed>, timeline: array<int, array<string, mixed>>, account_ledgers: array<int, array<string, mixed>>} 退款详情结构
     */
    public function detail(string $refundNo, ?int $merchantId = null): array
    {
        return $this->queryService->detail($refundNo, $merchantId);
    }

    /**
     * 创建退款单。
     *
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function createRefund(array $input): RefundOrder
    {
        return $this->creationService->createRefund($input);
    }

    /**
     * 标记退款处理中。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundProcessing(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundProcessing($refundNo, $input);
    }

    /**
     * 重新派发待处理退款，或重试失败退款。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @param int|null $merchantId 商户ID
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function retryRefund(string $refundNo, array $input = [], ?int $merchantId = null): RefundOrder
    {
        $refundOrder = $this->queryService->findByRefundNo($refundNo, $merchantId);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $status = (int) $refundOrder->status;
        $extJson = (array) ($refundOrder->ext_json ?? []);
        $adminAction = (array) ($extJson['admin_action'] ?? []);
        if ((string) ($adminAction['type'] ?? '') === 'manual_refund') {
            throw new BusinessStateException('手动退款记录不能派发到上游通道', ['refund_no' => $refundNo]);
        }
        if (!in_array($status, [
            TradeConstant::REFUND_STATUS_CREATED,
            TradeConstant::REFUND_STATUS_FAILED,
        ], true)) {
            throw new BusinessStateException('当前退款状态不允许重新派发', [
                'refund_no' => $refundNo,
                'status' => $status,
            ]);
        }

        return $this->dispatchService->dispatch(
            $refundOrder,
            $status === TradeConstant::REFUND_STATUS_FAILED
        );
    }

    /**
     * 在当前事务中标记退款处理中或重试。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @param bool $isRetry 是否来自重试流程
     * @return RefundOrder 退款单模型
     */
    public function markRefundProcessingInCurrentTransaction(string $refundNo, array $input = [], bool $isRetry = false): RefundOrder
    {
        return $this->lifecycleService->markRefundProcessingInCurrentTransaction($refundNo, $input, $isRetry);
    }

    /**
     * 退款成功。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundSuccess(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundSuccess($refundNo, $input);
    }

    /**
     * 在当前事务中标记退款成功。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundSuccessInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundSuccessInCurrentTransaction($refundNo, $input);
    }

    /**
     * 退款失败。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundFailed(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundFailed($refundNo, $input);
    }

    /**
     * 在当前事务中标记退款失败。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundFailedInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundFailedInCurrentTransaction($refundNo, $input);
    }
}
