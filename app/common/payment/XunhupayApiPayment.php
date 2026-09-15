<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\xunhupay\XunhupayClient;
use app\common\sdk\xunhupay\XunhupaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\repository\payment\trade\PayOrderRepository;
use support\Request;
use support\Response;

/**
 * 虎皮椒支付 API v1.1 插件。
 *
 * 支付宝和微信共享同一套虎皮椒接口，PC 固定使用二维码图片中解析出的真实
 * 二维码内容，非 PC 环境使用 WAP 跳转地址。
 */
class XunhupayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_WECHAT_H5 = 'wechat_h5';
    private const PRODUCT_ALIPAY = 'alipay';
    private const PRODUCT_WECHAT = 'wechat';

    private ?XunhupayClient $client = null;

    protected array $paymentInfo = [
        'code' => 'xunhupay_api',
        'name' => '虎皮椒支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.xunhupay.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造虎皮椒支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付通知归属校验所用仓储；为空时创建默认实例
     */
    public function __construct(private ?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository ??= new PayOrderRepository();
    }

    /**
     * 初始化通道配置并丢弃上一次创建的客户端实例。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;
    }

    /**
     * 获取后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'appid',
                'title' => '商户ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户ID不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'api_key',
                'title' => 'API密钥',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => 'API密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'api_url',
                'title' => '网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '留空使用虎皮椒 HTTPS 正式网关',
                    'tip' => '必须填写以 /payment/do.html 结尾的 HTTPS 地址。',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'wap_name',
                'title' => 'H5网站名称',
                'value' => '',
                'props' => [
                    'placeholder' => '留空使用同步返回地址的主机名',
                ],
            ],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY_H5 => '支付宝 H5',
                self::PRODUCT_WECHAT_H5 => '微信 H5',
                self::PRODUCT_ALIPAY => '支付宝扫码',
                self::PRODUCT_WECHAT => '微信扫码',
            ]),
        ];
    }

    /**
     * 按终端环境创建虎皮椒支付单。
     *
     * 当前合同的 PC 承接使用二维码图片中解析出的真实内容；移动、微信、支付宝和显式跳转环境使用 H5 地址，
     * 两类环境分别暴露对应处理器，避免产品未开通时跨形态重下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    public function pay(array $order): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        if ($this->isPcEnvironment($order)) {
            return $this->executeDirectPaymentProduct($order, [
                'qrcode' => [
                    'products' => [
                        'alipay' => self::PRODUCT_ALIPAY,
                        'wxpay' => self::PRODUCT_WECHAT,
                    ],
                    'handler' => fn (): array => $this->qrcodePay($order, $payType),
                ],
            ], '虎皮椒');
        }

        return $this->executeDirectPaymentProduct($order, [
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_H5,
                    'wxpay' => self::PRODUCT_WECHAT_H5,
                ],
                'handler' => fn (): array => $this->h5Pay($order, $payType),
            ],
            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_H5,
                    'wxpay' => self::PRODUCT_WECHAT_H5,
                ],
                'handler' => fn (): array => $this->h5Pay($order, $payType),
            ],
        ], '虎皮椒');
    }

    /**
     * 查询虎皮椒支付状态并与当前支付单强关联。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        try {
            $data = $this->client()->query(['out_trade_order' => $payNo]);
        } catch (XunhupaySdkException $e) {
            throw new PaymentUncertainException('虎皮椒查单结果不确定：' . $e->getMessage(), 40200, [
                'pay_no' => $payNo,
                'channel_error_code' => $e->channelErrorCode(),
            ]);
        }

        $status = strtoupper(trim((string) ($data['status'] ?? '')));
        if ($status === '') {
            throw new PaymentException('虎皮椒查单响应缺少 status', 40200, ['pay_no' => $payNo]);
        }
        [$amount, $openOrderId] = $this->assertPaymentResponse($data, $order, '虎皮椒查单');
        $mappedStatus = match ($status) {
            'OD' => PaymentPluginStatusConstant::SUCCESS,
            'WP', 'RD' => PaymentPluginStatusConstant::PENDING,
            'CD' => PaymentPluginStatusConstant::CLOSED,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $mappedStatus,
            'pay_no' => $payNo,
            'paid_amount' => $mappedStatus === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $openOrderId,
            'channel_status' => $status,
            'message' => $status,
        ];
    }

    /**
     * 当前官方支付合同没有可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('虎皮椒插件暂不支持关单', 40200);
    }

    /**
     * 申请虎皮椒全额退款。
     *
     * 官方请求没有部分退款金额字段，`CD` 才表示退款资金成功；`RD` 或仍为
     * `OD` 只表示尚未完成，不能提前冲正资金。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     *
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $amount = (int) ($order['amount'] ?? 0);
        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        if ($amount <= 0 || $refundAmount !== $amount) {
            throw new PaymentDefinitiveException('虎皮椒退款接口仅支持全额退款', 40200, [
                'pay_no' => $payNo,
            ]);
        }

        $openOrderId = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($openOrderId === '') {
            throw new PaymentDefinitiveException('虎皮椒退款缺少 open_order_id', 40200, [
                'pay_no' => $payNo,
            ]);
        }

        try {
            $data = $this->client()->refund(['open_order_id' => $openOrderId]);
        } catch (XunhupaySdkException $e) {
            $this->throwSdkException($e, '退款', $payNo);
        }

        $this->assertExactField('trade_order_id', $data['trade_order_id'] ?? '', $payNo, '虎皮椒退款');
        $responseAmount = $this->yuanToCents($data['refund_fee'] ?? null, '虎皮椒退款金额');
        if ($responseAmount !== $refundAmount) {
            throw new PaymentException('虎皮椒退款金额与退款单不一致', 40200, [
                'pay_no' => $payNo,
                'refund_amount' => $refundAmount,
                'response_amount' => $responseAmount,
            ]);
        }

        $refundStatus = strtoupper(trim((string) ($data['refund_status'] ?? '')));
        $channelRefundNo = trim((string) ($data['out_refund_no'] ?? ''));
        if ($refundStatus === '' || $channelRefundNo === '') {
            throw new PaymentUncertainException('虎皮椒退款响应缺少状态或退款单号', 40200, [
                'pay_no' => $payNo,
            ]);
        }
        if ($refundStatus === 'UD') {
            throw new PaymentDefinitiveException('虎皮椒明确返回退款失败', 40200, [
                'pay_no' => $payNo,
                'channel_status' => $refundStatus,
            ]);
        }
        $status = match ($refundStatus) {
            'CD' => PaymentPluginStatusConstant::SUCCESS,
            'RD', 'OD' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $status,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'channel_status' => $refundStatus,
            'message' => $refundStatus,
        ];
    }

    /**
     * 验签、关联本地支付单并归一化虎皮椒支付通知。
     *
     * 通知须匹配 AppID、本地通道、支付金额及已保存的 open_order_id，成功状态还必须携带渠道订单号。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = (array) $request->post();
        if ($payload === [] || !$this->client()->verify($payload)) {
            throw new PaymentException('虎皮椒回调验签失败', 40200);
        }

        $payNo = trim((string) ($payload['trade_order_id'] ?? ''));
        if ($payNo === '') {
            throw new PaymentException('虎皮椒回调缺少 trade_order_id', 40200);
        }
        $this->assertExactField('appid', $payload['appid'] ?? '', $this->configText('appid'), '虎皮椒回调');

        $payOrder = $this->payOrderRepository?->findByPayNo(
            $payNo,
            ['pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no']
        );
        if (!$payOrder) {
            throw new PaymentException('虎皮椒回调对应的支付单不存在', 40200, ['pay_no' => $payNo]);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('虎皮椒回调支付单不属于当前通道', 40200, [
                'pay_no' => $payNo,
                'channel_id' => $channelId,
                'order_channel_id' => (int) $payOrder->channel_id,
            ]);
        }

        $amount = $this->yuanToCents($payload['total_fee'] ?? null, '虎皮椒回调金额');
        if ($amount !== (int) $payOrder->pay_amount) {
            throw new PaymentException('虎皮椒回调金额与支付单不一致', 40200, [
                'pay_no' => $payNo,
                'notify_amount' => $amount,
                'order_amount' => (int) $payOrder->pay_amount,
            ]);
        }

        $storedOrderNo = trim((string) ($payOrder->channel_order_no ?? ''));
        if ($storedOrderNo !== '' && !hash_equals($storedOrderNo, $payNo)) {
            throw new PaymentException('虎皮椒回调订单号与已保存渠道订单号不一致', 40200, [
                'pay_no' => $payNo,
            ]);
        }
        $openOrderId = trim((string) ($payload['open_order_id'] ?? ''));
        $storedOpenOrderId = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedOpenOrderId !== ''
            && ($openOrderId === '' || !hash_equals($storedOpenOrderId, $openOrderId))) {
            throw new PaymentException('虎皮椒重复回调 open_order_id 不一致', 40200, [
                'pay_no' => $payNo,
            ]);
        }

        $channelStatus = strtoupper(trim((string) ($payload['status'] ?? '')));
        if ($channelStatus === '') {
            throw new PaymentException('虎皮椒回调缺少 status', 40200, ['pay_no' => $payNo]);
        }
        $success = $channelStatus === 'OD';
        if ($success && $openOrderId === '') {
            throw new PaymentException('虎皮椒成功回调缺少 open_order_id', 40200, ['pay_no' => $payNo]);
        }

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => $payNo,
            'paid_amount' => $success ? $amount : null,
            'message' => $channelStatus,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $openOrderId,
            'channel_status' => $channelStatus,
        ];
    }

    /**
     * 返回虎皮椒成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回虎皮椒失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 创建移动 H5/WAP 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准跳转待支付结果
     */
    private function h5Pay(array $order, string $payType): array
    {
        $data = $this->createOrder($order, $payType, true);
        $url = trim((string) ($data['url'] ?? ''));
        if (!$this->isHttpsUrl($url)) {
            throw new PaymentUncertainException('虎皮椒未返回受信 HTTPS 跳转地址', 40200, [
                'pay_no' => $this->orderPayNo($order),
            ]);
        }

        return $this->payResult(
            'jump',
            $payType,
            $this->paymentProduct($payType, true),
            ['url' => $url, 'raw' => $this->safeResponseSummary($data)],
            $data,
            $order
        );
    }

    /**
     * 创建 PC 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function qrcodePay(array $order, string $payType): array
    {
        $data = $this->createOrder($order, $payType, false);
        $qrcodeImage = trim((string) ($data['url_qrcode'] ?? ''));
        if ($qrcodeImage === '') {
            throw new PaymentUncertainException('虎皮椒未返回二维码图片地址', 40200, [
                'pay_no' => $this->orderPayNo($order),
            ]);
        }
        try {
            $qrcode = $this->client()->parseQrcode($qrcodeImage);
        } catch (XunhupaySdkException $e) {
            throw new PaymentUncertainException('虎皮椒二维码内容解析失败：' . $e->getMessage(), 40200, [
                'pay_no' => $this->orderPayNo($order),
            ]);
        }

        return $this->payResult(
            'qrcode',
            $payType,
            $this->paymentProduct($payType, false),
            ['qrcode' => $qrcode, 'raw' => $this->safeResponseSummary($data)],
            $data,
            $order
        );
    }

    /**
     * 创建虎皮椒订单请求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     * @param bool $useWap 是否请求 WAP 承接参数
     *
     * @return array<string, mixed> 已通过 SDK 校验的下单响应
     */
    private function createOrder(array $order, string $payType, bool $useWap): array
    {
        $payNo = $this->orderPayNo($order);
        if (preg_match('/^[A-Za-z0-9_\-*]{1,32}$/D', $payNo) !== 1) {
            throw new PaymentDefinitiveException('虎皮椒支付单号格式不受支持', 40200, ['pay_no' => $payNo]);
        }

        $params = [
            'version' => '1.1',
            'trade_order_id' => $payNo,
            'payment' => $this->channelPayment($payType),
            'total_fee' => FormatHelper::amount((int) ($order['amount'] ?? 0)),
            'title' => $this->orderTitle((string) ($order['subject'] ?? '')),
            'notify_url' => $this->merchantUrl((string) ($order['callback_url'] ?? ''), 'notify_url'),
            'return_url' => $this->merchantUrl((string) ($order['return_url'] ?? ''), 'return_url'),
        ];

        if ($useWap) {
            $host = strtolower((string) parse_url($params['return_url'], PHP_URL_HOST));
            if ($host === '') {
                throw new PaymentDefinitiveException('虎皮椒 H5 return_url 缺少主机名', 40200, [
                    'pay_no' => $payNo,
                ]);
            }
            $params['type'] = 'WAP';
            $params['wap_url'] = $host;
            $params['wap_name'] = $this->wapName($host);
        }

        try {
            return $this->client()->pay($params);
        } catch (XunhupaySdkException $e) {
            $this->throwSdkException($e, '下单', $payNo);
        }
    }

    /**
     * 校验查单业务字段与本地支付单一致。
     *
     * @param array<string, mixed> $data 查单业务数据
     * @param array<string, mixed> $order 标准查单参数
     * @param string $scene 用于异常提示的业务场景
     *
     * @return array{0:int,1:string}
     */
    private function assertPaymentResponse(array $data, array $order, string $scene): array
    {
        $payNo = $this->orderPayNo($order);
        $this->assertExactField('trade_order_id', $data['trade_order_id'] ?? '', $payNo, $scene);

        $amount = $this->yuanToCents($data['total_fee'] ?? null, $scene . '金额');
        $orderAmount = (int) ($order['amount'] ?? -1);
        if ($orderAmount < 0 || $amount !== $orderAmount) {
            throw new PaymentException($scene . '金额与支付单不一致', 40200, [
                'pay_no' => $payNo,
                'response_amount' => $amount,
                'order_amount' => $orderAmount,
            ]);
        }

        $openOrderId = trim((string) ($data['open_order_id'] ?? ''));
        if ($openOrderId === '') {
            throw new PaymentException($scene . '缺少 open_order_id', 40200, ['pay_no' => $payNo]);
        }
        $storedOpenOrderId = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($storedOpenOrderId !== '' && !hash_equals($storedOpenOrderId, $openOrderId)) {
            throw new PaymentException($scene . ' open_order_id 与支付单不一致', 40200, [
                'pay_no' => $payNo,
            ]);
        }

        return [$amount, $openOrderId];
    }

    /**
     * 将 SDK 确定性转换为 MPAY 变更操作异常。
     *
     * @param XunhupaySdkException $e SDK 异常
     * @param string $operation 支付变更动作
     * @param string $payNo 本地支付单号
     */
    private function throwSdkException(XunhupaySdkException $e, string $operation, string $payNo): never
    {
        $data = [
            'pay_no' => $payNo,
            'operation' => $operation,
            'channel_error_code' => $e->channelErrorCode(),
        ];
        if ($e->isDefinitive()) {
            throw new PaymentDefinitiveException('虎皮椒' . $operation . '被拒绝：' . $e->getMessage(), 40200, $data);
        }

        throw new PaymentUncertainException('虎皮椒' . $operation . '结果不确定：' . $e->getMessage(), 40200, $data);
    }

    /**
     * 精确比较官网字段，不扫描候选别名。
     *
     * @param string $field 上游字段名
     * @param mixed $actual 上游字段原值
     * @param string $expected 本地预期值
     * @param string $scene 用于异常提示的业务场景
     */
    private function assertExactField(string $field, mixed $actual, string $expected, string $scene): void
    {
        $actual = trim((string) $actual);
        if ($actual === '' || $expected === '' || !hash_equals($expected, $actual)) {
            throw new PaymentException($scene . ' ' . $field . ' 不匹配', 40200, [
                'field' => $field,
            ]);
        }
    }

    /**
     * 将非负元金额精确转换为整数分。
     *
     * @param mixed $value 上游元金额原值
     * @param string $field 用于异常提示的字段名称
     */
    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $text, $matches) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }

    /**
     * 包装公共选择器需要的支付产品结果。
     *
     * @param string $page 收银台承接页类型
     * @param string $payType 标准支付方式代码
     * @param string $product 虎皮椒产品代码
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function payResult(
        string $page,
        string $payType,
        string $product,
        array $payParams,
        array $data,
        array $order
    ): array {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'payment.do',
            'pay_params' => $payParams,
            'chan_order_no' => $this->orderPayNo($order),
            'chan_trade_no' => trim((string) ($data['open_order_id'] ?? '')),
        ]);
    }

    /**
     * 只保留不含签名、URL 和报文值的响应摘要。
     *
     * @param array<string, mixed> $data 上游响应
     * @return array<string, mixed>
     */
    private function safeResponseSummary(array $data): array
    {
        $fields = array_values(array_filter(
            array_map('strval', array_keys($data)),
            static fn (string $field): bool => $field !== 'hash'
        ));
        sort($fields, SORT_STRING);

        return [
            'errcode' => (string) ($data['errcode'] ?? ''),
            'errmsg' => $this->safeText($data['errmsg'] ?? ''),
            'response_fields' => $fields,
        ];
    }

    /**
     * 虎皮椒上游支付方式编码。
     *
     * @param string $payType 标准支付方式代码
     */
    private function channelPayment(string $payType): string
    {
        return match ($payType) {
            'alipay' => 'alipay',
            'wxpay' => 'wechat',
            default => throw new PaymentDefinitiveException('虎皮椒不支持当前支付方式', 40200, [
                'pay_type' => $payType,
            ]),
        };
    }

    /**
     * 返回当前支付方式对应的稳定插件产品编码。
     *
     * @param string $payType 标准支付方式代码
     * @param bool $h5 是否为 H5 承接产品
     */
    private function paymentProduct(string $payType, bool $h5): string
    {
        return match ([$payType, $h5]) {
            ['alipay', true] => self::PRODUCT_ALIPAY_H5,
            ['wxpay', true] => self::PRODUCT_WECHAT_H5,
            ['alipay', false] => self::PRODUCT_ALIPAY,
            ['wxpay', false] => self::PRODUCT_WECHAT,
            default => throw new PaymentDefinitiveException('虎皮椒支付产品映射失败', 40200),
        };
    }

    /**
     * 判断是否应使用官网要求的 PC 二维码承接。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function isPcEnvironment(array $order): bool
    {
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));

        return !in_array($env, ['mobile', 'qq', 'wechat', 'alipay', 'jump'], true);
    }

    /**
     * 读取并验证标准 MPAY 支付单号。
     *
     * @param array<string, mixed> $order 标准插件参数
     */
    private function orderPayNo(array $order): string
    {
        $payNo = trim((string) ($order['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentException('虎皮椒操作缺少 pay_no', 40200);
        }

        return $payNo;
    }

    /**
     * 生成符合官网限制的订单标题。
     *
     * @param string $subject 标准订单主题
     */
    private function orderTitle(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === ''
            || str_contains($subject, '%')
            || preg_match('/[\x{10000}-\x{10FFFF}]/u', $subject) === 1) {
            throw new PaymentDefinitiveException('虎皮椒订单标题为空或包含不支持的字符', 40200);
        }

        $title = mb_substr($subject, 0, 42, 'UTF-8');
        $title = trim(mb_strcut($title, 0, 127, 'UTF-8'));
        if ($title === '') {
            throw new PaymentDefinitiveException('虎皮椒订单标题无效', 40200);
        }

        return $title;
    }

    /**
     * 校验传给上游的商户回调或返回地址。
     *
     * 拒绝凭据、片段和非 HTTP(S) 协议，并遵守上游 128 字节长度限制。
     *
     * @param string $url 商户回调或返回地址
     * @param string $field 用于异常提示的上游字段名
     */
    private function merchantUrl(string $url, string $field): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 128 || !is_array($parts)) {
            throw new PaymentDefinitiveException('虎皮椒 ' . $field . ' 无效或超过 128 字节', 40200);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new PaymentDefinitiveException('虎皮椒 ' . $field . ' 无效或超过 128 字节', 40200);
        }

        return $url;
    }

    /**
     * 获取符合 WAP 长度约束的网站名称。
     *
     * @param string $fallback 未配置名称时使用的返回地址主机名
     */
    private function wapName(string $fallback): string
    {
        $name = $this->configText('wap_name');
        $name = $name !== '' ? $name : $fallback;
        $name = trim(mb_strcut($name, 0, 32, 'UTF-8'));
        if ($name === '') {
            throw new PaymentDefinitiveException('虎皮椒 H5 网站名称不能为空', 40200);
        }

        return $name;
    }

    /**
     * 判断上游跳转结果是否为普通 HTTPS 地址。
     *
     * @param string $url 上游跳转地址
     */
    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    /**
     * 截断可展示错误摘要。
     *
     * @param mixed $value 上游错误信息
     */
    private function safeText(mixed $value): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';

        return trim(mb_strcut($text, 0, 160, 'UTF-8'));
    }

    /**
     * 获取按当前商户和独立网关配置初始化的 SDK 客户端。
     */
    private function client(): XunhupayClient
    {
        if ($this->client === null) {
            $this->client = new XunhupayClient([
                'appid' => $this->configText('appid'),
                'api_key' => $this->configText('api_key'),
                'api_url' => $this->configText('api_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 读取字符串配置，缺失时返回空字符串。
     *
     * @param string $key 配置键
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
