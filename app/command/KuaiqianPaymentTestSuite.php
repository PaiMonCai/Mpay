<?php

declare(strict_types=1);

namespace app\command;

use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\payment\KuaiqianApiPayment;
use app\common\sdk\kuaiqian\KuaiqianClient;
use app\common\sdk\kuaiqian\KuaiqianSdkException;
use app\exception\PaymentException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\order\PaymentPluginNotifyResultValidator;
use RuntimeException;
use support\Request;
use Throwable;

/**
 * 快钱表单 profile 定向测试。
 */
final class KuaiqianPaymentTestSuite
{
    /**
     * @var array<string, mixed>
     */
    private array $fixture;

    private KuaiqianClient $client;

    /**
     * 执行快钱定向测试。
     */
    public static function run(): void
    {
        $suite = new self();
        $suite->fixture = $suite->createCertificateFixture();
        try {
            $suite->client = new KuaiqianClient([
                'merchant_cert_password' => $suite->fixture['password'],
                'platform_cert_path' => $suite->fixture['platform_cert_path'],
                'merchant_key_path' => $suite->fixture['merchant_pfx_path'],
            ]);
            $suite->testSignatureAndHtml();
            $suite->testProductsAndIdentity();
            $suite->testCertificateAndPrivateAssetFailures();
            $suite->testNotifyValidationAndStatuses();
            $suite->testUnsupportedEncryptedOperations();
        } finally {
            $suite->cleanupCertificateFixture();
        }
    }

    private function testSignatureAndHtml(): void
    {
        $params = [
            'inputCharset' => '1',
            'pageUrl' => 'https://merchant.unit.test/return',
            'bgUrl' => 'https://merchant.unit.test/notify',
            'version' => 'mobile1.0',
            'language' => '1',
            'signType' => '4',
            'merchantAcctId' => '1001213884201',
            'orderId' => 'KQ-SIGN-001',
            'orderAmount' => '100',
            'orderTime' => '20260718123045',
            'productName' => '商品<&quot;>',
            'payType' => '21',
            'empty' => '',
        ];
        $expected = 'inputCharset=1&pageUrl=https://merchant.unit.test/return'
            . '&bgUrl=https://merchant.unit.test/notify&version=mobile1.0&language=1&signType=4'
            . '&merchantAcctId=1001213884201&orderId=KQ-SIGN-001&orderAmount=100'
            . '&orderTime=20260718123045&productName=商品<&quot;>&payType=21';
        self::assertSame($expected, $this->client->requestSigningContent($params), '快钱表单签名原文顺序错误');

        $html = $this->client->formHtml(
            KuaiqianClient::MOBILE_GATEWAY,
            $params,
            ['terminalIp' => '127.0.0.1', 'tdpformName' => 'MPAY']
        );
        self::assertTrue(str_contains($html, 'action="' . KuaiqianClient::MOBILE_GATEWAY . '"'), '快钱表单目标错误');
        self::assertTrue(!str_contains($html, '商品<&quot;>'), '快钱表单值必须 HTML 转义');
        self::assertTrue(str_contains($html, '商品&lt;&amp;quot;&gt;'), '快钱表单转义结果错误');

        preg_match('/name="signMsg" value="([^"]+)"/', $html, $matches);
        $signature = base64_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        self::assertTrue(is_string($signature) && $signature !== '', '快钱表单缺少签名');
        self::assertSame(
            1,
            openssl_verify($expected, $signature, $this->fixture['merchant_cert_pem'], OPENSSL_ALGO_SHA256),
            '快钱表单 RSA-SHA256 固定向量验签失败'
        );
        self::assertSame(
            0,
            openssl_verify($expected . '&terminalIp=127.0.0.1', $signature, $this->fixture['merchant_cert_pem'], OPENSSL_ALGO_SHA256),
            'terminalIp 不得参与快钱表单签名'
        );

        self::assertThrowsClass(
            fn () => $this->client->formHtml('https://evil.example/pay', $params),
            KuaiqianSdkException::class,
            '快钱表单必须拒绝非固定 HTTPS 网关'
        );
    }

