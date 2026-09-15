<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\PaymentPluginStatusConstant;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\ResourceNotFoundException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\runtime\PaymentPluginManager;
use support\Log;
use Throwable;

/**
 * 支付渠道单据拉起服务。
 *
 * 负责调用第三方插件、校验标准结果并推进同步支付状态。
 *
 * @property PaymentPluginManager $paymentPluginManager 支付插件管理器
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
 */
class PayOrderChannelDispatchService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
     */
    public function __construct(
        protected PaymentPluginManager $paymentPluginManager,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderLifecycleService $payOrderLifecycleService
    ) {}

    /**
     * 拉起第三方支付单并回写渠道响应。
     *
     * @param PayOrder $payOrder 支付订单
     * @param BizOrder $bizOrder 业务订单
     * @param PaymentChannel $channel 渠道
     * @param Merchant $merchant 商户
     * @return array 拉起结果
     * @throws ResourceNotFoundException
     * @throws PaymentException
     */
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel, Merchant $merchant): array
    {
        $pluginPayPayload = $this->buildPluginPayPayload($payOrder, $bizOrder, $merchant);
        $pluginPayResult = $this->callPluginPay($payOrder, $channel, $pluginPayPayload);
        $payOrder = $this->applyPluginPayResult($payOrder, $pluginPayResult);
        $presentation = (array) ($pluginPayResult['presentation'] ?? []);

        return [
            'pay_order' => $payOrder,
            'payment_result' => $pluginPayResult,
            'pay_params' => (array) ($presentation['pay_params'] ?? []),
        ];
    }

    /**
     * 调用插件下单并校验返回结构。
     *
     * @param PayOrder $payOrder 支付单
     * @param PaymentChannel $channel 支付通道
     * @param array<string, mixed> $payload 插件下单参数
     * @return array<string, mixed>
     * @throws PaymentException
     */
    private function callPluginPay(PayOrder $payOrder, PaymentChannel $channel, array $payload): array
    {
        try {
            $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $payOrder->pay_type_id);
        } catch (PaymentException $e) {
            $exception = $e instanceof PaymentDefinitiveException || $e instanceof UnsupportedPaymentOperationException
                ? $e
                : new PaymentDefinitiveException(
                    $e->getMessage(),
                    (int) ($e->getCode() ?: 40200),
                    $e->getData()
                );
            throw $this->recordPluginPayFailure($payOrder, $exception);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 插件初始化失败 pay_no=%s channel_id=%d exception=%s error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                get_class($e),
                $e->getMessage()
            ));
            throw $this->recordPluginPayFailure($payOrder, new PaymentDefinitiveException(
                '支付插件初始化失败',
                40200,
                [
                    'channel_error_code' => 'PLUGIN_INITIALIZE_ERROR',
                    'exception_class' => get_class($e),
                ]
            ));
        }

        try {
            $this->persistProvisionalPaymentContext($payOrder, $payload);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 支付外呼上下文预写失败 pay_no=%s channel_id=%d error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                $e->getMessage()
            ));
            throw $this->recordPluginPayFailure($payOrder, new PaymentDefinitiveException(
                '支付请求准备失败，请稍后重试',
                40200,
                ['channel_error_code' => 'PAYMENT_CONTEXT_PERSIST_FAILED']
            ));
        }

        try {
            $result = $plugin->pay($payload);

            $validated = PaymentPluginPayResultValidator::make($result)
                ->withScene('pay_result')
                ->withException(PaymentException::class)
                ->validate();

            if (!hash_equals((string) $payOrder->pay_no, (string) $validated['pay_no'])) {
                throw new PaymentUncertainException('插件下单返回的支付单号不匹配', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                ]);
            }
            if ((string) $validated['status'] === PaymentPluginStatusConstant::SUCCESS
                && (int) $validated['paid_amount'] !== (int) $payOrder->pay_amount) {
                throw new PaymentUncertainException('插件下单返回的实付金额不匹配', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                ]);
            }
            if ((string) $validated['status'] === PaymentPluginStatusConstant::SUCCESS
                && trim((string) ($validated['chan_order_no'] ?? '')) === ''
                && trim((string) ($validated['chan_trade_no'] ?? '')) === '') {
                throw new PaymentUncertainException('插件下单成功结果缺少渠道流水号', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                ]);
            }

            return $validated;
        } catch (PaymentDefinitiveException|UnsupportedPaymentOperationException $e) {
            throw $this->recordPluginPayFailure($payOrder, $e);
        } catch (PaymentUncertainException $e) {
            $this->persistUncertainPaymentContext($payOrder, $e);
            throw $this->withPayNo($payOrder, $e);
        } catch (PaymentException $e) {
            $exception = new PaymentUncertainException(
                $e->getMessage(),
                (int) ($e->getCode() ?: 40200),
                $e->getData()
            );
            $this->persistUncertainPaymentContext($payOrder, $exception);
            throw $this->withPayNo($payOrder, $exception);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 插件下单异常 pay_no=%s channel_id=%d exception=%s error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                get_class($e),
                $e->getMessage()
            ));
            $exception = new PaymentUncertainException('创建第三方支付订单结果不确定', 40200, [
                'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                'exception_class' => get_class($e),
            ]);
            throw $this->withPayNo($payOrder, $exception);
        }
    }

    /**
     * 构建插件下单参数。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder $bizOrder 业务单
     * @param Merchant $merchant 商户
     * @return array<string, mixed>
     */
    private function buildPluginPayPayload(PayOrder $payOrder, BizOrder $bizOrder, Merchant $merchant): array
    {
        $paymentType = $this->paymentTypeRepository->find((int) $payOrder->pay_type_id);

        return [
            'pay_no' => $payOrder->pay_no,
            'biz_no' => $payOrder->biz_no,
            'trace_no' => $payOrder->trace_no,
            'channel_request_no' => $payOrder->channel_request_no,
            'merchant_id' => (int) $payOrder->merchant_id,
            'merchant_no' => $merchant->merchant_no,
            'pay_type_id' => (int) $payOrder->pay_type_id,
            'pay_type_code' => $paymentType->code,
            'amount' => (int) $payOrder->pay_amount,
            'subject' => $bizOrder->subject,
            'body' => $bizOrder->body,
            'callback_url' => rtrim(sys_config('site_url'), '/') . '/api/pay/' . $payOrder->pay_no . '/callback',
            'notify_url' => $payOrder->notify_url,
            'return_url' => $this->resolveReturnUrl($payOrder, $bizOrder),
            'client_ip' => $payOrder->client_ip,
            '_env' => (string) (($payOrder->device ?? '') ?: 'pc'),
            'extra' => (array) ($payOrder->ext_json ?? []),
        ];
    }

    /**
     * 解析传给支付插件的同步跳转地址。
     *
     * 页面跳转支付已要求商户传 return_url；API 下单允许为空，这里兜底为平台支付承接页，
     * 避免上游 ePay 类插件收到空同步地址。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder $bizOrder 业务单
     * @return string 同步跳转地址
     */
    private function resolveReturnUrl(PayOrder $payOrder, BizOrder $bizOrder): string
    {
        $returnUrl = trim((string) ($payOrder->return_url ?? ''));
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        $returnUrl = trim((string) ($bizOrder->return_url ?? ''));
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        $siteUrl = rtrim((string) sys_config('site_url'), '/');
        $path = '/payment/' . rawurlencode((string) $payOrder->pay_no);

        return $siteUrl !== '' ? $siteUrl . $path : $path;
    }

    /**
     * 按插件标准结果写回渠道信息并推进同步成功状态。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $pluginPayResult 插件支付结果
     * @return PayOrder 支付单
     */
    private function applyPluginPayResult(PayOrder $payOrder, array $pluginPayResult): PayOrder
    {
        if ((string) $pluginPayResult['status'] === PaymentPluginStatusConstant::SUCCESS) {
            return $this->payOrderLifecycleService->markPaySuccess((string) $payOrder->pay_no, [
                'channel_order_no' => (string) ($pluginPayResult['chan_order_no'] ?? ''),
                'channel_trade_no' => (string) ($pluginPayResult['chan_trade_no'] ?? ''),
                'paid_at' => $this->now(),
                'ext_json' => [
                    'payment_context' => $this->paymentContext($pluginPayResult),
                ],
            ]);
        }

        return $this->transactionRetry(function () use ($payOrder, $pluginPayResult) {
            $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
            if (!$latest) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => (string) $payOrder->pay_no]);
            }

            $latest->channel_order_no = (string) ($pluginPayResult['chan_order_no'] ?? $latest->channel_order_no ?? '');
            $latest->channel_trade_no = (string) ($pluginPayResult['chan_trade_no'] ?? $latest->channel_trade_no ?? '');
            $extJson = (array) $latest->ext_json;
            $extJson['presentation'] = (array) $pluginPayResult['presentation'];
            $extJson['payment_context'] = $this->paymentContext($pluginPayResult);
            $latest->ext_json = $extJson;
            $latest->save();

            return $latest->refresh();
        });
    }

    /**
     * 提取插件明确返回的支付产品上下文。
     *
     * @param array<string, mixed> $pluginPayResult 标准支付结果
     * @return array<string, mixed>
     */
    private function paymentContext(array $pluginPayResult): array
    {
        return [
            'pay_type' => (string) $pluginPayResult['pay_type'],
            'pay_product' => (string) $pluginPayResult['pay_product'],
            'pay_action' => (string) ($pluginPayResult['pay_action'] ?? ''),
            'channel_context' => (array) ($pluginPayResult['channel_context'] ?? []),
        ];
    }

    /**
     * 在调用上游前持久化最小查单上下文。
     *
     * 外呼发生超时、断连或响应解析失败时，正常支付结果不会返回；预写快照保证
     * 运行时仍能按原支付单、原通道和原支付方式执行只读查单。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $payload 插件下单参数
     * @return void
     */
    private function persistProvisionalPaymentContext(PayOrder $payOrder, array $payload): void
    {
        $this->transactionRetry(function () use ($payOrder, $payload): void {
            $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
            if (!$latest) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => (string) $payOrder->pay_no]);
            }

            $extJson = (array) ($latest->ext_json ?? []);
            $existing = (array) ($extJson['payment_context'] ?? []);
            if (trim((string) ($existing['pay_type'] ?? '')) !== ''
                && trim((string) ($existing['pay_product'] ?? '')) !== '') {
                return;
            }

            $extJson['payment_context'] = array_merge($existing, [
                'pay_type' => trim((string) ($payload['pay_type_code'] ?? '')),
                'pay_product' => trim((string) ($existing['pay_product'] ?? '')),
                'pay_action' => trim((string) ($existing['pay_action'] ?? '')),
                'channel_context' => (array) ($existing['channel_context'] ?? []),
                '_provisional' => true,
                '_prepared_at' => $this->now(),
            ]);
            $latest->ext_json = $extJson;
            $latest->save();
        });
    }

    /**
     * 将插件在外呼前已经确定的产品写回不确定态上下文。
     *
     * @param PayOrder $payOrder 支付单
     * @param PaymentException $exception 不确定异常
     * @return void
     */
    private function persistUncertainPaymentContext(PayOrder $payOrder, PaymentException $exception): void
    {
        $data = $exception->getData();
        $payProduct = trim((string) ($data['pay_product'] ?? ''));
        if ($payProduct === '') {
            return;
        }

        try {
            $this->transactionRetry(function () use ($payOrder, $payProduct, $data): void {
                $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
                if (!$latest) {
                    return;
                }

                $extJson = (array) ($latest->ext_json ?? []);
                $context = (array) ($extJson['payment_context'] ?? []);
                $context['pay_type'] = trim((string) ($data['pay_type'] ?? $context['pay_type'] ?? ''));
                $context['pay_product'] = $payProduct;
                $context['pay_action'] = trim((string) ($data['pay_action'] ?? $context['pay_action'] ?? ''));
                $context['channel_context'] = (array) ($data['channel_context'] ?? $context['channel_context'] ?? []);
                $context['_provisional'] = true;
                $context['_uncertain_at'] = $this->now();
                $extJson['payment_context'] = $context;
                $latest->ext_json = $extJson;
                $latest->save();
            });
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 支付不确定态产品上下文写入失败 pay_no=%s error=%s',
                (string) $payOrder->pay_no,
                $e->getMessage()
            ));
        }
    }

    /**
     * 记录插件下单失败，并返回带支付单号的异常。
     *
     * @param PayOrder $payOrder 支付单
     * @param PaymentException $e 支付异常
     * @return PaymentException 可继续抛给入口层的异常
     */
    private function recordPluginPayFailure(PayOrder $payOrder, PaymentException $e): PaymentException
    {
        $data = $e->getData();
        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
        $message = $message !== '' ? $message : '支付通道返回异常';
        $code = (string) ($data['channel_error_code'] ?? ($e->getCode() ?: 'PLUGIN_PAY_FAILED'));

        $this->payOrderLifecycleService->markPayFailed((string) $payOrder->pay_no, [
            'channel_error_msg' => $message,
            'channel_error_code' => $code,
        ]);

        $data['pay_no'] = (string) $payOrder->pay_no;

        $class = $e::class;

        return new $class(
            $message,
            (int) ($e->getCode() ?: 40200),
            $data
        );
    }

    /**
     * 在异常上下文中补充支付单号，不改变异常语义。
     */
    private function withPayNo(PayOrder $payOrder, PaymentException $exception): PaymentException
    {
        $data = $exception->getData();
        $data['pay_no'] = (string) $payOrder->pay_no;

        $class = $exception::class;

        return new $class(
            $exception->getMessage(),
            (int) ($exception->getCode() ?: 40200),
            $data
        );
    }
}
