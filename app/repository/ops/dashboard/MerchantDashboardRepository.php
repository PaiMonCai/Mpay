<?php

namespace app\repository\ops\dashboard;

use app\common\constant\TradeConstant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\model\payment\SettlementOrder;

/**
 * 商户首页只读聚合仓库。
 *
 * 首页统计全部按当前商户隔离；服务层负责展示格式化和接入检查编排。
 */
class MerchantDashboardRepository
{
    public function __construct(
        protected PayOrder $payOrder = new PayOrder(),
        protected RefundOrder $refundOrder = new RefundOrder(),
        protected SettlementOrder $settlementOrder = new SettlementOrder(),
        protected BizOrder $bizOrder = new BizOrder()
    ) {
    }

    /**
     * 查询商户今日支付摘要。
     *
     * @return array{order_count: int, success_count: int, pay_amount: int}
     */
    public function todayPaySummary(int $merchantId, string $start, string $end): array
    {
        $created = $this->payOrder->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COUNT(*) AS order_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS success_count', [TradeConstant::ORDER_STATUS_SUCCESS])
            ->first();

        $paid = $this->payOrder->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->selectRaw('COALESCE(SUM(pay_amount), 0) AS pay_amount')
            ->first();

        return [
            'order_count' => (int) ($created->order_count ?? 0),
            'success_count' => (int) ($created->success_count ?? 0),
            'pay_amount' => (int) ($paid->pay_amount ?? 0),
        ];
    }

    /** 查询商户今日成功退款金额。 */
    public function todayRefundAmount(int $merchantId, string $start, string $end): int
    {
        return (int) $this->refundOrder->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', TradeConstant::REFUND_STATUS_SUCCESS)
            ->where('succeeded_at', '>=', $start)
            ->where('succeeded_at', '<', $end)
            ->sum('refund_amount');
    }

    /**
     * 查询待结算摘要。
     *
     * @return array{count: int, amount: int}
     */
    public function pendingSettlementSummary(int $merchantId): array
    {
        $row = $this->settlementOrder->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', TradeConstant::SETTLEMENT_STATUS_PENDING)
            ->selectRaw('COUNT(*) AS count_value')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS amount_value')
            ->first();

        return [
            'count' => (int) ($row->count_value ?? 0),
            'amount' => (int) ($row->amount_value ?? 0),
        ];
    }

    /** 查询商户是否完成过自建通道测试支付。 */
    public function hasChannelTestOrder(int $merchantId): bool
    {
        return $this->bizOrder->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('merchant_order_no', 'like', 'MCHTEST%')
            ->exists();
    }
}
