<?php

declare(strict_types=1);

namespace app\command;

use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\payment\UnionpayApiPayment;
use app\common\sdk\unionpay\UnionpayClient;
use app\common\sdk\unionpay\UnionpaySdkException;
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
use ReflectionProperty;
use RuntimeException;
use support\Request;
use Throwable;

/**
 * 银联前置测试客户端。仅记录业务字段，不发起网络请求。
 */
final class UnionpayUnitClient extends UnionpayClient
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct(UnionpayPaymentTestSuite::clientConfig());
    }

    /**
     * 发送银联模拟请求。
     *
     * @param array<string, mixed> $payload 请求字段
     * @return array<string, mixed>
     */
    public function request(array $payload): array
    {
        $this->calls[] = $payload;
        $result = ($this->responder)($payload, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 银联前置 Swiftpass XML 协议定向测试。
 */
final class UnionpayPaymentTestSuite
{
    private const ALL_PRODUCTS = [
        'wxpay_mp',
        'wxpay_mini',
        'alipay_jsapi',
        'bank_jsapi',
        'wxpay_h5',
        'wxpay_scan',
        'alipay_scan',
        'qqpay_scan',
        'bank_scan',
    ];

    /**
     * 执行银联前置定向测试。
     */
    public static function run(): void
    {
        $suite = new self();
        $suite->testMd5AndXmlSecurity();
        $suite->testRequestEnvelopeAndResponseVerification();
        $suite->testRetainedProductsAndExactFields();
        $suite->testStrictPlatformIdentities();
        $suite->testQqAndJdpayCapabilityBoundary();
        $suite->testNotifyValidationAndStatuses();
        $suite->testRefundSemanticsAndUnsupportedOperations();
    }

    /**
     * 构建 SDK 测试配置。
     *
     * @return array<string, string>
     */
    public static function clientConfig(): array
    {
        return [
            'mch_id' => '1900000109',
            'sub_mch_id' => 'SUB1900000109',
            'key' => '1234567890ABCDEF',
            'gateway_url' => 'https://qra.95516.com/pay/gateway',
        ];
    }

    private function testMd5AndXmlSecurity(): void
    {
        $client = new UnionpayClient(self::clientConfig());
        $payload = [
            'service' => 'unified.trade.native',
            'mch_id' => '1900000109',
            'nonce_str' => 'abc123',
            'body' => '测试订单',
            'total_fee' => '100',
            'out_trade_no' => 'PAY202607180001',
            'empty' => '',
            'spaces' => '   ',
            'null' => null,
            'sign' => 'IGNORED',
        ];
        $signing = 'body=测试订单&mch_id=1900000109&nonce_str=abc123'
            . '&out_trade_no=PAY202607180001&service=unified.trade.native&total_fee=100';
        self::assertSame($signing, $client->signingContent($payload), '银联前置 MD5 字段排序或空值规则错误');
        self::assertSame('02555BE1AA7FD1B62461306DE2B65C38', $client->sign($payload), '银联前置 MD5 固定向量错误');

        $signed = $payload;
        $signed['sign'] = $client->sign($payload);
        self::assertTrue($client->verify($signed), '银联前置大写 MD5 签名应验签成功');
        $signed['sign'] = strtolower($signed['sign']);
        self::assertTrue(!$client->verify($signed), '银联前置签名大小写合同必须固定为大写');

        $xml = $client->encodeXml(['body' => 'A]]>B', 'amount' => '100']);
        self::assertSame(['body' => 'A]]>B', 'amount' => '100'], $client->parseXml($xml), '银联前置 CDATA 拆分回读错误');
        self::assertSame(['empty' => ''], $client->parseXml('<xml><empty></empty></xml>'), '银联前置 XML 解析不得吞掉显式空节点');

        foreach ([
            '<!DOCTYPE xml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><xml><body>&xxe;</body></xml>',
            '<xml><body>broken</xml>',
            '<xml><body>A</body><body>B</body></xml>',
            '<xml><body><nested>B</nested></body></xml>',
            '<xml flag="1"><body>A</body></xml>',
        ] as $unsafeXml) {
            self::assertThrowsClass(
                fn () => $client->parseXml($unsafeXml),
                UnionpaySdkException::class,
                '银联前置必须拒绝 XXE、畸形、重复、嵌套或带属性 XML'
            );
        }
        self::assertThrowsClass(
            fn () => new UnionpayClient(array_replace(self::clientConfig(), ['gateway_url' => 'http://qra.95516.com/pay/gateway'])),
            UnionpaySdkException::class,
            '银联前置生产网关必须使用 HTTPS'
        );
    }

    private function testRequestEnvelopeAndResponseVerification(): void
    {
        $signer = new UnionpayClient(self::clientConfig());
        $responsePayload = [
            'status' => '0',
            'result_code' => '0',
            'mch_id' => '1900000109',
            'sub_mch_id' => 'SUB1900000109',
            'out_trade_no' => 'UP-ENVELOPE-001',
            'code_url' => 'https://pay.unit.test/up-envelope',
        ];
        $responsePayload['sign'] = $signer->sign($responsePayload);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], $signer->encodeXml($responsePayload)),
        ]));
        $stack->push(Middleware::history($history));
        $client = new UnionpayClient(self::clientConfig(), new Client([
            'handler' => $stack,
            'http_errors' => false,
            'verify' => true,
        ]));
        $result = $client->request([
            'service' => 'unified.trade.native',
            'mch_id' => 'OVERRIDE-MERCHANT',
            'sub_mch_id' => 'OVERRIDE-SUB',
            'key' => 'OVERRIDE-KEY',
            'nonce_str' => 'OVERRIDE-NONCE',
            'charset' => 'GBK',
            'sign_type' => 'RSA',
            'out_trade_no' => 'UP-ENVELOPE-001',
            'body' => '请求包检查',
            'total_fee' => '100',
        ]);
        self::assertSame('https://pay.unit.test/up-envelope', $result['code_url'] ?? '', '银联前置已验签响应读取错误');
        self::assertSame(1, count($history), '银联前置请求次数错误');
        $transaction = $history[0] ?? [];
        $request = $transaction['request'] ?? null;
        self::assertTrue($request !== null, '银联前置请求历史缺失');
        $sent = $signer->parseXml((string) $request->getBody());
        self::assertSame('1900000109', $sent['mch_id'] ?? '', '银联前置不得接受调用方覆盖 mch_id');
        self::assertSame('SUB1900000109', $sent['sub_mch_id'] ?? '', '银联前置不得接受调用方覆盖 sub_mch_id');
        self::assertTrue(!array_key_exists('key', $sent), '银联前置 XML 请求不得携带 MD5 密钥');
        self::assertSame('UTF-8', $sent['charset'] ?? '', '银联前置 charset 必须为 UTF-8');
        self::assertSame('MD5', $sent['sign_type'] ?? '', '银联前置 sign_type 必须为 MD5');
        self::assertTrue(preg_match('/^[a-f0-9]{32}$/D', (string) ($sent['nonce_str'] ?? '')) === 1, '银联前置 nonce 必须为 32 位随机十六进制');
        self::assertTrue($signer->verify($sent), '银联前置实际 XML 请求签名错误');
        self::assertSame('application/xml; charset=UTF-8', $request->getHeaderLine('Content-Type'), '银联前置 XML Content-Type 错误');

        self::assertThrowsClass(
            fn () => $client->request(['service' => 'pay.weixin.native']),
            UnionpaySdkException::class,
            '银联前置不得接受相邻 Swiftpass 插件的 service'
        );

        $missingResult = self::capturedException(
            fn () => $this->sdkClientWithResponse([
                'return_code' => 'SUCCESS',
                'mch_id' => '1900000109',
            ])->request(['service' => 'unified.trade.native']),
            UnionpaySdkException::class,
            '银联前置响应缺少 result_code 必须失败'
        );
        self::assertTrue($missingResult->isUncertain(), '银联前置缺失业务状态必须标记结果不确定');

        $communicationFailed = self::capturedException(
            fn () => $this->sdkClientWithResponse([
                'return_code' => 'FAIL',
                'return_msg' => '通信拒绝',
                'mch_id' => '1900000109',
            ])->request(['service' => 'unified.trade.native']),
            UnionpaySdkException::class,
            '银联前置明确通信失败必须拒绝'
        );
        self::assertTrue(!$communicationFailed->isUncertain(), '已验签的明确通信失败必须是确定失败');

        $rejected = self::capturedException(
            fn () => $this->sdkClientWithResponse([
                'return_code' => 'SUCCESS',
                'result_code' => 'FAIL',
                'err_code' => 'NO_PERMISSION',
                'err_msg' => 'openid=DO-NOT-LEAK 未开通',
                'mch_id' => '1900000109',
            ])->request(['service' => 'unified.trade.native']),
            UnionpaySdkException::class,
            '银联前置明确业务拒绝必须失败'
        );
        self::assertTrue(!$rejected->isUncertain(), '已验签的明确业务拒绝必须是确定失败');
        self::assertTrue(!str_contains($rejected->getMessage(), 'DO-NOT-LEAK'), '银联前置错误信息不得泄露用户身份');
    }

    private function testRetainedProductsAndExactFields(): void
    {
        $cases = [
            ['alipay_scan', 'alipay', 'pc', [], 'unified.trade.native', 'qrcode', 'code_url'],
            ['wxpay_scan', 'wxpay', 'pc', [], 'unified.trade.native', 'qrcode', 'code_url'],
            ['qqpay_scan', 'qqpay', 'pc', [], 'unified.trade.native', 'qrcode', 'code_url'],
            ['bank_scan', 'bank', 'pc', [], 'unified.trade.native', 'qrcode', 'code_url'],
            ['wxpay_mp', 'wxpay', 'wechat', ['openid' => 'WX-MP-OPENID'], 'pay.weixin.jspay', 'jsapi', 'pay_info'],
            ['wxpay_mini', 'wxpay', 'wechat', ['is_mini' => true, 'mini_openid' => 'WX-MINI-OPENID'], 'pay.weixin.jspay', 'jsapi', 'pay_info'],
            ['alipay_jsapi', 'alipay', 'alipay', ['buyer_id' => 'ALI-BUYER-ID'], 'pay.alipay.jspay', 'jsapi', 'pay_info'],
            ['bank_jsapi', 'bank', 'mobile', ['method' => 'jsapi', 'unionpay_user_id' => 'UP-USER-ID'], 'pay.unionpay.jspay', 'jump', 'pay_url'],
            ['wxpay_h5', 'wxpay', 'mobile', [], 'pay.weixin.wappay', 'jump', 'pay_info'],
        ];

        foreach ($cases as [$product, $payType, $env, $payment, $service, $page, $responseField]) {
            $client = new UnionpayUnitClient(fn (array $payload): array => $this->payResponse($payload));
            $plugin = $this->plugin([$product], $client);
            $order = $this->payOrder($payType, $env, $payment, 'UP-' . strtoupper(str_replace('_', '-', $product)));
            $result = $plugin->pay($order);
            self::assertSame($product, $result['pay_product'] ?? '', '银联前置产品选择错误：' . $product);
            self::assertSame($service, $result['pay_action'] ?? '', '银联前置 service 映射错误：' . $product);
            self::assertSame($page, $result['presentation']['pay_page'] ?? '', '银联前置 presentation 类型错误：' . $product);
            self::assertSame($service, $client->calls[array_key_last($client->calls)]['service'] ?? '', '银联前置请求 service 错误：' . $product);
            self::assertTrue(array_key_exists($responseField, $this->payResponse($client->calls[array_key_last($client->calls)])), '银联前置测试响应字段错误：' . $product);
            self::assertNoPrivateExtensions($result, '银联前置下单结果字段越界：' . $product);
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();

            $payParams = (array) ($result['presentation']['pay_params'] ?? []);
            if ($responseField === 'code_url') {
                self::assertTrue(isset($payParams['qrcode']) && !isset($payParams['url'], $payParams['tradeNO']), '统一主扫只能解析 code_url');
            } elseif ($product === 'alipay_jsapi') {
                self::assertSame(['tradeNO' => 'ALI-TRADE-NO'], $payParams, '支付宝服务窗只能解析 pay_info.tradeNO');
            } elseif ($product === 'bank_jsapi') {
                self::assertSame(['url' => 'https://pay.unit.test/unionpay-jsapi'], $payParams, '银联 JSAPI 只能解析 pay_url');
            } elseif ($product === 'wxpay_h5') {
                self::assertSame(['url' => 'https://pay.unit.test/wechat-h5'], $payParams, '微信 H5 只能解析 pay_info');
            } else {
                self::assertSame('WX-PAY-SIGN', $payParams['paySign'] ?? '', '微信 JSAPI 只能解析 pay_info JSON');
            }
        }

        $authClient = new UnionpayUnitClient(fn (array $payload): array => $this->payResponse($payload));
        $authPlugin = $this->plugin(['bank_jsapi'], $authClient);
        $result = $authPlugin->pay($this->payOrder('bank', 'mobile', [
            'method' => 'jsapi',
            'unionpay_auth_code' => 'UP-AUTH-CODE',
        ], 'UP-BANK-AUTH-001'));
        self::assertSame(
            ['pay.unionpay.userid', 'pay.unionpay.jspay'],
            array_column($authClient->calls, 'service'),
            '银联 userAuth 必须先换 user_id，再调用银联 JSAPI'
        );
        self::assertSame('UP-AUTH-CODE', $authClient->calls[0]['user_auth_code'] ?? '', '银联身份交换授权码错误');
        self::assertSame('UP-USER-ID', $authClient->calls[1]['user_id'] ?? '', '银联 JSAPI user_id 错误');
        self::assertSame('bank_jsapi', $result['pay_product'] ?? '', '银联授权后的产品错误');

        $wrongFieldClient = new UnionpayUnitClient(static fn (array $payload): array => [
            'mch_id' => '1900000109',
            'sub_mch_id' => 'SUB1900000109',
            'out_trade_no' => (string) ($payload['out_trade_no'] ?? ''),
            'pay_url' => 'https://wrong-field.unit.test',
        ]);
        self::assertThrowsClass(
            fn () => $this->plugin(['wxpay_scan'], $wrongFieldClient)->pay($this->payOrder('wxpay', 'pc', [], 'UP-WRONG-FIELD-001')),
            PaymentException::class,
            '统一主扫缺少 code_url 时不得遍历其它产品字段'
        );

        $wrongProductFields = [
            ['wxpay_mp', $this->payOrder('wxpay', 'wechat', ['openid' => 'WX-ID'], 'UP-WRONG-WX-001'), ['pay_url' => 'https://wrong.unit.test/wx']],
            ['alipay_jsapi', $this->payOrder('alipay', 'alipay', ['buyer_id' => 'ALI-ID'], 'UP-WRONG-ALI-001'), ['pay_url' => 'https://wrong.unit.test/ali']],
            ['bank_jsapi', $this->payOrder('bank', 'mobile', ['method' => 'jsapi', 'unionpay_user_id' => 'UP-ID'], 'UP-WRONG-BANK-001'), ['code_url' => 'https://wrong.unit.test/bank']],
            ['wxpay_h5', $this->payOrder('wxpay', 'mobile', [], 'UP-WRONG-H5-001'), ['pay_url' => 'https://wrong.unit.test/h5']],
        ];
        foreach ($wrongProductFields as [$product, $order, $wrongFields]) {
            $wrongClient = new UnionpayUnitClient(static fn (array $payload): array => [
                'mch_id' => '1900000109',
                'sub_mch_id' => 'SUB1900000109',
                'out_trade_no' => (string) ($payload['out_trade_no'] ?? ''),
            ] + $wrongFields);
            self::assertThrowsClass(
                fn () => $this->plugin([$product], $wrongClient)->pay($order),
                PaymentUncertainException::class,
                '银联前置产品缺少唯一响应字段时不得扫描候选字段：' . $product
            );
        }
    }

    private function testStrictPlatformIdentities(): void
    {
        $plugin = $this->plugin(self::ALL_PRODUCTS, new UnionpayUnitClient(fn (array $payload): array => $this->payResponse($payload)));
        self::assertTrue($plugin instanceof PaymentIdentityRequirementInterface, '银联前置必须实现身份需求接口');

        $wechat = $plugin->identityRequirement($this->payOrder('wxpay', 'wechat', ['buyer_id' => 'ALI-ID'], 'UP-ID-WX-001'));
        self::assertSame('openid', $wechat['identity_field'] ?? '', '微信公众号必须只要求 openid');
        self::assertSame(['sub_openid'], $wechat['identity_aliases'] ?? [], '微信公众号身份别名错误');
        self::assertSame('wxpay', $wechat['provider'] ?? '', '微信公众号身份 provider 错误');

        $mini = $plugin->identityRequirement($this->payOrder('wxpay', 'wechat', [
            'is_mini' => true,
            'buyer_id' => 'ALI-ID',
            'sub_openid' => 'WX-MP-ID',
        ], 'UP-ID-MINI-001'));
        self::assertSame('mini_openid', $mini['identity_field'] ?? '', '微信小程序必须只要求 mini_openid');
        self::assertSame([], $mini['identity_aliases'] ?? null, '微信小程序不得接受公众号身份别名');

        $alipay = $plugin->identityRequirement($this->payOrder('alipay', 'alipay', [
            'sub_openid' => 'WX-ID',
            'mini_openid' => 'WX-MINI-ID',
        ], 'UP-ID-ALI-001'));
        self::assertSame('buyer_id', $alipay['identity_field'] ?? '', '支付宝必须只要求 buyer_id');
        self::assertSame([], $alipay['identity_aliases'] ?? null, '支付宝不得接受微信身份别名');

        $unionpay = $plugin->identityRequirement($this->payOrder('bank', 'mobile', [
            'method' => 'jsapi',
            'sub_openid' => 'WX-ID',
            'buyer_id' => 'ALI-ID',
        ], 'UP-ID-BANK-001'));
        self::assertSame('unionpay_auth_code', $unionpay['identity_field'] ?? '', '银联必须只要求 userAuth 授权码');
        self::assertSame('unionpay_user_auth', $unionpay['auth_type'] ?? '', '银联必须复用现有 userAuth 回调');
        self::assertSame([], $unionpay['identity_aliases'] ?? null, '银联不得接受跨平台身份别名');

        self::assertSame(null, $plugin->identityRequirement($this->payOrder('wxpay', 'wechat', ['openid' => 'WX-ID'], 'UP-ID-WX-OK')), '已有微信公众号身份不应重复授权');
        self::assertSame(null, $plugin->identityRequirement($this->payOrder('wxpay', 'wechat', ['is_mini' => true, 'mini_openid' => 'MINI-ID'], 'UP-ID-MINI-OK')), '已有小程序身份不应重复授权');
        self::assertSame(null, $plugin->identityRequirement($this->payOrder('alipay', 'alipay', ['buyer_id' => 'ALI-ID'], 'UP-ID-ALI-OK')), '已有支付宝身份不应重复授权');
        self::assertSame(null, $plugin->identityRequirement($this->payOrder('bank', 'mobile', ['method' => 'jsapi', 'unionpay_user_id' => 'UP-ID'], 'UP-ID-BANK-OK')), '已有银联身份不应重复授权');

        $mpOnly = $this->plugin(['wxpay_mp'], new UnionpayUnitClient(fn (array $payload): array => $this->payResponse($payload)));
        self::assertThrowsClass(
            fn () => $mpOnly->pay($this->payOrder('wxpay', 'wechat', ['mini_openid' => 'MINI-ID'], 'UP-ID-NO-FALLBACK')),
            PaymentException::class,
            'mini_openid 不得跨产品兜底为微信公众号 openid'
        );
    }

    private function testQqAndJdpayCapabilityBoundary(): void
    {
        $client = new UnionpayUnitClient(fn (array $payload): array => $this->payResponse($payload));
        $plugin = $this->plugin(['qqpay_scan'], $client);
        self::assertSame(['alipay', 'wxpay', 'qqpay', 'bank'], $plugin->getEnabledPayTypes(), '银联前置支付方式声明必须排除 jdpay');
        $schema = $plugin->getConfigSchema();
        $enabled = array_values(array_filter($schema, static fn (array $field): bool => ($field['field'] ?? '') === 'enabled_products'))[0] ?? [];
        $options = array_column((array) ($enabled['options'] ?? []), 'value');
        self::assertSame(self::ALL_PRODUCTS, $options, '银联前置必须只暴露九个保留产品开关');
        self::assertTrue(in_array('qqpay_scan', $options, true), 'QQ 统一主扫必须保留产品开关');
        self::assertTrue(!in_array('jdpay_scan', $options, true), 'jdpay 不得出现在插件产品开关');
        self::assertSame([], $enabled['value'] ?? null, '无真实闭环时银联前置产品默认必须全部关闭');

        $result = $plugin->pay($this->payOrder('qqpay', 'pc', [], 'UP-QQ-001'));
        self::assertSame('unified.trade.native', $client->calls[0]['service'] ?? '', 'QQ 扫码只能使用统一主扫 service');
        self::assertSame('https://qpay.qq.com/qr/QQ-TOKEN-001', $result['presentation']['pay_params']['qrcode'] ?? '', 'QQ 二维码协议转换错误');

        self::assertThrowsClass(
            fn () => $this->plugin(self::ALL_PRODUCTS, new UnionpayUnitClient(fn (array $payload): array => $this->payResponse($payload)))
                ->pay($this->payOrder('jdpay', 'pc', [], 'UP-JD-001')),
            PaymentException::class,
            'jdpay 缺少核心支付类型证据时必须保持不可路由'
        );
    }

    private function testNotifyValidationAndStatuses(): void
    {
        $payOrder = $this->storedPayOrder();
        $plugin = $this->notifyPlugin($payOrder);
        $successPayload = $this->notifyPayload();
        $success = $plugin->notify($this->xmlRequest($this->signedXml($successPayload)));
        self::assertSame(PaymentPluginStatusConstant::SUCCESS, $success['status'] ?? '', '银联前置 SUCCESS 通知状态错误');
        self::assertSame(100, $success['paid_amount'] ?? null, '银联前置成功通知金额错误');
        self::assertSame('UP-TX-001', $success['chan_trade_no'] ?? '', '银联前置通知 transaction_id 映射错误');
        self::assertNoPrivateExtensions($success, '银联前置通知结果字段越界');
        PaymentPluginNotifyResultValidator::make($success)->withScene('notify_result')->validate();
        self::assertSame('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>', $plugin->notifySuccess(), '银联前置成功 ACK 错误');
        self::assertSame('<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[FAIL]]></return_msg></xml>', $plugin->notifyFail(), '银联前置失败 ACK 错误');

        $legacyPayload = $this->notifyPayload(['result_code' => '0']);
        unset($legacyPayload['return_code']);
        $legacyPayload['status'] = '0';
        $legacy = $plugin->notify($this->xmlRequest($this->signedXml($legacyPayload)));
        self::assertSame(PaymentPluginStatusConstant::SUCCESS, $legacy['status'] ?? '', 'rainbow_legacy 通知状态口径错误');

        $pending = $plugin->notify($this->xmlRequest($this->signedXml($this->notifyPayload([
            'trade_state' => 'PROCESSING',
            'transaction_id' => '',
        ]))));
        self::assertSame(PaymentPluginStatusConstant::PENDING, $pending['status'] ?? '', '银联前置处理中通知必须映射 pending');
        self::assertSame(null, $pending['paid_amount'] ?? null, '银联前置处理中通知不得返回已支付金额');

        $failed = $plugin->notify($this->xmlRequest($this->signedXml($this->notifyPayload([
            'result_code' => 'FAIL',
            'trade_state' => 'FAILED',
            'transaction_id' => '',
        ]))));
        self::assertSame(PaymentPluginStatusConstant::FAILED, $failed['status'] ?? '', '银联前置业务失败通知状态错误');

        $badSign = $successPayload;
        $badSign['sign'] = str_repeat('A', 32);
        self::assertThrowsClass(fn () => $plugin->notify($this->xmlRequest($this->xml($badSign))), PaymentException::class, '银联前置错签通知必须拒绝');

        foreach ([
            '错商户' => ['mch_id' => 'OTHER-MERCHANT'],
            '错子商户' => ['sub_mch_id' => 'OTHER-SUB-MERCHANT'],
            '错订单' => ['out_trade_no' => 'UP-NOTIFY-OTHER'],
            '错金额' => ['total_fee' => '101'],
            '错渠道交易号' => ['transaction_id' => 'UP-TX-OTHER'],
        ] as $name => $overrides) {
            self::assertThrowsClass(
                fn () => $plugin->notify($this->xmlRequest($this->signedXml($this->notifyPayload($overrides)))),
                PaymentException::class,
                '银联前置通知必须拒绝' . $name
            );
        }
        self::assertThrowsClass(
            fn () => $plugin->notify($this->xmlRequest($this->signedXml($this->notifyPayload(['return_code' => 'FAIL'])))),
            PaymentException::class,
            '银联前置通信失败通知必须拒绝'
        );
        self::assertThrowsClass(
            fn () => $plugin->notify($this->xmlRequest($this->signedXml($this->notifyPayload(['trade_state' => 'MYSTERY'])))),
            PaymentUncertainException::class,
            '银联前置未知交易状态必须标记不确定'
        );
    }

    private function testRefundSemanticsAndUnsupportedOperations(): void
    {
        $client = new UnionpayUnitClient(function (array $payload): array {
            $response = [
                'return_code' => 'SUCCESS',
                'result_code' => 'SUCCESS',
                'mch_id' => '1900000109',
                'sub_mch_id' => 'SUB1900000109',
                'out_refund_no' => (string) ($payload['out_refund_no'] ?? ''),
                'total_fee' => (string) ($payload['total_fee'] ?? ''),
                'refund_fee' => (string) ($payload['refund_fee'] ?? ''),
                'refund_id' => 'UP-REFUND-ID-' . (string) ($payload['out_refund_no'] ?? ''),
            ];
            if (isset($payload['transaction_id'])) {
                $response['transaction_id'] = (string) $payload['transaction_id'];
            }
            if (isset($payload['out_trade_no'])) {
                $response['out_trade_no'] = (string) $payload['out_trade_no'];
            }
            $status = match ((string) ($payload['out_refund_no'] ?? '')) {
                'UP-REFUND-SUCCESS' => 'SUCCESS',
                'UP-REFUND-UNKNOWN' => 'UNKNOWN',
                'UP-REFUND-MYSTERY' => 'MYSTERY',
                default => '',
            };
            if ($status !== '') {
                $response['refund_status'] = $status;
            }

            return $response;
        });
        $plugin = $this->plugin(self::ALL_PRODUCTS, $client);

        $accepted = $plugin->refund($this->refundOrder('UP-REFUND-ACCEPTED', 'UP-TX-ORIGINAL', 'UP-ORDER-ORIGINAL'));
        self::assertSame(PaymentPluginStatusConstant::PENDING, $accepted['status'] ?? '', '仅通信和业务受理不得当成退款成功');
        self::assertSame('UP-TX-ORIGINAL', $client->calls[0]['transaction_id'] ?? '', '退款必须优先使用 transaction_id');
        self::assertTrue(!array_key_exists('out_trade_no', $client->calls[0]), '有 transaction_id 时退款不得同时发送 out_trade_no');
        PaymentPluginRefundResultValidator::make($accepted)->withScene('refund_result')->validate();

        $success = $plugin->refund($this->refundOrder('UP-REFUND-SUCCESS', 'UP-TX-ORIGINAL', 'UP-ORDER-ORIGINAL'));
        self::assertSame(PaymentPluginStatusConstant::SUCCESS, $success['status'] ?? '', '明确退款成功状态映射错误');
        $unknown = $plugin->refund($this->refundOrder('UP-REFUND-UNKNOWN', 'UP-TX-ORIGINAL', 'UP-ORDER-ORIGINAL'));
        self::assertSame(PaymentPluginStatusConstant::UNKNOWN, $unknown['status'] ?? '', '退款未知状态映射错误');

        $fallback = $plugin->refund($this->refundOrder('UP-REFUND-FALLBACK', '', 'UP-ORDER-ORIGINAL'));
        $fallbackCall = $client->calls[array_key_last($client->calls)];
        self::assertSame('UP-ORDER-ORIGINAL', $fallbackCall['out_trade_no'] ?? '', '缺少 transaction_id 时退款必须使用 out_trade_no');
        self::assertTrue(!array_key_exists('transaction_id', $fallbackCall), '退款引用必须二选一');
        self::assertSame(PaymentPluginStatusConstant::PENDING, $fallback['status'] ?? '', '商户订单号退款受理状态错误');

        self::assertThrowsClass(
            fn () => $plugin->refund($this->refundOrder('UP-REFUND-MYSTERY', 'UP-TX-ORIGINAL', 'UP-ORDER-ORIGINAL')),
            PaymentUncertainException::class,
            '未识别退款状态必须抛出不确定异常'
        );
        self::assertThrowsClass(fn () => $plugin->query(['pay_no' => 'UP-QUERY-001']), UnsupportedPaymentOperationException::class, '无完整协议时查单必须保持不支持');
        self::assertThrowsClass(fn () => $plugin->close(['pay_no' => 'UP-CLOSE-001']), UnsupportedPaymentOperationException::class, '无完整协议时关单必须保持不支持');
    }

    private function plugin(array $enabledProducts, UnionpayClient $client, ?PayOrderRepository $repository = null): UnionpayApiPayment
    {
        $plugin = new UnionpayApiPayment($repository);
        $plugin->init(array_replace(self::clientConfig(), [
            'channel_id' => 97,
            'enabled_products' => $enabledProducts,
            'wx_mp_app_id' => 'wx-mp-unit-app',
            'wx_mp_app_secret' => 'wx-mp-unit-secret',
            'wx_mini_app_id' => 'wx-mini-unit-app',
            'wx_mini_app_secret' => 'wx-mini-unit-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'alipay_oauth_app_id' => 'ali-unit-app',
            'alipay_oauth_private_key' => 'ali-unit-private',
            'alipay_oauth_public_key' => 'ali-unit-public',
            'unionpay_app_up_identifier' => 'UP-APP-IDENTIFIER',
        ]));
        $property = new ReflectionProperty(UnionpayApiPayment::class, 'client');
        $property->setValue($plugin, $client);

        return $plugin;
    }

    /**
     * 构建测试支付单。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function payOrder(string $payType, string $env, array $payment, string $payNo): array
    {
        return [
            'pay_no' => $payNo,
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '银联前置单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/notify/unionpay',
            'return_url' => 'https://merchant.unit.test/return/unionpay',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构建模拟支付响应。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function payResponse(array $payload): array
    {
        $service = (string) ($payload['service'] ?? '');
        $response = [
            'mch_id' => '1900000109',
            'sub_mch_id' => 'SUB1900000109',
        ];
        if (isset($payload['out_trade_no'])) {
            $response['out_trade_no'] = (string) $payload['out_trade_no'];
            $response['transaction_id'] = 'UP-TX-' . (string) $payload['out_trade_no'];
        }

        return $response + match ($service) {
            'unified.trade.native' => [
                'code_url' => (string) ($payload['out_trade_no'] ?? '') === 'UP-QQ-001'
                    ? 'https://myun.tenpay.com/cgi-bin/pay?t=QQ-TOKEN-001'
                    : 'https://pay.unit.test/qrcode/' . rawurlencode((string) ($payload['out_trade_no'] ?? '')),
            ],
            'pay.weixin.jspay' => ['pay_info' => json_encode([
                'appId' => (string) ($payload['sub_appid'] ?? ''),
                'timeStamp' => '1721260800',
                'nonceStr' => 'WX-NONCE',
                'package' => 'prepay_id=WX-PREPAY-ID',
                'signType' => 'MD5',
                'paySign' => 'WX-PAY-SIGN',
            ], JSON_UNESCAPED_SLASHES)],
            'pay.alipay.jspay' => ['pay_info' => '{"tradeNO":"ALI-TRADE-NO"}'],
            'pay.unionpay.userid' => ['user_id' => 'UP-USER-ID'],
            'pay.unionpay.jspay' => ['pay_url' => 'https://pay.unit.test/unionpay-jsapi'],
            'pay.weixin.wappay' => ['pay_info' => 'https://pay.unit.test/wechat-h5'],
            default => throw new RuntimeException('测试响应器收到未声明 service：' . $service),
        };
    }

    private function storedPayOrder(): PayOrder
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'UP-NOTIFY-001',
            'pay_amount' => 100,
            'channel_id' => 97,
            'channel_order_no' => 'UP-NOTIFY-001',
            'channel_trade_no' => 'UP-TX-001',
        ]);

        return $payOrder;
    }

    private function notifyPlugin(PayOrder $payOrder): UnionpayApiPayment
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

        return $this->plugin(self::ALL_PRODUCTS, new UnionpayUnitClient(static fn (): array => []), $repository);
    }

    /**
     * 构建支付通知载荷。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function notifyPayload(array $overrides = []): array
    {
        return array_replace([
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'mch_id' => '1900000109',
            'sub_mch_id' => 'SUB1900000109',
            'out_trade_no' => 'UP-NOTIFY-001',
            'total_fee' => '100',
            'trade_state' => 'SUCCESS',
            'transaction_id' => 'UP-TX-001',
        ], $overrides);
    }

    /**
     * 构建已签名 XML 通知。
     *
     * @param array<string, mixed> $payload
     */
    private function signedXml(array $payload): string
    {
        $client = new UnionpayClient(self::clientConfig());
        unset($payload['sign']);
        $payload['sign'] = $client->sign($payload);

        return $client->encodeXml($payload);
    }

    /**
     * 将字段编码为 XML 报文。
     *
     * @param array<string, mixed> $payload
     */
    private function xml(array $payload): string
    {
        return (new UnionpayClient(self::clientConfig()))->encodeXml($payload);
    }

    private function xmlRequest(string $xml): Request
    {
        $raw = "POST /notify/unionpay HTTP/1.1\r\n"
            . "Host: merchant.unit.test\r\n"
            . "Content-Type: application/xml; charset=UTF-8\r\n"
            . 'Content-Length: ' . strlen($xml) . "\r\n\r\n"
            . $xml;

        return new Request($raw);
    }

    /**
     * 构建返回指定响应的 SDK 客户端。
     *
     * @param array<string, mixed> $payload
     */
    private function sdkClientWithResponse(array $payload): UnionpayClient
    {
        $signer = new UnionpayClient(self::clientConfig());
        $payload['sign'] = $signer->sign($payload);
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], $signer->encodeXml($payload)),
        ]));

        return new UnionpayClient(self::clientConfig(), new Client([
            'handler' => $stack,
            'http_errors' => false,
            'verify' => true,
        ]));
    }

    /**
     * 构建测试退款订单。
     *
     * @return array<string, mixed>
     */
    private function refundOrder(string $refundNo, string $transactionId, string $outTradeNo): array
    {
        return [
            'pay_no' => 'UP-PAY-REFUND-001',
            'refund_no' => $refundNo,
            'amount' => 100,
            'refund_amount' => 40,
            'chan_trade_no' => $transactionId,
            'chan_order_no' => $outTradeNo,
        ];
    }

    /**
     * 断言结果未暴露私有扩展字段。
     *
     * @param array<string, mixed> $result
     */
    private static function assertNoPrivateExtensions(array $result, string $message): void
    {
        self::assertTrue(!array_key_exists('raw', $result), $message . '：不得返回 raw');
        self::assertTrue(!array_key_exists('raw_data', $result), $message . '：不得返回 raw_data');
        self::assertTrue(!array_key_exists('currency', $result), $message . '：不得返回 currency');
        foreach (array_keys($result) as $key) {
            self::assertTrue(!str_starts_with((string) $key, 'channel_') || $key === 'channel_context' || $key === 'channel_status', $message . '：不得新增 channel_*');
        }
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
