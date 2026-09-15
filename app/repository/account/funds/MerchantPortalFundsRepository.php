<?php

namespace app\repository\account\funds;

use app\common\constant\FundFreezeConstant;
use app\common\constant\LedgerConstant;
use app\common\constant\TradeConstant;
use app\model\merchant\MerchantAccountLedger;
use app\model\merchant\MerchantFundFreeze;
use app\model\payment\SettlementOrder;

/** 商户门户资金摘要只读仓库。 */
class MerchantPortalFundsRepository
{
    public function __construct(
        protected MerchantAccountLedger $ledger = new MerchantAccountLedger(),
        protected MerchantFundFreeze $freeze = new MerchantFundFreeze(),
        protected SettlementOrder $settlementOrder = new SettlementOrder()
    ) {
    }

    /** 查询累计入账、退款冲减和平台服务费。 */
    public function cumulativeSummary(int $merchantId): array
    {
        $row = $this->ledger->newQuery()
            ->where('merchant_id', $merchantId)
            ->selectRaw('COALESCE(SUM(CASE WHEN biz_type = ? AND direction = ? THEN amount ELSE 0 END), 0) AS credited_amount', [LedgerConstant::BIZ_TYPE_SETTLEMENT_CREDIT, LedgerConstant::DIRECTION_IN])
            ->selectRaw('COALESCE(SUM(CASE WHEN biz_type = ? AND direction = ? THEN amount ELSE 0 END), 0) AS refund_amount', [LedgerConstant::BIZ_TYPE_REFUND_REVERSE, LedgerConstant::DIRECTION_OUT])
            ->selectRaw('COALESCE(SUM(CASE WHEN biz_type = ? AND direction = ? THEN amount ELSE 0 END), 0) AS service_fee_amount', [LedgerConstant::BIZ_TYPE_PAY_DEDUCT, LedgerConstant::DIRECTION_OUT])
            ->first();

        $pending = $this->settlementOrder->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', TradeConstant::SETTLEMENT_STATUS_PENDING)
            ->selectRaw('COUNT(*) AS count_value')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS amount_value')
            ->first();

        return [
            'credited_amount' => (int) ($row->credited_amount ?? 0),
            'refund_amount' => (int) ($row->refund_amount ?? 0),
            'service_fee_amount' => (int) ($row->service_fee_amount ?? 0),
            'pending_settlement_count' => (int) ($pending->count_value ?? 0),
            'pending_settlement_amount' => (int) ($pending->amount_value ?? 0),
        ];
    }

    /** 查询当前仍占用资金的冻结明细。 */
    public function activeFreezes(int $merchantId, int $limit = 5)
    {
        return $this->freeze->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('status', FundFreezeConstant::STATUS_ACTIVE)
            ->where('remaining_amount', '>', 0)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }
}
