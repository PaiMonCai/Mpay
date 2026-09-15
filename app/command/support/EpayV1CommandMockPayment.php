<?php

declare(strict_types=1);

namespace app\command\support;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\AuthConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\service\payment\epay\EpaySignerManager;
use support\Request;
use support\Response;

/**
 * ePay V1 命令测试支付桩。
 */
class EpayV1CommandMockPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    protected array $paymentInfo = [
        'code' => 'epay_v1_command_mock',
        'name' => 'ePay V1 命令测试桩',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    public function pay(array $order): array
    {
        $payNo = (string) $order['pay_no'];
        $payUrl = rtrim((string) $this->getConfig('mock_jump_base_url', 'https://mock.epay.test/v1/pay'), '/') . '/' . rawurlencode($payNo);

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jump',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => (string) $order['pay_type_code'],
            'pay_action' => 'commandMockPay',
            'pay_params' => [
                'url' => $payUrl,
                'raw' => [
                    'trade_no' => $payNo,
                    'payurl' => $payUrl,
                ],
            ],
            'chan_order_no' => $payNo,
            'chan_trade_no' => $payNo,
        ]);
    }

    public function query(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => (string) $order['pay_no'],
            'paid_amount' => (int) $order['amount'],
            'chan_order_no' => (string) ($order['chan_order_no'] ?? $order['pay_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? $order['chan_order_no'] ?? $order['pay_no'] ?? ''),
            'channel_status' => '1',
            'message' => 'mock success',
            'paid_at' => FormatHelper::dateTime(time()),
        ];
    }

    public function close(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => (string) $order['pay_no'],
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => 'mock closed',
        ];
    }

    public function refund(array $order): array
    {
        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) $order['refund_amount'],
            'chan_refund_no' => (string) ($order['refund_no'] ?? ''),
            'message' => '退款成功',
        ];
    }

    public function notify(Request $request): array
    {
        $payload = (array) $request->all();
        $sign = (string) ($payload['sign'] ?? '');
        /** @var EpaySignerManager $signerManager */
        $signerManager = container_get(EpaySignerManager::class);
        if ($sign === '' || !$signerManager->verify($payload, AuthConstant::API_SIGN_NAME_MD5, $sign, (string) $this->getConfig('api_key'))) {
            throw new PaymentException('上游 V1 回调验签失败', 40200);
        }

        $tradeStatus = strtoupper((string) ($payload['trade_status'] ?? ''));

        return [
            'status' => $tradeStatus === NotifyConstant::EPAY_TRADE_STATUS_SUCCESS
                ? PaymentPluginStatusConstant::SUCCESS
                : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($payload['out_trade_no'] ?? '')),
            'paid_amount' => $tradeStatus === NotifyConstant::EPAY_TRADE_STATUS_SUCCESS
                ? $this->yuanToCents($payload['money'] ?? null)
                : null,
            'message' => $tradeStatus,
            'chan_order_no' => (string) ($payload['trade_no'] ?? ''),
            'chan_trade_no' => (string) ($payload['trade_no'] ?? ''),
            'channel_status' => $tradeStatus,
        ];
    }

    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 将两位小数元金额转换为整数分。
     */
    private function yuanToCents(mixed $value): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentUncertainException('上游 V1 回调金额格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }
}