    private function testProductsAndIdentity(): void
    {
        $cases = [
            ['27-3', 'alipay', 'alipay', [], KuaiqianClient::MOBILE_GATEWAY],
            ['21', 'bank', 'mobile', [], KuaiqianClient::MOBILE_GATEWAY],
            ['26-1', 'wxpay', 'wechat', ['openid' => 'OPENID-KQ-MP'], KuaiqianClient::MOBILE_GATEWAY],
            ['26-2', 'wxpay', 'mobile', [], KuaiqianClient::MOBILE_GATEWAY],
            ['00', 'bank', 'jump', [], KuaiqianClient::MOBILE_GATEWAY],
            ['00', 'bank', 'pc', [], KuaiqianClient::BANK_GATEWAY],
            ['10', 'bank', 'pc', [], KuaiqianClient::BANK_GATEWAY],
        ];
        foreach ($cases as [$product, $payType, $env, $payment, $gateway]) {
            $plugin = $this->plugin([$product]);
            $result = $plugin->pay($this->payOrder($payType, $env, $payment, 'KQ' . str_replace('-', '', $product) . '001'));
            self::assertSame($product, $result['pay_product'] ?? '', '快钱产品路由错误：' . $product);
            $payParams = (array) ($result['presentation']['pay_params'] ?? []);
            $resultHtml = (string) ($payParams['html'] ?? '');
            self::assertTrue(
                str_contains($resultHtml, 'action="' . $gateway . '"'),
                '快钱网关环境错误：' . $product . '；html=' . mb_strcut($resultHtml, 0, 180, 'UTF-8')
            );
            self::assertTrue(!array_key_exists('raw', $payParams), '快钱下单不得返回 raw');
        }

        $plugin = $this->plugin(['26-1']);
        $requirement = $plugin->identityRequirement($this->payOrder('wxpay', 'wechat', [], 'KQIDENTITY001'));
        self::assertSame('openid', $requirement['identity_field'] ?? '', '快钱公众号身份字段错误');
        self::assertSame(['sub_openid'], $requirement['identity_aliases'] ?? [], '快钱公众号身份别名错误');
        self::assertSame('wx1234567890abcdef', $requirement['app_id'] ?? '', '快钱公众号必须使用固定 AppID');
        self::assertSame(
            null,
            $plugin->identityRequirement($this->payOrder('wxpay', 'wechat', ['sub_openid' => 'SUB-OPENID'], 'KQIDENTITY002')),
            '已有公众号 sub_openid 时不应重复授权'
        );
        self::assertThrowsClass(
            fn () => $plugin->identityRequirement($this->payOrder('wxpay', 'wechat', ['mini_openid' => 'MINI-ID'], 'KQIDENTITY003')),
            UnsupportedPaymentOperationException::class,
            '快钱必须拒绝 mini_openid'
        );
        self::assertThrowsClass(
            fn () => $plugin->pay($this->payOrder('wxpay', 'wechat', ['method' => 'mini'], 'KQIDENTITY005')),
            UnsupportedPaymentOperationException::class,
            '快钱必须拒绝小程序支付意图'
        );
        self::assertThrowsClass(
            fn () => $plugin->pay($this->payOrder('wxpay', 'wechat', ['openid' => 'OPENID', 'sub_appid' => 'wx-wrong'], 'KQIDENTITY004')),
            PaymentException::class,
            '快钱必须拒绝错 AppID 作用域'
        );

        $schema = $plugin->getConfigSchema();
        $uploads = array_values(array_filter($schema, static fn (array $field): bool => ($field['type'] ?? '') === 'upload'));
        self::assertSame(2, count($uploads), '快钱必须提供两个证书上传字段');
        foreach ($uploads as $upload) {
            $fileUpload = (array) ($upload['props']['fileUpload'] ?? []);
            self::assertSame(FileConstant::SCENE_CERTIFICATE, $fileUpload['scene'] ?? null, '快钱证书上传场景错误');
            self::assertSame(FileConstant::VISIBILITY_PRIVATE, $fileUpload['visibility'] ?? null, '快钱证书必须私有存储');
            self::assertSame('object_key', $fileUpload['getKey'] ?? '', '快钱证书配置必须保存 object_key');
        }
    }

