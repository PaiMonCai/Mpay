<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\EventConstant;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\repository\ops\log\PayOrderOperationLogRepository;
use app\service\account\funds\MerchantAccountService;
use app\service\payment\runtime\PaymentExceptionService;
use app\service\payment\runtime\PaymentRecoveryTaskService;
use support\Log;
use Webman\Event\Event;

/**
 * 退款单生命周期服务。
 *
 * 负责退款单创建、处理中、成功、失败和重试等状态推进。
 *
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property RefundOrderRepository $refundOrderRepository 退款单仓库
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 */
class RefundLifecycleService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param PayOrderOperationLogRepository $operationLogRepository 支付异常处置日志仓库
     * @param PaymentExceptionService $paymentExceptionService 支付业务异常服务
     * @param PaymentRecoveryTaskService $recoveryTaskService 支付恢复任务服务
     * @return void
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected MerchantAccountService $merchantAccountService,
        protected PayOrderOperationLogRepository $operationLogRepository,
        protected PaymentExceptionService $paymentExceptionService,
        protected PaymentRecoveryTaskService $recoveryTaskService
    ) {
    }

    /**
     * 标记退款处理中。
     *
     * 由渠道受理后推进到处理中态，幂等地处理重复请求。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundProcessing(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundProcessingInCurrentTransaction($refundNo, $input, false);
        });
    }

    /**
     * 退款重试。
     *
     * 仅允许失败态退款单重新推进到处理中。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function retryRefund(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundProcessingInCurrentTransaction($refundNo, $input, true);
        });
    }

    /**
     * 原子取得一次退款派发权。
     *
     * @param string $refundNo 退款单号
     * @param bool $isRetry 是否为失败单重试
     * @return array{refund_order: RefundOrder, claimed: bool}
     */
    public function claimRefundDispatch(string $refundNo, bool $isRetry = false): array
    {
        return $this->transactionRetry(function () use ($refundNo, $isRetry): array {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }

            $expectedStatus = $isRetry
                ? TradeConstant::REFUND_STATUS_FAILED
                : TradeConstant::REFUND_STATUS_CREATED;
            if ((int) $refundOrder->status !== $expectedStatus) {
                return ['refund_order' => $refundOrder, 'claimed' => false];
            }

            $refundOrder = $this->markRefundProcessingInCurrentTransaction(
                $refundNo,
                ['last_error' => ''],
                $isRetry
            );

            return ['refund_order' => $refundOrder, 'claimed' => true];
        });
    }

    /**
     * 释放尚未发送上游请求的派发权。
     *
     * @param string $refundNo 退款单号
     * @param string $reason 释放原因
     * @param int $restoreStatus 恢复状态
     * @return RefundOrder 最新退款单
     */
    public function releaseRefundDispatchClaim(
        string $refundNo,
        string $reason,
        int $restoreStatus = TradeConstant::REFUND_STATUS_CREATED
    ): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $reason, $restoreStatus): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING) {
                return $refundOrder;
            }

            if (!in_array($restoreStatus, [TradeConstant::REFUND_STATUS_CREATED, TradeConstant::REFUND_STATUS_FAILED], true)) {
                throw new BusinessStateException('退款派发权恢复状态无效', ['status' => $restoreStatus]);
            }
            $refundOrder->status = $restoreStatus;
            $refundOrder->processing_at = null;
            $refundOrder->last_error = mb_strcut($reason, 0, 255, 'UTF-8');
            $refundOrder->save();
            $this->recoveryTaskService->completeInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
                $refundNo
            );

            return $refundOrder->refresh();
        });
    }

    /**
     * 保存退款处理过程中取得的渠道退款号。
     *
     * @param string $refundNo 退款单号
     * @param string $channelRefundNo 渠道退款号
     * @return RefundOrder 最新退款单
     */
    public function recordRefundProgress(string $refundNo, string $channelRefundNo = ''): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $channelRefundNo): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((string) $refundOrder->channel_refund_no === '' && $channelRefundNo !== '') {
                $refundOrder->channel_refund_no = $channelRefundNo;
                $refundOrder->save();
            }

            return $refundOrder->refresh();
        });
    }

    /**
     * 在当前事务中标记退款处理中或重试。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @param bool $isRetry 是否来自重试流程
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markRefundProcessingInCurrentTransaction(string $refundNo, array $input = [], bool $isRetry = false): RefundOrder
    {
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $currentStatus = (int) $refundOrder->status;
        if ($currentStatus === TradeConstant::REFUND_STATUS_PROCESSING) {
            return $refundOrder;
        }

        if (TradeConstant::isRefundTerminalStatus($currentStatus)) {
            return $refundOrder;
        }

        if ($currentStatus !== TradeConstant::REFUND_STATUS_CREATED && $currentStatus !== TradeConstant::REFUND_STATUS_FAILED) {
            throw new BusinessStateException('退款单状态不允许当前操作', [
                'refund_no' => $refundNo,
                'status' => $currentStatus,
            ]);
        }

        // 退款失败后再重试时，只有失败态才允许重新推进到处理中。
        if ($currentStatus === TradeConstant::REFUND_STATUS_FAILED && !$isRetry) {
            return $refundOrder;
        }

        if ($isRetry && $currentStatus !== TradeConstant::REFUND_STATUS_FAILED) {
            return $refundOrder;
        }

        $refundOrder->status = TradeConstant::REFUND_STATUS_PROCESSING;
        $refundOrder->processing_at = $input['processing_at'] ?? $this->now();
        if (empty($refundOrder->request_at)) {
            $refundOrder->request_at = $input['request_at'] ?? $refundOrder->processing_at;
        }
        $refundOrder->last_error = mb_strcut(
            (string) ($input['last_error'] ?? $refundOrder->last_error ?? ''),
            0,
            255,
            'UTF-8'
        );
        if ($isRetry) {
            // 重试时生成新的渠道请求号，避免和上一轮失败请求混在一起。
            $refundOrder->retry_count = (int) $refundOrder->retry_count + 1;
            $refundOrder->channel_request_no = $this->generateNo('RQR');
        }

        $extJson = (array) $refundOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
            // 把处理/重试原因单独保留到扩展字段里，便于后台排查。
            $extJson[$isRetry ? 'retry_reason' : 'processing_reason'] = $reason;
        }
        $refundOrder->ext_json = array_merge($extJson, $input['ext_json'] ?? []);
        $refundOrder->save();
        $this->recoveryTaskService->scheduleInCurrentTransaction(
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
            $refundNo,
            60,
            0,
            0,
            $isRetry
        );

        return $refundOrder->refresh();
    }

    /**
     * 退款成功。
     *
     * 成功后会推进退款单状态，并在平台代收场景下做余额冲减或结算逆向处理。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundSuccess(string $refundNo, array $input = []): RefundOrder
    {
        $shouldDispatchEvent = false;

        $refundOrder = $this->transactionRetry(function () use ($refundNo, $input, &$shouldDispatchEvent) {
            return $this->markRefundSuccessInCurrentTransaction($refundNo, $input, $shouldDispatchEvent);
        });

        if ($shouldDispatchEvent) {
            $this->dispatchRefundOrderEvent(EventConstant::REFUND_ORDER_SUCCEEDED, $refundOrder);
        }

        return $refundOrder;
    }

    /**
     * 在当前事务中标记退款成功。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markRefundSuccessInCurrentTransaction(string $refundNo, array $input = [], bool &$shouldDispatchEvent = false): RefundOrder
    {
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $currentStatus = (int) $refundOrder->status;
        if ($currentStatus === TradeConstant::REFUND_STATUS_SUCCESS) {
            return $refundOrder;
        }

        if (TradeConstant::isRefundTerminalStatus($currentStatus)) {
            return $refundOrder;
        }

        // 先锁定原支付单，避免退款推进时原单状态被并发修改。
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo((string) $refundOrder->pay_no);
        if (!$payOrder || (int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new BusinessStateException('原支付单状态不允许退款', [
                'refund_no' => $refundNo,
                'pay_no' => (string) $refundOrder->pay_no,
            ]);
        }

        $traceNo = (string) ($refundOrder->trace_no ?: $refundOrder->biz_no);
        $accountReverseStatus = TradeConstant::REFUND_ACCOUNT_REVERSE_NOT_REQUIRED;
        $accountReverseRequiredAmount = 0;
        $accountReverseCollectedAmount = 0;
        $accountReverseDueAmount = 0;
        $accountReverseRecordedAt = null;
        $accountReverseRecoveredAt = null;
        if ((int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT) {
            if ((int) $payOrder->settlement_status === TradeConstant::SETTLEMENT_STATUS_SETTLED) {
                // 平台代收退款在已结算时，需要同步冲减商户可提现余额，口径按本次退款净额处理。
                $reverseAmount = max(0, (int) $refundOrder->refund_amount - (int) $refundOrder->fee_reverse_amount);
                if ($reverseAmount > 0) {
                    $accountReverseRequiredAmount = $reverseAmount;
                    $accountReverseCollectedAmount = $this->merchantAccountService->debitAvailableUpToInCurrentTransaction(
                        (int) $refundOrder->merchant_id,
                        $reverseAmount,
                        (string) $refundOrder->refund_no,
                        'REFUND_REVERSE_INITIAL:' . (string) $refundOrder->refund_no,
                        [
                            'pay_no' => (string) $refundOrder->pay_no,
                            'remark' => '上游退款成功后商户余额冲减',
                        ],
                        $traceNo
                    );
                    $accountReverseDueAmount = max(0, $reverseAmount - $accountReverseCollectedAmount);
                    $accountReverseRecordedAt = $this->now();
                    $accountReverseStatus = $accountReverseDueAmount > 0
                        ? TradeConstant::REFUND_ACCOUNT_REVERSE_DUE
                        : TradeConstant::REFUND_ACCOUNT_REVERSE_RECOVERED;
                    $accountReverseRecoveredAt = $accountReverseDueAmount === 0 ? $accountReverseRecordedAt : null;

                    if ($accountReverseDueAmount > 0) {
                        Log::critical(sprintf(
                            '[RefundLifecycle] 上游退款成功但本地余额冲减不足 refund_no=%s merchant_id=%d required=%d collected=%d due=%d',
                            (string) $refundOrder->refund_no,
                            (int) $refundOrder->merchant_id,
                            $reverseAmount,
                            $accountReverseCollectedAmount,
                            $accountReverseDueAmount
                        ));
                    }
                }
            }
        }

        // 退款成功后，退款单和业务单都要同步收口到成功态。
        $refundOrder->status = TradeConstant::REFUND_STATUS_SUCCESS;
        $refundOrder->succeeded_at = $input['succeeded_at'] ?? $this->now();
        $refundOrder->channel_refund_no = (string) ($input['channel_refund_no'] ?? $refundOrder->channel_refund_no ?? '');
        $refundOrder->last_error = '';
        $refundOrder->account_reverse_status = $accountReverseStatus;
        $refundOrder->account_reverse_required_amount = $accountReverseRequiredAmount;
        $refundOrder->account_reverse_collected_amount = $accountReverseCollectedAmount;
        $refundOrder->account_reverse_due_amount = $accountReverseDueAmount;
        $refundOrder->account_reverse_recorded_at = $accountReverseRecordedAt;
        $refundOrder->account_reverse_recovered_at = $accountReverseRecoveredAt;
        $refundOrder->ext_json = array_merge((array) $refundOrder->ext_json, $input['ext_json'] ?? []);
        $refundOrder->save();
        $this->recoveryTaskService->completeInCurrentTransaction(
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
            $refundNo
        );
        if ($accountReverseDueAmount > 0) {
            $this->recoveryTaskService->scheduleInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACCOUNT_REVERSE,
                $refundNo,
                60
            );
        } else {
            $this->recoveryTaskService->completeInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACCOUNT_REVERSE,
                $refundNo
            );
        }

        $bizOrder = $this->bizOrderRepository->findForUpdateByBizNo((string) $refundOrder->biz_no);
        if ($bizOrder) {
            $bizOrder->refund_amount = (int) $bizOrder->refund_amount + (int) $refundOrder->refund_amount;
            if ((int) $bizOrder->refund_amount > (int) $bizOrder->order_amount) {
                Log::critical(sprintf(
                    '[RefundLifecycle] 实际累计退款超过订单金额 refund_no=%s pay_no=%s refund_amount=%d order_amount=%d',
                    (string) $refundOrder->refund_no,
                    (string) $refundOrder->pay_no,
                    (int) $bizOrder->refund_amount,
                    (int) $bizOrder->order_amount
                ));
            }
            if (empty($bizOrder->trace_no)) {
                $bizOrder->trace_no = $traceNo;
            }
            $bizOrder->save();
        }

        $this->resolveLateDuplicateAfterRefund($payOrder, $refundOrder);

        $shouldDispatchEvent = true;

        return $refundOrder->refresh();
    }

    /**
     * 当重复支付已足额退款时关闭异常处置记录。
     *
     * @param \app\model\payment\PayOrder $payOrder 原支付单
     * @param RefundOrder $refundOrder 本次退款单
     * @return void
     */
    private function resolveLateDuplicateAfterRefund(\app\model\payment\PayOrder $payOrder, RefundOrder $refundOrder): void
    {
        $refundedAmount = (int) $this->refundOrderRepository->query()
            ->where('pay_no', (string) $payOrder->pay_no)
            ->where('status', TradeConstant::REFUND_STATUS_SUCCESS)
            ->sum('refund_amount');
        if ($refundedAmount < (int) $payOrder->pay_amount) {
            return;
        }

        if (!$this->paymentExceptionService->resolveLateDuplicateInCurrentTransaction(
            (string) $payOrder->pay_no,
            (string) $refundOrder->refund_no,
            $refundedAmount
        )) {
            return;
        }

        $this->operationLogRepository->create([
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'action' => 'late_duplicate_refunded',
            'admin_id' => 0,
            'reason' => '重复支付已足额退款',
            'result_status' => 'success',
            'result_message' => '重复支付异常处置已闭环',
            'result_payload' => [
                'refund_no' => (string) $refundOrder->refund_no,
                'refunded_amount' => $refundedAmount,
            ],
            'created_at' => $this->now(),
        ]);
    }

    /**
     * 追缴上游已退款但本地尚未完成的商户余额冲减。
     *
     * @param string $refundNo 退款单号
     * @return array{refund_order:RefundOrder,recovered:bool}
     */
    public function recoverRefundAccountReverse(string $refundNo): array
    {
        return $this->transactionRetry(function () use ($refundNo): array {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_SUCCESS) {
                return ['refund_order' => $refundOrder, 'recovered' => false];
            }

            $dueAmount = max(0, (int) $refundOrder->account_reverse_due_amount);
            if ((int) $refundOrder->account_reverse_status !== TradeConstant::REFUND_ACCOUNT_REVERSE_DUE || $dueAmount <= 0) {
                return ['refund_order' => $refundOrder, 'recovered' => true];
            }

            $traceNo = (string) ($refundOrder->trace_no ?: $refundOrder->biz_no);
            $collectedBefore = max(0, (int) $refundOrder->account_reverse_collected_amount);
            $collectedNow = $this->merchantAccountService->debitAvailableUpToInCurrentTransaction(
                (int) $refundOrder->merchant_id,
                $dueAmount,
                (string) $refundOrder->refund_no,
                'REFUND_REVERSE_RECOVERY:' . (string) $refundOrder->refund_no . ':' . $collectedBefore,
                [
                    'pay_no' => (string) $refundOrder->pay_no,
                    'remark' => '退款成功账务差额追缴',
                ],
                $traceNo
            );
            $remainingDue = max(0, $dueAmount - $collectedNow);
            $refundOrder->account_reverse_collected_amount = $collectedBefore + $collectedNow;
            $refundOrder->account_reverse_due_amount = $remainingDue;
            if ($remainingDue === 0) {
                $refundOrder->account_reverse_status = TradeConstant::REFUND_ACCOUNT_REVERSE_RECOVERED;
                $refundOrder->account_reverse_recovered_at = $this->now();
            }
            $refundOrder->save();

            return ['refund_order' => $refundOrder->refresh(), 'recovered' => $remainingDue === 0];
        });
    }

    /**
     * 记录退款主动查单调度结果。
     *
     * @param string $refundNo 退款单号
     * @param string $result 查询结果摘要
     * @param string $error 错误摘要
     * @param int $delaySeconds 下次查询延迟秒数
     * @return RefundOrder 最新退款单
     */
    public function recordRefundQueryAttempt(
        string $refundNo,
        string $result,
        string $error = '',
        int $delaySeconds = 60
    ): RefundOrder {
        return $this->transactionRetry(function () use ($refundNo, $result, $error): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING) {
                return $refundOrder;
            }

            $summary = $error !== '' ? $error : ($result === 'pending' ? '渠道退款仍在处理中' : '');
            $refundOrder->last_error = mb_strcut($summary, 0, 255, 'UTF-8');
            $refundOrder->save();

            return $refundOrder->refresh();
        });
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
        $shouldDispatchEvent = false;

        $refundOrder = $this->transactionRetry(function () use ($refundNo, $input, &$shouldDispatchEvent) {
            return $this->markRefundFailedInCurrentTransaction($refundNo, $input, $shouldDispatchEvent);
        });

        if ($shouldDispatchEvent) {
            $this->dispatchRefundOrderEvent(EventConstant::REFUND_ORDER_FAILED, $refundOrder);
        }

        return $refundOrder;
    }

    /**
     * 在当前事务中标记退款失败。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markRefundFailedInCurrentTransaction(string $refundNo, array $input = [], bool &$shouldDispatchEvent = false): RefundOrder
    {
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $currentStatus = (int) $refundOrder->status;
        if ($currentStatus === TradeConstant::REFUND_STATUS_FAILED) {
            return $refundOrder;
        }

        if (TradeConstant::isRefundTerminalStatus($currentStatus)) {
            return $refundOrder;
        }

        if ($currentStatus !== TradeConstant::REFUND_STATUS_CREATED && $currentStatus !== TradeConstant::REFUND_STATUS_PROCESSING) {
            throw new BusinessStateException('退款单状态不允许当前操作', [
                'refund_no' => $refundNo,
                'status' => $currentStatus,
            ]);
        }

        // 失败状态只更新失败信息，不再改动原支付单和业务单。
        $refundOrder->status = TradeConstant::REFUND_STATUS_FAILED;
        $refundOrder->failed_at = $input['failed_at'] ?? $this->now();
        $refundOrder->channel_refund_no = (string) ($input['channel_refund_no'] ?? $refundOrder->channel_refund_no ?? '');
        $refundOrder->last_error = mb_strcut(
            (string) ($input['last_error'] ?? $refundOrder->last_error ?? ''),
            0,
            255,
            'UTF-8'
        );
        $extJson = (array) $refundOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
            // 失败原因也放进扩展字段，方便后台对比渠道返回和内部处理结果。
            $extJson['fail_reason'] = $reason;
        }
        $refundOrder->ext_json = array_merge($extJson, $input['ext_json'] ?? []);
        $refundOrder->save();
        $this->recoveryTaskService->completeInCurrentTransaction(
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
            $refundNo
        );
        $shouldDispatchEvent = true;

        return $refundOrder->refresh();
    }

    /**
     * 发送退款单事件。
     *
     * @param string $eventName 事件名称
     * @param RefundOrder $refundOrder 退款单
     * @return void
     */
    private function dispatchRefundOrderEvent(string $eventName, RefundOrder $refundOrder): void
    {
        Event::dispatch($eventName, [
            'refund_no' => (string) $refundOrder->refund_no,
            'pay_no' => (string) $refundOrder->pay_no,
            'biz_no' => (string) $refundOrder->biz_no,
            'refund_order' => $refundOrder,
        ]);
    }

}
