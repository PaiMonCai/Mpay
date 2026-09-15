<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\heepay\HeepayClient;
use app\common\sdk\heepay\HeepaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 汇付宝支付 API 插件。
 *
 * 提供支付宝、微信和银联跳转支付、支付通知与退款能力，并将不同终端场景映射为
 * 汇付宝产品编码。当前适配协议没有可确认的主动查单和关单接口。
 */
class HeepayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_CODE_22 = '22';
    private const PRODUCT_CODE_30 = '30';
    private const PRODUCT_CODE_34 = '34';
    private const PRODUCT_CODE_20 = '20';
    private const PRODUCT_CODE_64 = '64';

    private ?HeepayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'heepay_api',
        'name' => '汇付宝支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.heepay.com/',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 获取后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            ['type' => 'input', 'field' => 'agent_id', 'title' => '商户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户编号不能为空']]],
            ['type' => 'input', 'field' => 'ref_agent_id', 'title' => '二级商户号', 'value' => ''],
            ['type' => 'input', 'field' => 'bank_id', 'title' => '上游商户BankId', 'value' => ''],
            ['type' => 'password', 'field' => 'pay_key', 'title' => '支付密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '支付密钥不能为空']]],
            ['type' => 'password', 'field' => 'refund_key', 'title' => '退款密钥', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_CODE_22 => '支付宝支付',
                self::PRODUCT_CODE_30 => '微信支付',
                self::PRODUCT_CODE_34 => '银联 H5',
                self::PRODUCT_CODE_20 => '银联网页',
                self::PRODUCT_CODE_64 => '银联扫码',
            ]),
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];

        return $this->executeDirectPaymentProduct($order, [
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_22,
                    'wxpay' => self::PRODUCT_CODE_30,
                    'bank' => self::PRODUCT_CODE_34,
                ],
                'handler' => fn (): array => $this->jumpPay($order, match ($payType) {
                    'bank' => '34',
                    'wxpay' => '30',
                    default => '22',
                }),
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_22,
                    'wxpay' => self::PRODUCT_CODE_30,
                    'bank' => self::PRODUCT_CODE_34,
                ],
                'handler' => fn (): array => $this->jumpPay($order, match ($payType) {
                    'bank' => '34',
                    'wxpay' => '30',
                    default => '22',
                }),
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_22,
                    'wxpay' => self::PRODUCT_CODE_30,
                    'bank' => self::PRODUCT_CODE_20,
                ],
                'handler' => fn (): array => $this->jumpPay($order, $payType === 'bank' ? '20' : ($payType === 'wxpay' ? '30' : '22')),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_22,
                    'wxpay' => self::PRODUCT_CODE_30,
                    'bank' => self::PRODUCT_CODE_64,
                ],
                'handler' => fn (): array => $this->jumpPay($order, $payType === 'bank' ? '64' : ($payType === 'wxpay' ? '30' : '22')),
            ],
        ], '汇付宝');
    }

    /**
     * 构建汇付宝跳转支付地址。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payCode 汇付宝支付产品编码
     * @return array<string, mixed> 标准支付结果
     */
    private function jumpPay(array $order, string $payCode): array
    {
        $payType = (string) $order['pay_type_code'];
        $url = $this->client()->payUrl($this->basePayload($order) + ['pay_type' => $payCode], $payCode === '20');

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jump',
            'pay_type' => $payType,
            'pay_product' => $payCode,
            'pay_action' => 'Payment/Index',
            'pay_params' => ['url' => $url],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('汇付宝插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('汇付宝插件暂不支持关单', 40200);
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        try {
            $data = $this->client()->refund([
                'pay_no' => (string) $order['pay_no'],
                'refund_no' => (string) $order['refund_no'],
                'amount' => (int) $order['amount'],
                'refund_amount' => (int) $order['refund_amount'],
                'notify_url' => (string) ($order['refund_callback_url'] ?? ''),
            ]);
        } catch (HeepaySdkException $e) {
            throw new PaymentUncertainException('汇付宝退款结果不确定：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['ret_code'] ?? '') !== '0000') {
            throw new PaymentDefinitiveException((string) ($data['ret_msg'] ?? '汇付宝退款失败'), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) $order['refund_amount'],
            'chan_refund_no' => '',
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析并验签支付回调。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $request->get();
        if (!$this->client()->verifyNotify($payload)) {
            throw new PaymentException('汇付宝回调验签失败', 40200);
        }

        $success = (string) ($payload['result'] ?? '') === '1';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'pay_no' => trim((string) ($payload['agent_bill_id'] ?? '')),
            'paid_amount' => $success ? $this->yuanToCents($payload['pay_amt'] ?? null, '汇付宝回调金额') : null,
            'message' => (string) ($payload['result'] ?? ''),
            'chan_order_no' => (string) ($payload['agent_bill_id'] ?? ''),
            'chan_trade_no' => (string) ($payload['jnet_bill_no'] ?? ''),
            'channel_status' => (string) ($payload['result'] ?? ''),
        ];
    }

    /**
     * 返回汇付宝成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'ok';
    }

    /**
     * 返回汇付宝失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'error';
    }

    /**
     * 将渠道元金额转换为整数分。
     *
     * @param mixed $value 渠道金额
     * @param string $field 金额字段说明
     * @return int 金额，单位分
     */
    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }

    /**
     * 构造支付参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 渠道支付参数
     */
    private function basePayload(array $order): array
    {
        return [
            'pay_no' => (string) $order['pay_no'],
            'amount' => (int) $order['amount'],
            'notify_url' => (string) $order['callback_url'],
            'return_url' => (string) $order['return_url'],
            'client_ip' => (string) $order['client_ip'],
            'subject' => (string) $order['subject'],
        ];
    }

    /**
     * 获取当前通道的 SDK 客户端。
     *
     * @return HeepayClient
     */
    private function client(): HeepayClient
    {
        if ($this->client === null) {
            $this->client = new HeepayClient([
                'agent_id' => $this->configText('agent_id'),
                'ref_agent_id' => $this->configText('ref_agent_id'),
                'bank_id' => $this->configText('bank_id'),
                'pay_key' => $this->configText('pay_key'),
                'refund_key' => $this->configText('refund_key'),
            ]);
        }

        return $this->client;
    }

    /**
     * 获取字符串配置。
     *
     * @param string $key 配置键
     * @return string 配置值
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