    private function testCertificateAndPrivateAssetFailures(): void
    {
        $secret = 'DO-NOT-LEAK-PASSWORD';
        $wrongPassword = self::capturedException(fn () => new KuaiqianClient([
            'merchant_cert_password' => $secret,
            'platform_cert_path' => $this->fixture['platform_cert_path'],
            'merchant_key_path' => $this->fixture['merchant_pfx_path'],
        ]), KuaiqianSdkException::class, '错误 PFX 密码必须失败');
        self::assertTrue(!str_contains($wrongPassword->getMessage(), $secret), '快钱证书错误不得泄露密码');
        self::assertTrue(!str_contains($wrongPassword->getMessage(), $this->fixture['merchant_pfx_path']), '快钱证书错误不得泄露路径');

        $invalidPublic = self::capturedException(fn () => new KuaiqianClient([
            'merchant_cert_password' => $this->fixture['password'],
            'platform_cert_path' => $this->fixture['invalid_cert_path'],
            'merchant_key_path' => $this->fixture['merchant_pfx_path'],
        ]), KuaiqianSdkException::class, '无效平台证书必须失败');
        self::assertTrue(!str_contains($invalidPublic->getMessage(), $this->fixture['invalid_cert_path']), '快钱公钥错误不得泄露路径');

        self::assertThrowsClass(
            fn () => $this->plugin(['21'], ['platform_cert_path' => $this->fixture['platform_cert_path']]),
            PaymentException::class,
            '快钱插件不得接受任意服务器证书路径'
        );
        self::assertThrowsClass(
            fn () => $this->plugin(['21'], ['merchant_key_path' => 'storage/private/certificate/../secret.pfx']),
            PaymentException::class,
            '快钱插件不得接受路径穿越 object_key'
        );
    }

