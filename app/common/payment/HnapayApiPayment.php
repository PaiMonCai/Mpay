<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\hnapay\HnapayClient;
use app\common\sdk\hnapay\HnapaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 新生支付 API 插件。
 *
 * 提供支付宝、微信和银联的扫码/JSAPI、支付宝 H5、支付通知与退款能力，并兼容
 * 扫码整数分通知和聚合支付元金额通知两种金额口径。当前没有可确认的统一查单与关单接口。
 */
class HnapayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY = 'ALIPAY';
    private const PRODUCT_WECHATPAY = 'WECHATPAY';
    private const PRODUCT_UNIONPAY = 'UNIONPAY';
    private const PRODUCT_HNA_ZFB = 'HnaZFB';

    private ?HnapayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'hnapay_api',
        'name' => '新生支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.hnapay.com/',
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
            ['type' => 'input', 'field' => 'mer_id', 'title' => '商户ID', 'value' => '', 'validate' => [['required' => true, 'message' => '商户ID不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '新生公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '新生公钥不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'input', 'field' => 'merchant_id', 'title' => '报备编号', 'value' => ''],
            ['type' => 'select', 'field' => 'interface_type', 'title' => '接口类型', 'value' => 'scan', 'options' => [['label' => '扫码支付', 'value' => 'scan'], ['label' => '公众号/生活号支付', 'value' => 'jsapi'], ['label' => '支付宝H5', 'value' => 'h5']]],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY => '支付宝扫码/JSAPI',
                self::PRODUCT_WECHATPAY => '微信扫码/JSAPI',
                self::PRODUCT_UNIONPAY => '银联扫码/JSAPI',
                self::PRODUCT_HNA_ZFB => '支付宝 H5',
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
        return $this->executeDirectPaymentProduct($order, [

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WECHATPAY,
                    'bank' => self::PRODUCT_UNIONPAY,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_HNA_ZFB,
                ],
                'handler' => fn (): array => (string) $order['pay_type_code'] === 'alipay'
                    ? $this->h5Pay($order)
                    : throw new PaymentException('新生支付当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_HNA_ZFB,
                ],
                'handler' => fn (): array => (string) $order['pay_type_code'] === 'alipay'
                ? $this->h5Pay($order)
                : throw new PaymentException('新生支付当前支付方式不支持跳转产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WECHATPAY,
                    'bank' => self::PRODUCT_UNIONPAY,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],
        ], '新生支付');
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function scanPay(array $order): array
    {
        $orgCode = $this->orgCode((string) $order['pay_type_code']);
        try {
            $data = $this->client()->scanPay([
                'merOrderNum' => (string) $order['pay_no'],
                'tranAmt' => (string) (int) $order['amount'],
                'submitTime' => substr((string) $order['pay_no'], 3, 14) ?: date('YmdHis'),
                'orgCode' => $orgCode,
                'goodsName' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'tranIP' => (string) $order['client_ip'],
                'notifyUrl' => (string) $order['callback_url'],
                'weChatMchId' => $this->configText('merchant_id'),
            ]);
        } catch (HnapaySdkException $e) {
            throw new PaymentException('新生支付下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], $orgCode, 'scanPay', ['qrcode' => (string) ($data['qrCodeUrl'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 支付宝 H5 表单支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function h5Pay(array $order): array
    {
        $html = $this->client()->h5Html((string) $order['pay_no'], [
            'tranAmt' => FormatHelper::amount((int) $order['amount']),
            'payType' => 'HnaZFB',
            'frontUrl' => (string) $order['return_url'],
            'notifyUrl' => (string) $order['callback_url'],
            'orderSubject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'merchantId' => json_encode(['02' => $this->configText('merchant_id')], JSON_UNESCAPED_UNICODE),
            'merUserIp' => (string) $order['client_ip'],
        ]);

        return $this->payResult('html', 'alipay', 'HnaZFB', 'multipay/h5', ['html' => $html], [], $order);
    }

    /**
     * 当前适配协议未提供可确认的统一主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('新生支付插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('新生支付插件暂不支持关单', 40200);
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
            $data = $this->client()->refund((string) $order['pay_no'], [
                'orgHnapayOrderId' => (string) ($order['chan_trade_no'] ?? ''),
                'refundAmt' => FormatHelper::amount((int) $order['refund_amount']),
                'notifyServerUrl' => (string) ($order['refund_callback_url'] ?? ''),
            ]);
        } catch (HnapaySdkException $e) {
            throw new PaymentUncertainException('新生支付退款结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) $order['refund_amount'],
            'chan_refund_no' => (string) ($data['hnapayOrderId'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析并验签支付回调。
     *
     * 扫码与聚合支付使用不同验签方法和金额单位，必须先按订单字段判断协议类型。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $request->post();
        $isScan = isset($payload['merOrderNum']);
        $verified = $isScan ? $this->client()->verifyScanNotify($payload) : $this->client()->verifyPayNotify($payload);
        if (!$verified) {
            throw new PaymentException('新生支付回调验签失败', 40200);
        }

        $success = (string) ($payload[$isScan ? 'respCode' : 'resultCode'] ?? '') === '0000';
        $paidAmount = null;
        if ($success) {
            $paidAmount = $isScan
                ? $this->integerCents($payload['tranAmt'] ?? null, '新生支付扫码回调金额')
                : $this->yuanToCents($payload['tranAmt'] ?? null, '新生支付回调金额');
        }

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'pay_no' => trim((string) ($payload[$isScan ? 'merOrderNum' : 'merOrderId'] ?? '')),
            'paid_amount' => $paidAmount,
            'message' => (string) ($payload[$isScan ? 'respCode' : 'resultCode'] ?? ''),
            'chan_order_no' => (string) ($payload[$isScan ? 'merOrderNum' : 'merOrderId'] ?? ''),
            'chan_trade_no' => (string) ($payload['hnapayOrderId'] ?? ''),
            'channel_status' => (string) ($payload[$isScan ? 'respCode' : 'resultCode'] ?? ''),
        ];
    }

    /**
     * 返回新生成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '200';
    }

    /**
     * 返回新生失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'sign_error';
    }

    /**
     * 读取渠道整数分金额。
     *
     * @param mixed $value 渠道金额
     * @param string $field 金额字段说明
     * @return int 金额，单位分
     */
    private function integerCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+$/', $text) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return (int) $text;
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
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $orgCode = $this->orgCode((string) $order['pay_type_code']);
        $payload = [
            'tranAmt' => FormatHelper::amount((int) $order['amount']),
            'orgCode' => $orgCode,
            'notifyServerUrl' => (string) $order['callback_url'],
            'merUserIp' => (string) $order['client_ip'],
            'goodsInfo' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'orderSubject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'merchantId' => $this->configText('merchant_id'),
        ];
        if ($orgCode === 'WECHATPAY') {
            $payload['appId'] = (string) ($payment['sub_appid'] ?? '');
            $payload['openId'] = (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? '');
        } else {
            $payload['aliAppId'] = (string) ($payment['sub_appid'] ?? '');
            $payload['buyerId'] = (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '');
        }

        try {
            $data = $this->client()->jsapiPay((string) $order['pay_no'], $payload);
        } catch (HnapaySdkException $e) {
            throw new PaymentException('新生支付JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['payInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('jsapi', (string) $order['pay_type_code'], $orgCode, 'jsapiPay', ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 将平台支付方式映射为新生机构编码。
     *
     * @param string $payType 平台支付方式编码
     * @return string 新生机构编码
     */
    private function orgCode(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'WECHATPAY',
            'bank' => 'UNIONPAY',
            default => 'ALIPAY',
        };
    }

    /**
     * 包装标准支付结果。
     *
     * @param string $page 平台承接页类型
     * @param string $payType 平台支付方式编码
     * @param string $product 新生产品编码
     * @param string $action 渠道接口动作
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function payResult(string $page, string $payType, string $product, string $action, array $payParams, array $data, array $order): array
    {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['merOrderNum'] ?? $data['merOrderId'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['hnapayOrderId'] ?? ''),
        ]);
    }

    /**
     * 获取当前通道的 SDK 客户端。
     *
     * @return HnapayClient
     */
    private function client(): HnapayClient
    {
        if ($this->client === null) {
            $this->client = new HnapayClient([
                'mer_id' => $this->configText('mer_id'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
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
