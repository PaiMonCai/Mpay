<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\PaymentExceptionConstant;
use app\model\payment\PayOrder;
use app\model\payment\PaymentExceptionRecord;
use app\repository\payment\runtime\PaymentExceptionRecordRepository;

/**
 * 管理支付业务异常的当前处置状态。
 *
 * 异常记录用于后台发现和处置少量非标准业务结果，不替代支付单的渠道错误字段，
 * 也不改变支付单真实状态或商户协议响应。
 *
 * @property PaymentExceptionRecordRepository $exceptionRepository 支付异常记录仓库
 */
class PaymentExceptionService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentExceptionRecordRepository $exceptionRepository 支付异常记录仓库
     */
    public function __construct(
        protected PaymentExceptionRecordRepository $exceptionRepository
    ) {
    }

    /**
     * 登记晚到的重复支付异常。
     *
     * 调用方必须已开启支付成功事务。本方法锁定同一支付单、同一异常类型的记录，
     * 首次发现时创建记录，再次发现时重新打开并更新证据快照。
     *
     * @param PayOrder $payOrder 已确认真实成功的支付单
     * @param int $previousStatus 支付成功前的平台状态
     * @param string $sourceType 异常来源类型
     * @param int $sourceId 异常来源记录 ID
     * @return PaymentExceptionRecord 最新异常记录
     */
    public function openLateDuplicateInCurrentTransaction(
        PayOrder $payOrder,
        int $previousStatus,
        string $sourceType = 'SYSTEM',
        int $sourceId = 0
    ): PaymentExceptionRecord {
        $record = $this->exceptionRepository->findForUpdateBySubjectType(
            PaymentExceptionConstant::SUBJECT_PAY,
            (string) $payOrder->pay_no,
            PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE
        );
        $detectedAt = $record?->detected_at ?: $this->now();
        $data = [
            'biz_no' => (string) $payOrder->biz_no,
            'merchant_id' => (int) $payOrder->merchant_id,
            'severity' => PaymentExceptionConstant::SEVERITY_HIGH,
            'status' => PaymentExceptionConstant::STATUS_OPEN,
            'source_type' => mb_strcut($sourceType, 0, 32, 'UTF-8'),
            'source_id' => max(0, $sourceId),
            'summary' => '业务单已由其他支付单完成，本次真实成功款等待退款处置',
            'detail_json' => [
                'previous_status' => $previousStatus,
                'pay_amount' => (int) $payOrder->pay_amount,
                'channel_type' => (int) $payOrder->channel_type,
                'channel_id' => (int) $payOrder->channel_id,
                'channel_trade_no' => (string) $payOrder->channel_trade_no,
            ],
            'resolution' => '',
            'resolution_ref_no' => '',
            'resolver_id' => 0,
            'detected_at' => $detectedAt,
            'resolved_at' => null,
        ];

        if (!$record) {
            /** @var PaymentExceptionRecord $record */
            $record = $this->exceptionRepository->create([
                'exception_no' => $this->generateNo('EXC'),
                'subject_type' => PaymentExceptionConstant::SUBJECT_PAY,
                'subject_no' => (string) $payOrder->pay_no,
                'exception_type' => PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE,
            ] + $data);
        } else {
            $record->fill($data);
            $record->save();
        }

        return $record->refresh();
    }

    /**
     * 关闭已完成退款的重复支付异常。
     *
     * 调用方必须已开启退款成功事务。仅处于待处理或处理中状态的异常可以关闭，
     * 已关闭或不存在时返回 false，避免重复写入处置结果。
     *
     * @param string $payNo 支付单号
     * @param string $refundNo 完成处置的退款单号
     * @param int $refundedAmount 已退款金额，单位为分
     * @return bool 是否完成异常状态转换
     */
    public function resolveLateDuplicateInCurrentTransaction(
        string $payNo,
        string $refundNo,
        int $refundedAmount
    ): bool {
        $record = $this->exceptionRepository->findForUpdateBySubjectType(
            PaymentExceptionConstant::SUBJECT_PAY,
            $payNo,
            PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE
        );
        if (!$record || !PaymentExceptionConstant::isOpen((int) $record->status)) {
            return false;
        }

        $record->status = PaymentExceptionConstant::STATUS_RESOLVED;
        $record->resolution = '重复支付已足额退款';
        $record->resolution_ref_no = $refundNo;
        $record->resolver_id = 0;
        $record->resolved_at = $this->now();
        $detail = (array) ($record->detail_json ?? []);
        $detail['refunded_amount'] = $refundedAmount;
        $record->detail_json = $detail;
        $record->save();

        return true;
    }
}