    private function testNotifyValidationAndStatuses(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'KQNOTIFY001',
            'pay_amount' => 100,
            'channel_id' => 91,
            'channel_order_no' => 'KQNOTIFY001',
            'channel_trade_no' => '',
            'ext_json' => ['payment_context' => ['pay_product' => '21', 'pay_action' => 'mobilegateway']],
        ]);
        $plugin = $this->notifyPlugin($payOrder);
        $successPayload = $this->signedNotify();
        $success = $plugin->notify($this->queryRequest($successPayload));
        $duplicate = $plugin->notify($this->queryRequest($successPayload));
        self::assertSame(PaymentPluginStatusConstant::SUCCESS, $success['status'] ?? '', '快钱 payResult=10 必须映射成功');
        self::assertSame($success, $duplicate, '快钱重复通知必须返回相同结果');
        self::assertSame('900000000001', $success['chan_trade_no'] ?? '', '快钱 chan_trade_no 必须使用 dealId');
        self::assertSame('KQNOTIFY001', $success['chan_order_no'] ?? '', '快钱 chan_order_no 必须使用订单号');
        self::assertTrue(!array_key_exists('currency', $success) && !array_key_exists('raw_data', $success), '快钱通知不得扩展 currency/raw_data');
        foreach (array_keys($success) as $key) {
            self::assertTrue(!str_starts_with((string) $key, 'channel_'), '快钱标准通知不得返回 channel_* 字段');
        }
        PaymentPluginNotifyResultValidator::make($success)->withScene('notify_result')->validate();
        self::assertSame('<result>1</result>', $plugin->notifySuccess(), '快钱成功 ACK 错误');
        self::assertSame('<result>0</result>', $plugin->notifyFail(), '快钱失败 ACK 错误');

        $failed = $plugin->notify($this->queryRequest($this->signedNotify(['payResult' => '11', 'errCode' => '20001'])));
        self::assertSame(PaymentPluginStatusConstant::FAILED, $failed['status'] ?? '', '快钱 payResult=11 必须映射失败');
        self::assertSame(null, $failed['paid_amount'] ?? null, '快钱失败通知不得返回已支付金额');
        $unknown = $plugin->notify($this->queryRequest($this->signedNotify(['payResult' => '99'])));
        self::assertSame(PaymentPluginStatusConstant::UNKNOWN, $unknown['status'] ?? '', '快钱未定义 payResult 必须映射 unknown');
        self::assertTrue(($unknown['status'] ?? '') !== PaymentPluginStatusConstant::PENDING, '快钱表单 profile 不得猜测 pending 状态');

        $badSignature = $successPayload;
        $badSignature['signMsg'] = base64_encode(str_repeat("\0", 256));
        self::assertThrowsClass(fn () => $plugin->notify($this->queryRequest($badSignature)), PaymentException::class, '快钱错签通知必须拒绝');

        foreach ([
            '错商户' => ['merchantAcctId' => '9999999999901'],
            '错订单' => ['orderId' => 'KQNOTIFY999'],
            '错金额' => ['orderAmount' => '101'],
            '错产品' => ['payType' => '00'],
            '空状态' => ['payResult' => ''],
            '空交易号' => ['dealId' => ''],
        ] as $scene => $override) {
            self::assertThrowsClass(
                fn () => $plugin->notify($this->queryRequest($this->signedNotify($override))),
                PaymentException::class,
                '快钱回调' . $scene . '必须拒绝'
            );
        }

        $boundOrder = clone $payOrder;
        $boundOrder->channel_trade_no = '900000000099';
        self::assertThrowsClass(
            fn () => $this->notifyPlugin($boundOrder)->notify($this->queryRequest($successPayload)),
            PaymentException::class,
            '快钱回调 dealId 与已保存交易号不一致必须拒绝'
        );
    }

    private function testUnsupportedEncryptedOperations(): void
    {
        $plugin = $this->plugin(['21']);
        self::assertThrowsClass(
            fn () => $plugin->pay($this->payOrder('bank', 'mobile', ['method' => 'qrcode'], 'KQQRCODE001')),
            UnsupportedPaymentOperationException::class,
            '快钱加密二维码必须保持不支持'
        );
        self::assertThrowsClass(fn () => $plugin->query(['pay_no' => 'KQQUERY001']), UnsupportedPaymentOperationException::class, '快钱加密查单必须保持不支持');
        self::assertThrowsClass(fn () => $plugin->refund(['pay_no' => 'KQREFUND001']), UnsupportedPaymentOperationException::class, '快钱加密退款必须保持不支持');
        self::assertThrowsClass(fn () => $plugin->close(['pay_no' => 'KQCLOSE001']), UnsupportedPaymentOperationException::class, '快钱加密关单必须保持不支持');
    }

    /**
     * 创建已初始化的快钱测试插件。
     *
     * @param array<int, string> $enabledProducts
     * @param array<string, mixed> $overrides
     */
    private function plugin(array $enabledProducts, array $overrides = [], ?PayOrderRepository $repository = null): KuaiqianApiPayment
    {
        $plugin = new KuaiqianApiPayment($repository);
        $plugin->init(array_replace([
            'channel_id' => 91,
            'account_id' => '10012138842',
            'merchant_cert_password' => $this->fixture['password'],
            'platform_cert_path' => $this->fixture['platform_object_key'],
            'merchant_key_path' => $this->fixture['merchant_object_key'],
            'wechat_mp_app_id' => 'wx1234567890abcdef',
            'wechat_mp_app_secret' => 'kuaiqian-mp-secret',
            'enabled_products' => $enabledProducts,
        ], $overrides));

        return $plugin;
    }

    private function notifyPlugin(PayOrder $payOrder): KuaiqianApiPayment
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

        return $this->plugin(['21'], [], $repository);
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
            'subject' => '快钱单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/notify/kuaiqian',
            'return_url' => 'https://merchant.unit.test/return/kuaiqian',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构建已签名支付通知。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function signedNotify(array $overrides = []): array
    {
        $payload = array_replace([
            'merchantAcctId' => '1001213884201',
            'version' => 'mobile1.0',
            'language' => '1',
            'signType' => '4',
            'payType' => '21',
            'bankId' => 'ABC',
            'orderId' => 'KQNOTIFY001',
            'orderTime' => '20260718120000',
            'orderAmount' => '100',
            'dealId' => '900000000001',
            'bankDealId' => 'BANK900000001',
            'dealTime' => '20260718120101',
            'payAmount' => '100',
            'fee' => '0',
            'payResult' => '10',
            'errCode' => '',
        ], $overrides);
        unset($payload['signMsg']);
        $signature = '';
        if (!openssl_sign(
            $this->client->notifySigningContent($payload),
            $signature,
            $this->fixture['platform_private_key'],
            OPENSSL_ALGO_SHA256
        )) {
            throw new RuntimeException('无法生成快钱通知测试签名');
        }
        $payload['signMsg'] = base64_encode($signature);

        return $payload;
    }

    /**
     * 构建查单请求。
     *
     * @param array<string, mixed> $payload
     */
    private function queryRequest(array $payload): Request
    {
        $query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $raw = "GET /notify?" . $query . " HTTP/1.1\r\nHost: localhost\r\n\r\n";

        return new Request($raw);
    }

    /**
     * 创建测试证书夹具。
     *
     * @return array<string, mixed>
     */
    private function createCertificateFixture(): array
    {
        $suffix = bin2hex(random_bytes(5));
        $directoryObjectKey = 'storage/private/certificate/kuaiqian_unit_' . $suffix;
        $directory = runtime_path($directoryObjectKey);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建快钱证书测试目录');
        }

        $merchantFile = $directory . DIRECTORY_SEPARATOR . 'merchant.pfx';
        $platformFile = $directory . DIRECTORY_SEPARATOR . 'platform.cer';
        $invalidFile = $directory . DIRECTORY_SEPARATOR . 'invalid.cer';
        try {
            $merchant = $this->certificatePair('MPAY Kuaiqian Merchant Unit');
            $platform = $this->certificatePair('MPAY Kuaiqian Platform Unit');
            $password = 'kuaiqian-unit-password';
            $pfx = '';
            if (!openssl_pkcs12_export($merchant['certificate'], $pfx, $merchant['private_key'], $password)) {
                throw new RuntimeException('无法导出快钱测试 PFX');
            }
            if (file_put_contents($merchantFile, $pfx) === false
                || file_put_contents($platformFile, $platform['certificate_pem']) === false
                || file_put_contents($invalidFile, 'not-a-certificate') === false) {
                throw new RuntimeException('无法写入快钱证书测试文件');
            }

            return [
                'directory' => $directory,
                'files' => [$merchantFile, $platformFile, $invalidFile],
                'password' => $password,
                'merchant_pfx_path' => $merchantFile,
                'platform_cert_path' => $platformFile,
                'invalid_cert_path' => $invalidFile,
                'merchant_object_key' => $directoryObjectKey . '/merchant.pfx',
                'platform_object_key' => $directoryObjectKey . '/platform.cer',
                'merchant_cert_pem' => $merchant['certificate_pem'],
                'platform_private_key' => $platform['private_key'],
            ];
        } catch (Throwable $e) {
            foreach ([$merchantFile, $platformFile, $invalidFile] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            throw $e;
        }
    }

    /**
     * 生成测试证书和密钥对。
     *
     * @return array<string, mixed>
     */
    private function certificatePair(string $commonName): array
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
            'config' => base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ];
        $privateKey = openssl_pkey_new($options);
        $csr = $privateKey === false ? false : openssl_csr_new(['commonName' => $commonName], $privateKey, $options);
        $certificate = $csr === false || $privateKey === false
            ? false
            : openssl_csr_sign($csr, null, $privateKey, 365, $options, random_int(1000, 999999));
        $certificatePem = '';
        if ($certificate === false
            || !openssl_x509_export($certificate, $certificatePem)) {
            throw new RuntimeException('无法生成快钱测试证书');
        }

        return [
            'private_key' => $privateKey,
            'certificate' => $certificate,
            'certificate_pem' => $certificatePem,
        ];
    }

    private function cleanupCertificateFixture(): void
    {
        foreach ((array) ($this->fixture['files'] ?? []) as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        $directory = $this->fixture['directory'] ?? '';
        if (is_string($directory) && is_dir($directory)) {
            rmdir($directory);
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
