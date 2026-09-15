<?php

declare(strict_types=1);

namespace app\command;

use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\payment\YsepayApiPayment;
use app\common\sdk\ysepay\YsepayClient;
use app\common\sdk\ysepay\YsepaySdkException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\PaymentPluginNotifyResultValidator;
use app\service\payment\order\PaymentPluginPayResultValidator;
use app\service\payment\order\PaymentPluginRefundResultValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use support\Request;
use Throwable;

/**
 * 银盛支付定向测试客户端。
 *
 * 记录精确接口报文，不访问外部网络。
 */
final class YsepayUnitClient extends YsepayClient
{
    /** @var array<int, array{method:string,biz:array<string,mixed>,urls:array<string,string>}> */
    public array $calls = [];

    /** @var array<int, array{method:string,biz:array<string,mixed>,urls:array<string,string>}> */
    public array $pageCalls = [];

    private \Closure $responder;

    /**
     * 构造银盛支付模拟 HTTP 客户端。
     *
     * @param array<string, string> $config
     */
    public function __construct(array $config, callable $responder)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct($config);
    }

    public function execute(string $method, array $bizContent, array $urls = []): array
    {
        $this->calls[] = ['method' => $method, 'biz' => $bizContent, 'urls' => $urls];
        $result = ($this->responder)($method, $bizContent, $urls, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function pageRequest(string $method, array $bizParams, array $urls = []): array
    {
        $this->pageCalls[] = ['method' => $method, 'biz' => $bizParams, 'urls' => $urls];

        return parent::pageRequest($method, $bizParams, $urls);
    }
}

/**
 * 银盛支付协议与安全边界定向测试。
 */
final class YsepayPaymentTestSuite
{
    private const ALL_PRODUCTS = [
        'alipay_jsapi',
        'alipay_h5',
        'weixin_jsapi',
        'cupmulapp',
        '1903000',
        '1902000',
        '9001002',
    ];

    /** @var array<string, mixed> */
    private array $fixture;

    private YsepayClient $client;

    /**
     * 执行银盛支付定向测试。
     */
    public static function run(): void
    {
        $suite = new self();
        $suite->fixture = $suite->createCertificateFixture();
        try {
            $suite->client = new YsepayClient($suite->clientConfig());
            $suite->testSignatureVectorAndMethodProfiles();
            $suite->testResponseSignatureAndGatewaySelection();
            $suite->testCertificateAndPrivateUploadSecurity();
            $suite->testSevenProductsAndPresentation();
            $suite->testStrictIdentityRequirements();
            $suite->testNotifyBindingsAndStatuses();
            $suite->testRefundStatesAndUnsupportedOperations();
        } finally {
            $suite->cleanupCertificateFixture();
        }
    }

    private function testSignatureVectorAndMethodProfiles(): void
    {
        $payload = $this->client->buildPayload(
            'ysepay.online.qrcodepay',
            ['out_trade_no' => '20260718SIGN001', 'subject' => '固定向量', 'empty' => ''],
            ['notify_url' => 'https://merchant.unit.test/notify/ysepay'],
            '2026-07-18 12:34:56'
        );
        $expected = 'biz_content={"out_trade_no":"20260718SIGN001","subject":"固定向量","empty":""}'
            . '&charset=UTF-8&method=ysepay.online.qrcodepay'
            . '&notify_url=https://merchant.unit.test/notify/ysepay&partner_id=PARTNER10001'
            . '&sign_type=RSA&timestamp=2026-07-18 12:34:56&version=3.5';
        self::assertSame($expected, $this->client->signingContent($payload), '银盛 RSA 固定向量签名原文错误');
        $signature = base64_decode((string) $payload['sign'], true);
        self::assertTrue(is_string($signature) && $signature !== '', '银盛 RSA 固定向量缺少签名');
        self::assertSame(
            1,
            openssl_verify($expected, $signature, $this->fixture['merchant_cert'], OPENSSL_ALGO_SHA1),
            '银盛 rainbow_legacy RSA-SHA1 固定向量验签失败'
        );
        self::assertSame(
            'a=1&z=2',
            $this->client->signingContent(['z' => '2', 'sign' => 'ignored', 'empty' => '', 'upload' => '@file', 'a' => '1']),
            '银盛 rainbow_legacy 排序/空值/@ 值过滤错误'
        );

        $profiles = [
            'ysepay.online.qrcodepay' => [YsepayClient::QRCODE_GATEWAY, '3.5'],
            'ysepay.online.alijsapi.pay' => [YsepayClient::QRCODE_GATEWAY, '3.5'],
            'ysepay.online.weixin.pay' => [YsepayClient::QRCODE_GATEWAY, '6.4'],
            'ysepay.online.cupmulapp.qrcodepay' => [YsepayClient::QRCODE_GATEWAY, '3.4'],
            'ysepay.online.cupgetmulapp.userid' => [YsepayClient::QRCODE_GATEWAY, '3.5'],
            'ysepay.online.wap.directpay.createbyuser' => [YsepayClient::OPENAPI_GATEWAY, '3.0'],
            'ysepay.online.trade.refund' => [YsepayClient::OPENAPI_GATEWAY, '3.0'],
        ];
        foreach ($profiles as $method => [$gateway, $version]) {
            self::assertSame($gateway, $this->client->gatewayForMethod($method), '银盛接口生产网关错误：' . $method);
            self::assertSame($version, $this->client->versionForMethod($method), '银盛接口版本错误：' . $method);
        }
        self::assertThrowsClass(
            fn () => $this->client->gatewayForMethod('ysepay.unknown.method'),
            YsepaySdkException::class,
            '银盛 SDK 必须拒绝方法白名单外接口'
        );
    }

    private function testResponseSignatureAndGatewaySelection(): void
    {
        $method = 'ysepay.online.trade.refund';
        $data = [
            'code' => '10000',
            'msg' => '受理成功',
            'out_trade_no' => '20260718REFPAY01',
            'trade_no' => 'YSETRADE001',
            'out_request_no' => 'YSEREFUND001',
            'refund_amount' => '1.00',
            'refundsn' => 'YSEREFUNDSN001',
        ];
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->signedResponse($method, $data)),
        ]));
        $stack->push(Middleware::history($history));
        $client = new YsepayClient($this->clientConfig(), new Client(['handler' => $stack]));
        $actual = $client->execute($method, [
            'out_trade_no' => '20260718REFPAY01',
            'trade_no' => 'YSETRADE001',
            'out_request_no' => 'YSEREFUND001',
            'refund_amount' => '1.00',
        ]);
        self::assertSame('YSEREFUNDSN001', $actual['refundsn'] ?? '', '银盛退款响应解析错误');
        self::assertSame(
            YsepayClient::OPENAPI_GATEWAY,
            (string) ($history[0]['request']->getUri() ?? ''),
            '银盛退款必须走 openapi 生产网关'
        );
        parse_str((string) $history[0]['request']->getBody(), $sent);
        self::assertSame('3.0', $sent['version'] ?? '', '银盛退款必须使用 3.0');
        self::assertTrue(isset($sent['biz_content']) && isset($sent['sign']), '银盛退款缺少业务报文或签名');

        $badStack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"ysepay_online_trade_refund_response":{"code":"10000"},"sign":"AAAA"}'),
        ]));
        $badClient = new YsepayClient($this->clientConfig(), new Client(['handler' => $badStack]));
        $exception = self::capturedException(
            fn () => $badClient->execute($method, ['out_trade_no' => '20260718REFPAY01']),
            YsepaySdkException::class,
            '银盛同步响应错签必须失败'
        );
        self::assertTrue($exception->isUncertain(), '银盛同步响应错签必须标记为结果不确定');

        $unknown = ['code' => '40004', 'sub_code' => 'ACQ.QUERY_NO_RECORD', 'sub_msg' => '暂无记录'];
        $unknownClient = $this->httpClientForResponse($method, $unknown);
        $exception = self::capturedException(
            fn () => $unknownClient->execute($method, ['out_trade_no' => '20260718REFPAY01']),
            YsepaySdkException::class,
            '银盛 ACQ.QUERY_NO_RECORD 必须失败'
        );
        self::assertTrue($exception->isUncertain(), 'ACQ.QUERY_NO_RECORD 不得当作确定失败');
    }

    private function testCertificateAndPrivateUploadSecurity(): void
    {
        $secret = 'WRONG-DO-NOT-LEAK';
        $exception = self::capturedException(fn () => new YsepayClient([
            'partner_id' => 'PARTNER10001',
            'platform_cert' => $this->fixture['platform_cert'],
            'private_cert' => $this->fixture['merchant_pfx'],
            'private_cert_password' => $secret,
        ]), YsepaySdkException::class, '银盛错误 PFX 密码必须失败');
        self::assertTrue(!str_contains($exception->getMessage(), $secret), '银盛证书错误不得泄露密码');

        $validTo = (int) (openssl_x509_parse($this->fixture['platform_cert'])['validTo_time_t'] ?? 0);
        self::assertThrowsClass(fn () => new YsepayClient(
            $this->clientConfig(),
            null,
            static fn (): int => $validTo + 1
        ), YsepaySdkException::class, '银盛过期证书必须失败');

        $weak = $this->createCertificate('ysepay-weak', 1024);
        self::assertThrowsClass(fn () => new YsepayClient([
            'partner_id' => 'PARTNER10001',
            'platform_cert' => $weak['certificate'],
            'private_cert' => $this->fixture['merchant_pfx'],
            'private_cert_password' => $this->fixture['password'],
        ]), YsepaySdkException::class, '银盛 1024 位旧平台证书必须失败');

        $plugin = $this->plugin(self::ALL_PRODUCTS, $this->unitClient());
        self::assertTrue($plugin instanceof PaymentIdentityRequirementInterface, '银盛 JSAPI 必须实现身份需求接口');
        $uploads = array_values(array_filter(
            $plugin->getConfigSchema(),
            static fn (array $field): bool => ($field['type'] ?? '') === 'upload'
        ));
        self::assertSame(4, count($uploads), '银盛证书及支付宝授权材料必须使用上传字段');
        foreach ($uploads as $upload) {
            $fileUpload = (array) ($upload['props']['fileUpload'] ?? []);
            self::assertSame(FileConstant::SCENE_CERTIFICATE, $fileUpload['scene'] ?? null, '银盛上传场景错误');
            self::assertSame(FileConstant::VISIBILITY_PRIVATE, $fileUpload['visibility'] ?? null, '银盛证书必须私有');
            self::assertSame(FileConstant::STORAGE_LOCAL, $fileUpload['storageEngine'] ?? null, '银盛证书必须复用本地私有机制');
            self::assertSame('object_key', $fileUpload['getKey'] ?? '', '银盛证书配置必须保存 object_key');
        }
        self::assertThrowsClass(
            fn () => $this->plugin(self::ALL_PRODUCTS, $this->unitClient(), null, ['platform_cert_path' => 'C:\\secrets\\ysepay.cer']),
            PaymentDefinitiveException::class,
            '银盛插件必须拒绝任意绝对证书路径'
        );
    }

    private function testSevenProductsAndPresentation(): void
    {
        $client = $this->unitClient();
        $plugin = $this->plugin(self::ALL_PRODUCTS, $client);
        $cases = [
            ['1903000', 'alipay', 'pc', []],
            ['1902000', 'wxpay', 'pc', []],
            ['9001002', 'bank', 'pc', []],
            ['alipay_h5', 'alipay', 'mobile', []],
            ['alipay_jsapi', 'alipay', 'alipay', ['buyer_id' => '2088000000000001']],
            ['weixin_jsapi', 'wxpay', 'wechat', ['openid' => 'WX-MP-OPENID']],
            ['weixin_jsapi', 'wxpay', 'mobile', ['method' => 'mini', 'mini_openid' => 'WX-MINI-OPENID']],
            ['cupmulapp', 'bank', 'mobile', ['method' => 'jsapi', 'unionpay_auth_code' => 'UP-AUTH-CODE']],
        ];
        foreach ($cases as $index => [$product, $payType, $env, $payment]) {
            $payNo = 'PAY20260718P' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
            $result = $plugin->pay($this->payOrder($payNo, $payType, $env, $payment));
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();
            self::assertSame($product, $result['pay_product'] ?? '', '银盛产品路由错误：' . $product);
            self::assertSame($payNo, $result['pay_no'] ?? '', '银盛标准结果必须保留 MPAY 本地支付单号');
            self::assertTrue(
                preg_match('/^\d{8}[A-F0-9]{24}$/D', (string) ($result['chan_order_no'] ?? '')) === 1
                    && !hash_equals($payNo, (string) $result['chan_order_no']),
                '银盛 chan_order_no 必须满足前八位交易日期且与本地 PAY 单号分离'
            );
            self::assertNoForbiddenResultFields($result);
        }

        $h5 = $plugin->pay($this->payOrder('PAY20260718H5FORM01', 'alipay', 'mobile', []));
        $params = (array) ($h5['presentation']['pay_params'] ?? []);
        self::assertSame('jump', $h5['presentation']['pay_page'] ?? '', '银盛支付宝 H5 必须使用 jump 自动表单');
        self::assertSame('post', $params['method'] ?? '', '银盛支付宝 H5 必须 POST');
        self::assertSame(YsepayClient::OPENAPI_GATEWAY, $params['action'] ?? '', '银盛支付宝 H5 网关错误');
        $payload = (array) ($params['payload'] ?? []);
        self::assertSame('3.0', $payload['version'] ?? '', '银盛支付宝 H5 版本错误');
        self::assertSame('BUS10001', $payload['business_code'] ?? '', '银盛支付宝 H5 缺少业务代码');
        self::assertTrue(!array_key_exists('biz_content', $payload), '银盛支付宝 H5 业务字段不得放入 biz_content');
        self::assertTrue(isset($payload['sign']), '银盛支付宝 H5 自动表单缺少签名');

        foreach (self::ALL_PRODUCTS as $product) {
            $single = $this->plugin([$product], $this->unitClient());
            self::assertSame([$product], $this->enabledProductsFromSchema($single, [$product]), '银盛单产品开关初始化错误');
        }
    }

    private function testStrictIdentityRequirements(): void
    {
        $plugin = $this->plugin(self::ALL_PRODUCTS, $this->unitClient());
        $alipayOrder = $this->payOrder('PAY20260718IDENT001', 'alipay', 'alipay', ['sub_openid' => 'WRONG-PLATFORM-ID']);
        $requirement = $plugin->identityRequirement($alipayOrder);
        self::assertSame('buyer_id', $requirement['identity_field'] ?? '', '银盛支付宝必须精确请求 buyer_id');
        self::assertSame([], $requirement['identity_aliases'] ?? null, '银盛支付宝不得复用其它身份字段');

        $wxOrder = $this->payOrder('PAY20260718IDENT002', 'wxpay', 'wechat', []);
        $requirement = $plugin->identityRequirement($wxOrder);
        self::assertSame('openid', $requirement['identity_field'] ?? '', '银盛公众号必须请求 openid');
        self::assertSame(['sub_openid'], $requirement['identity_aliases'] ?? [], '银盛公众号只允许同作用域 sub_openid');

        $miniOrder = $this->payOrder('PAY20260718IDENT003', 'wxpay', 'mobile', [
            'method' => 'mini',
            'openid' => 'MP-CANNOT-REUSE',
        ]);
        $requirement = $plugin->identityRequirement($miniOrder);
        self::assertSame('mini_openid', $requirement['identity_field'] ?? '', '银盛小程序必须请求 mini_openid');
        self::assertSame([], $requirement['identity_aliases'] ?? null, '银盛小程序不得复用公众号身份');

        $unionOrder = $this->payOrder('PAY20260718IDENT004', 'bank', 'mobile', [
            'method' => 'jsapi',
            'sub_openid' => 'WX-CANNOT-REUSE',
        ]);
        $requirement = $plugin->identityRequirement($unionOrder);
        self::assertSame('unionpay_auth_code', $requirement['identity_field'] ?? '', '银盛银联必须请求 userAuth 授权码');
        self::assertSame('unionpay_user_auth', $requirement['auth_type'] ?? '', '银盛银联必须复用标准授权回调');

        self::assertThrowsClass(
            fn () => $plugin->pay($this->payOrder('PAY20260718IDENT005', 'alipay', 'alipay', ['sub_openid' => 'WX-ID'])),
            PaymentDefinitiveException::class,
            '银盛支付宝不得把微信身份当 buyer_id'
        );
        self::assertThrowsClass(
            fn () => $plugin->pay($this->payOrder('PAY20260718IDENT006', 'wxpay', 'mobile', ['method' => 'mini', 'openid' => 'MP-ID'])),
            PaymentDefinitiveException::class,
            '银盛小程序不得把公众号 openid 当 mini_openid'
        );
        self::assertThrowsClass(
            fn () => $plugin->pay($this->payOrder('PAY20260718IDENT007', 'wxpay', 'wechat', [
                'openid' => 'WX-ID',
                'sub_appid' => 'wx-wrong-scope',
            ])),
            PaymentDefinitiveException::class,
            '银盛微信身份必须校验 AppID 作用域'
        );
    }

    private function testNotifyBindingsAndStatuses(): void
    {
        $client = $this->unitClient();
        $sourcePlugin = $this->plugin(self::ALL_PRODUCTS, $client);
        $result = $sourcePlugin->pay($this->payOrder('PAY20260718NOTIFY01', 'alipay', 'pc', []));
        $call = $client->calls[array_key_last($client->calls)];
        $extraContext = (string) ($call['biz']['extra_common_param'] ?? '');
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'PAY20260718NOTIFY01',
            'pay_amount' => 100,
            'channel_id' => 108,
            'plugin_code' => 'ysepay_api',
            'channel_order_no' => $result['chan_order_no'],
            'channel_trade_no' => $result['chan_trade_no'],
            'ext_json' => ['payment_context' => [
                'pay_product' => $result['pay_product'],
                'pay_action' => $result['pay_action'],
                'channel_context' => $result['channel_context'],
            ]],
        ]);
        $plugin = $this->notifyPlugin($payOrder, $client);
        $base = [
            'sign_type' => 'RSA',
            'notify_type' => 'directpay.status.sync',
            'notify_time' => '2026-07-18 13:00:00',
            'out_trade_no' => $result['chan_order_no'],
            'total_amount' => '1.00',
            'trade_no' => $result['chan_trade_no'],
            'trade_status' => 'TRADE_SUCCESS',
            'extra_common_param' => $extraContext,
        ];
        $success = $plugin->notify($this->postRequest($this->signedNotify($base)));
        PaymentPluginNotifyResultValidator::make($success)->withScene('notify_result')->validate();
        self::assertSame(PaymentPluginStatusConstant::SUCCESS, $success['status'] ?? '', '银盛成功通知状态错误');
        self::assertSame('PAY20260718NOTIFY01', $success['pay_no'] ?? '', '银盛通知必须从签名上下文还原本地 pay_no');
        self::assertSame(100, $success['paid_amount'] ?? null, '银盛成功通知金额错误');
        self::assertNoForbiddenResultFields($success);

        $pending = $plugin->notify($this->postRequest($this->signedNotify(array_replace($base, [
            'trade_status' => 'TRADE_ABNORMALITY',
        ]))));
        self::assertSame(PaymentPluginStatusConstant::PENDING, $pending['status'] ?? '', '银盛未知支付状态不得当成功或失败');
        $failed = $plugin->notify($this->postRequest($this->signedNotify(array_replace($base, [
            'trade_status' => 'TRADE_CLOSED',
        ]))));
        self::assertSame(PaymentPluginStatusConstant::FAILED, $failed['status'] ?? '', '银盛关闭通知状态错误');

        $wrongSign = $this->signedNotify($base);
        $wrongSign['sign'] = base64_encode('wrong-signature');
        self::assertThrowsClass(fn () => $plugin->notify($this->postRequest($wrongSign)), PaymentException::class, '银盛错签通知必须失败');
        self::assertThrowsClass(fn () => $plugin->notify($this->postRequest($this->signedNotify(array_replace($base, [
            'out_trade_no' => '20260718WRONGORD',
        ])))), PaymentException::class, '银盛错订单通知必须失败');
        self::assertThrowsClass(fn () => $plugin->notify($this->postRequest($this->signedNotify(array_replace($base, [
            'total_amount' => '1.01',
        ])))), PaymentException::class, '银盛错金额通知必须失败');
        self::assertThrowsClass(fn () => $plugin->notify($this->postRequest($this->signedNotify(array_replace($base, [
            'trade_no' => 'YSE-WRONG-TRADE',
        ])))), PaymentException::class, '银盛错交易流水通知必须失败');

        foreach (['partner_id', 'seller_id', 'business_code'] as $field) {
            $wrongContext = $this->mutateContext($extraContext, $field, 'WRONG-' . $field);
            self::assertThrowsClass(fn () => $plugin->notify($this->postRequest($this->signedNotify(array_replace($base, [
                'extra_common_param' => $wrongContext,
            ])))), PaymentException::class, '银盛回调必须拒绝错 ' . $field);
        }
    }

    private function testRefundStatesAndUnsupportedOperations(): void
    {
        $client = $this->unitClient();
        $plugin = $this->plugin(self::ALL_PRODUCTS, $client);
        $payResult = $plugin->pay($this->payOrder('PAY20260718REFPAY01', 'alipay', 'pc', []));
        $accepted = $plugin->refund([
            'pay_no' => 'PAY20260718REFPAY01',
            'refund_no' => 'YSEREFUND001',
            'refund_amount' => 100,
            'chan_order_no' => $payResult['chan_order_no'],
            'chan_trade_no' => $payResult['chan_trade_no'],
            'channel_context' => $payResult['channel_context'],
        ]);
        PaymentPluginRefundResultValidator::make($accepted)->withScene('refund_result')->validate();
        self::assertSame(PaymentPluginStatusConstant::PENDING, $accepted['status'] ?? '', '银盛退款受理不得标最终成功');
        self::assertSame('YSE-REFUNDSN-001', $accepted['chan_refund_no'] ?? '', '银盛必须使用 refundsn 作为退款流水');
        self::assertNoForbiddenResultFields($accepted);

        $definitiveClient = $this->unitClient(static fn (): Throwable => new YsepaySdkException('[ACQ.INVALID_PARAMETER]参数无效'));
        $definitive = $this->plugin(self::ALL_PRODUCTS, $definitiveClient);
        self::assertThrowsClass(fn () => $definitive->refund($this->refundOrder($payResult)), PaymentDefinitiveException::class, '银盛明确退款拒绝必须为确定异常');

        $uncertainClient = $this->unitClient(static fn (): Throwable => new YsepaySdkException('[ACQ.QUERY_NO_RECORD]未知', true));
        $uncertain = $this->plugin(self::ALL_PRODUCTS, $uncertainClient);
        self::assertThrowsClass(fn () => $uncertain->refund($this->refundOrder($payResult)), PaymentUncertainException::class, '银盛未知退款状态必须为不确定异常');

        self::assertThrowsClass(fn () => $plugin->query(['pay_no' => 'PAY20260718REFPAY01']), UnsupportedPaymentOperationException::class, '银盛 query 必须保持不支持');
        self::assertThrowsClass(fn () => $plugin->close(['pay_no' => 'PAY20260718REFPAY01']), UnsupportedPaymentOperationException::class, '银盛 close 必须保持不支持');
    }

    /**
     * 构建默认模拟响应。
     *
     * @return array<string, mixed>
     */
    private function defaultResponse(string $method, array $biz): array
    {
        $payNo = (string) ($biz['out_trade_no'] ?? '');
        return match ($method) {
            'ysepay.online.qrcodepay' => [
                'code' => '10000',
                'out_trade_no' => $payNo,
                'trade_no' => 'YSE-TRADE-' . $payNo,
                'trade_status' => 'WAIT_BUYER_PAY',
                'total_amount' => (string) ($biz['total_amount'] ?? ''),
                'currency' => 'CNY',
                'bank_type' => (string) ($biz['bank_type'] ?? ''),
                'source_qr_code_url' => 'https://pay.unit.test/qrcode/' . rawurlencode($payNo),
            ],
            'ysepay.online.alijsapi.pay' => [
                'code' => '10000',
                'out_trade_no' => $payNo,
                'trade_no' => 'YSE-TRADE-' . $payNo,
                'trade_status' => 'WAIT_BUYER_PAY',
                'total_amount' => (string) ($biz['total_amount'] ?? ''),
                'currency' => 'CNY',
                'jsapi_pay_info' => '{"tradeNO":"ALI-TRADE-NO"}',
            ],
            'ysepay.online.weixin.pay' => [
                'code' => '10000',
                'out_trade_no' => $payNo,
                'trade_no' => 'YSE-TRADE-' . $payNo,
                'trade_status' => 'WAIT_BUYER_PAY',
                'total_amount' => (string) ($biz['total_amount'] ?? ''),
                'currency' => 'CNY',
                'jsapi_pay_info' => json_encode([
                    'appId' => (string) ($biz['appid'] ?? ''),
                    'timeStamp' => '1721260800',
                    'nonceStr' => 'YSE-NONCE',
                    'package' => 'prepay_id=YSE-PREPAY',
                    'signType' => 'RSA',
                    'paySign' => 'YSE-WX-PAY-SIGN',
                ], JSON_UNESCAPED_SLASHES),
            ],
            'ysepay.online.cupgetmulapp.userid' => ['code' => '10000', 'userId' => 'YSE-UNIONPAY-USER-ID'],
            'ysepay.online.cupmulapp.qrcodepay' => [
                'code' => '10000',
                'out_trade_no' => $payNo,
                'trade_no' => 'YSE-TRADE-' . $payNo,
                'trade_status' => 'WAIT_BUYER_PAY',
                'total_amount' => (string) ($biz['total_amount'] ?? ''),
                'currency' => 'CNY',
                'bank_type' => '9001002',
                'web_url' => 'https://pay.unit.test/unionpay/' . rawurlencode($payNo),
            ],
            'ysepay.online.trade.refund' => [
                'code' => '10000',
                'out_trade_no' => $payNo,
                'trade_no' => (string) ($biz['trade_no'] ?? ''),
                'out_request_no' => (string) ($biz['out_request_no'] ?? ''),
                'refund_amount' => (string) ($biz['refund_amount'] ?? ''),
                'refundsn' => 'YSE-REFUNDSN-001',
            ],
            default => throw new RuntimeException('银盛测试响应器收到未知方法：' . $method),
        };
    }

    private function unitClient(?callable $responder = null): YsepayUnitClient
    {
        return new YsepayUnitClient(
            $this->clientConfig(),
            $responder ?? fn (string $method, array $biz): array => $this->defaultResponse($method, $biz)
        );
    }

    /**
     * 创建已初始化的银盛支付测试插件。
     *
     * @param array<int, string> $products
     * @param array<string, mixed> $overrides
     */
    private function plugin(
        array $products,
        YsepayClient $client,
        ?PayOrderRepository $repository = null,
        array $overrides = []
    ): YsepayApiPayment {
        $plugin = new YsepayApiPayment($repository, $client);
        $plugin->init(array_replace([
            'channel_id' => 108,
            'partner_id' => 'PARTNER10001',
            'seller_id' => 'SELLER10001',
            'business_code' => 'BUS10001',
            'platform_cert_path' => $this->fixture['platform_object_key'],
            'private_cert_path' => $this->fixture['merchant_object_key'],
            'private_cert_password' => $this->fixture['password'],
            'wx_mp_app_id' => 'wxmp1234567890',
            'wx_mp_app_secret' => 'wx-mp-secret',
            'wx_mini_app_id' => 'wxmini123456789',
            'wx_mini_app_secret' => 'wx-mini-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'alipay_oauth_app_id' => '2026000000000001',
            'alipay_oauth_private_key_path' => $this->fixture['oauth_private_object_key'],
            'alipay_oauth_public_key_path' => $this->fixture['oauth_public_object_key'],
            'unionpay_app_up_identifier' => 'YSE-APP-UP-ID',
            'enabled_products' => $products,
        ], $overrides));

        return $plugin;
    }

    private function notifyPlugin(PayOrder $payOrder, YsepayClient $client): YsepayApiPayment
    {
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $order)
            {
            }

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return hash_equals((string) $this->order->pay_no, $payNo) ? $this->order : null;
            }
        };

        return $this->plugin(self::ALL_PRODUCTS, $client, $repository);
    }

    /**
     * 构建测试支付单。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function payOrder(string $payNo, string $payType, string $env, array $payment): array
    {
        return [
            'pay_no' => $payNo,
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '银盛定向测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/notify/ysepay',
            'return_url' => 'https://merchant.unit.test/return/ysepay',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构建测试退款订单。
     *
     * @return array<string, mixed>
     */
    private function refundOrder(array $payResult): array
    {
        return [
            'pay_no' => 'PAY20260718REFPAY01',
            'refund_no' => 'YSEREFUND001',
            'refund_amount' => 100,
            'chan_order_no' => $payResult['chan_order_no'],
            'chan_trade_no' => $payResult['chan_trade_no'],
            'channel_context' => $payResult['channel_context'],
        ];
    }

    /**
     * 构建已签名支付通知。
     *
     * @param array<string, mixed> $payload
     */
    private function signedNotify(array $payload): array
    {
        unset($payload['sign']);
        $signature = '';
        if (!openssl_sign(
            $this->client->signingContent($payload),
            $signature,
            $this->fixture['platform_private_key'],
            OPENSSL_ALGO_SHA1
        )) {
            throw new RuntimeException('无法生成银盛通知测试签名');
        }
        $payload['sign'] = base64_encode($signature);

        return $payload;
    }

    /**
     * 构建 POST 通知请求。
     *
     * @param array<string, mixed> $payload
     */
    private function postRequest(array $payload): Request
    {
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $raw = "POST /notify/ysepay HTTP/1.1\r\n"
            . "Host: merchant.unit.test\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body;

        return new Request($raw);
    }

    /**
     * 修改签名回传上下文中的指定字段。
     *
     * @param string $encoded Base64URL 编码的上下文
     * @param string $field 待修改字段
     * @param string $value 替换值
     * @return string 修改后的 Base64URL 上下文
     */
    private function mutateContext(string $encoded, string $field, string $value): string
    {
        $padding = (4 - strlen($encoded) % 4) % 4;
        $json = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
        $context = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($context)) {
            throw new RuntimeException('银盛测试上下文解析失败');
        }
        $context[$field] = $value;
        $mutated = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode((string) $mutated), '+/', '-_'), '=');
    }

    /**
     * 构建已签名上游响应。
     *
     * @param array<string, mixed> $data
     */
    private function signedResponse(string $method, array $data): string
    {
        $nodeName = str_replace('.', '_', $method) . '_response';
        $node = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = '';
        if (!openssl_sign((string) $node, $signature, $this->fixture['platform_private_key'], OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('无法生成银盛同步响应测试签名');
        }

        return '{"' . $nodeName . '":' . $node . ',"sign":"' . base64_encode($signature) . '"}';
    }

    /**
     * 构建返回指定响应的 HTTP 客户端。
     *
     * @param array<string, mixed> $data
     */
    private function httpClientForResponse(string $method, array $data): YsepayClient
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $this->signedResponse($method, $data)),
        ]));

        return new YsepayClient($this->clientConfig(), new Client(['handler' => $stack]));
    }

    /**
     * 构建 SDK 测试配置。
     *
     * @return array<string, string>
     */
    private function clientConfig(): array
    {
        return [
            'partner_id' => 'PARTNER10001',
            'platform_cert' => $this->fixture['platform_cert'],
            'private_cert' => $this->fixture['merchant_pfx'],
            'private_cert_password' => $this->fixture['password'],
        ];
    }

    /**
     * 创建测试证书夹具。
     *
     * @return array<string, mixed>
     */
    private function createCertificateFixture(): array
    {
        $platform = $this->createCertificate('ysepay-platform');
        $merchant = $this->createCertificate('ysepay-merchant');
        $password = 'ysepay-unit-password';
        $pfx = '';
        if (!openssl_pkcs12_export($merchant['certificate'], $pfx, $merchant['private_key'], $password)) {
            throw new RuntimeException('无法生成银盛商户 PFX 测试证书');
        }

        $suffix = bin2hex(random_bytes(5));
        $directoryObjectKey = 'storage/private/certificate/ysepay_unit_' . $suffix;
        $directory = runtime_path($directoryObjectKey);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建银盛证书测试目录');
        }
        $files = [
            'platform.cer' => $platform['certificate'],
            'merchant.pfx' => $pfx,
            'alipay.key' => $merchant['private_key_pem'],
            'alipay.cer' => $merchant['certificate'],
        ];
        foreach ($files as $name => $contents) {
            if (file_put_contents($directory . DIRECTORY_SEPARATOR . $name, $contents) === false) {
                throw new RuntimeException('无法写入银盛证书测试文件');
            }
        }

        return [
            'directory' => $directory,
            'platform_cert' => $platform['certificate'],
            'platform_private_key' => $platform['private_key'],
            'merchant_cert' => $merchant['certificate'],
            'merchant_pfx' => $pfx,
            'password' => $password,
            'platform_object_key' => $directoryObjectKey . '/platform.cer',
            'merchant_object_key' => $directoryObjectKey . '/merchant.pfx',
            'oauth_private_object_key' => $directoryObjectKey . '/alipay.key',
            'oauth_public_object_key' => $directoryObjectKey . '/alipay.cer',
        ];
    }

    /**
     * 生成测试证书。
     *
     * @return array{certificate:string,private_key:mixed,private_key_pem:string}
     */
    private function createCertificate(string $commonName, int $bits = 2048): array
    {
        $opensslConfig = base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        $options = [
            'config' => $opensslConfig,
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        $privateKey = openssl_pkey_new($options);
        if ($privateKey === false) {
            throw new RuntimeException('无法生成银盛测试 RSA 私钥');
        }
        $csr = openssl_csr_new(['commonName' => $commonName], $privateKey, $options);
        $certificateResource = $csr === false ? false : openssl_csr_sign($csr, null, $privateKey, 30, $options);
        $certificate = '';
        $privateKeyPem = '';
        if ($certificateResource === false
            || !openssl_x509_export($certificateResource, $certificate)
            || !openssl_pkey_export($privateKey, $privateKeyPem, null, $options)) {
            throw new RuntimeException('无法生成银盛测试证书');
        }

        return [
            'certificate' => $certificate,
            'private_key' => $privateKey,
            'private_key_pem' => $privateKeyPem,
        ];
    }

    private function cleanupCertificateFixture(): void
    {
        $directory = $this->fixture['directory'] ?? '';
        if (!is_string($directory) || !is_dir($directory)) {
            return;
        }
        foreach (['platform.cer', 'merchant.pfx', 'alipay.key', 'alipay.cer'] as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * 断言结果未暴露禁止字段。
     *
     * @param array<string, mixed> $result
     */
    private static function assertNoForbiddenResultFields(array $result): void
    {
        $walk = static function (array $data, string $path = '') use (&$walk): void {
            foreach ($data as $key => $value) {
                $current = $path === '' ? (string) $key : $path . '.' . $key;
                $protocolPayload = str_starts_with($current, 'presentation.pay_params.payload.');
                if (in_array($key, ['raw', 'raw_data', 'channel_status'], true)
                    || ($key === 'currency' && !$protocolPayload)) {
                    throw new RuntimeException('银盛标准结果包含禁用字段：' . $current);
                }
                if (is_array($value) && $current !== 'channel_context') {
                    $walk($value, $current);
                }
            }
        };
        $walk($result);
    }

    /**
     * 校验产品配置结构并返回预期产品集合。
     *
     * 插件运行配置由 init 持有；这里仅验证 enabled_products schema 能完整表达七类产品。
     *
     * @param array<int, string> $expected
     * @return array<int, string>
     */
    private function enabledProductsFromSchema(YsepayApiPayment $plugin, array $expected): array
    {
        $field = array_values(array_filter(
            $plugin->getConfigSchema(),
            static fn (array $item): bool => ($item['field'] ?? '') === 'enabled_products'
        ))[0] ?? [];
        $options = array_column((array) ($field['options'] ?? []), 'value');
        self::assertSame(self::ALL_PRODUCTS, $options, '银盛 enabled_products 必须精确包含七类产品');

        return $expected;
    }

    private static function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private static function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . '；expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE)
                . ' actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE));
        }
    }

    private static function assertThrowsClass(callable $callback, string $class, string $message): void
    {
        self::capturedException($callback, $class, $message);
    }

    private static function capturedException(callable $callback, string $class, string $message): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new RuntimeException($message . '；异常类型为 ' . $e::class . '：' . $e->getMessage(), 0, $e);
        }

        throw new RuntimeException($message . '；没有抛出异常');
    }
}
