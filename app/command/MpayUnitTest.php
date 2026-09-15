<?php

namespace app\command;

use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentExceptionConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\TradeConstant;
use app\common\constant\TransferConstant;
use app\common\util\RsaKeyPairGenerator;
use app\common\payment\WechatApiPayment;
use app\common\payment\AlipayApiPayment;
use app\common\payment\AdapayApiPayment;
use app\common\payment\AllinpayApiPayment;
use app\common\payment\ChinaumsApiPayment;
use app\common\payment\DuolabaoApiPayment;
use app\common\payment\EasypayApiPayment;
use app\common\payment\EpayV2Payment;
use app\common\payment\FubeiApiPayment;
use app\common\payment\FuiouApiPayment;
use app\common\payment\HaipayApiPayment;
use app\common\payment\HuifuApiPayment;
use app\common\payment\JeepayApiPayment;
use app\common\payment\LakalaApiPayment;
use app\common\payment\SandpayApiPayment;
use app\common\payment\SuixingpayApiPayment;
use app\common\payment\TianqueTechApiPayment;
use app\common\payment\XorpayApiPayment;
use app\common\payment\XunhupayApiPayment;
use app\common\payment\YeepayApiPayment;
use app\common\payment\ZhangyishouApiPayment;
use app\common\payment\WechatReceiptPayment;
use app\common\sdk\alipay\AlipayCertificate;
use app\common\sdk\alipay\AlipayClient;
use app\common\sdk\alipay\AlipayConfig;
use app\common\sdk\alipay\AlipayResponse;
use app\common\sdk\alipay\AlipaySigner;
use app\common\sdk\adapay\AdapayClient;
use app\common\sdk\adapay\AdapaySdkException;
use app\common\sdk\allinpay\AllinpayClient;
use app\common\sdk\chinaums\ChinaumsClient;
use app\common\sdk\duolabao\DuolabaoClient;
use app\common\sdk\duolabao\DuolabaoSdkException;
use app\common\sdk\easypay\EasypayClient;
use app\common\sdk\easypay\EasypaySdkException;
use app\common\sdk\fubei\FubeiClient;
use app\common\sdk\fuiou\FuiouPayClient;
use app\common\sdk\fuiou\FuiouSdkException;
use app\common\sdk\haipay\HaipayClient;
use app\common\sdk\haipay\HaipaySdkException;
use app\common\sdk\huifu\HuifuClient;
use app\common\sdk\huifu\HuifuSdkException;
use app\common\sdk\jeepay\JeepayClient;
use app\common\sdk\jeepay\JeepaySdkException;
use app\common\sdk\lakala\LakalaOpenApiClient;
use app\common\sdk\lakala\LakalaSdkException;
use app\common\sdk\sandpay\SandpayClient;
use app\common\sdk\sandpay\SandpaySdkException;
use app\common\sdk\wxpay\WxpayClient;
use app\common\sdk\wxpay\WxpayConfig;
use app\common\sdk\wxpay\WxpaySigner;
use app\common\sdk\wxpay\WxpayXml;
use app\common\sdk\suixingpay\SuixingpayClient;
use app\common\sdk\tianquetech\TianqueTechClient;
use app\common\sdk\xorpay\XorpayClient;
use app\common\sdk\xorpay\XorpaySdkException;
use app\common\sdk\xunhupay\XunhupayClient;
use app\common\sdk\xunhupay\XunhupaySdkException;
use app\common\sdk\yeepay\YeepaySdkException;
use app\common\sdk\yeepay\YeepayYopClient;
use app\common\sdk\zhangyishou\ZhangyishouClient;
use app\common\sdk\zhangyishou\ZhangyishouSdkException;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\RefundNotifyInterface;
use app\common\interface\RefundQueryInterface;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPlugin;
use app\model\payment\PaymentPluginConf;
use app\model\payment\RefundOrder;
use app\service\payment\epay\Md5Signer;
use app\service\payment\epay\RsaSigner;
use app\service\payment\order\PayOrderCallbackService;
use app\service\payment\order\PaymentPluginCloseResultValidator;
use app\service\payment\order\PaymentPluginNotifyResultValidator;
use app\service\payment\order\PaymentPluginPayResultValidator;
use app\service\payment\order\PaymentPluginQueryResultValidator;
use app\service\payment\order\PaymentPluginRefundResultValidator;
use app\service\payment\order\RefundDispatchService;
use app\service\payment\order\RefundLifecycleService;
use app\service\payment\order\RefundOrderCallbackService;
use app\service\payment\identity\PaymentIdentityService;
use app\service\payment\runtime\NotifyService;
use app\service\payment\runtime\PaymentPluginManager;
use app\service\payment\runtime\PaymentPluginFactoryService;
use app\service\payment\runtime\PaymentRouteResolverService;
use app\service\payment\runtime\PaymentRuntimeMaintenanceService;
use app\service\payment\transfer\TransferService;
use app\service\system\config\SystemConfigRuntimeService;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use support\Request;
use support\Cache;

/**
 * 付呗插件单元测试客户端。记录方法和业务报文，不访问网络。
 */
final class FubeiUnitClient extends FubeiClient
{
    /**
     * @var array<int, array{method:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'vendor_sn' => 'VENDOR-TEST',
            'app_secret' => 'fubei-unit-secret',
            'api_gateway' => 'https://fubei.unit.test/gateway',
        ]);
    }

    public function execute(string $method, array $bizContent): array
    {
        $this->calls[] = ['method' => $method, 'data' => $bizContent];
        $result = ($this->responder)($method, $bizContent, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid && parent::verify($payload);
    }
}

/**
 * 银联商务单元测试客户端。记录接口路径和整数分报文，不访问网络。
 */
final class ChinaumsUnitClient extends ChinaumsClient
{
    /**
     * @var array<int, array{path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    /**
     * 构造测试客户端。
     *
     * @param array<string, mixed> $config
     */
    public function __construct(callable $responder, array $config)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct($config);
    }

    public function request(string $path, array $params): array
    {
        $this->calls[] = ['path' => $path, 'data' => $params];
        $result = ($this->responder)($path, $params, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 汇付插件单元测试客户端。记录官方接口路径与业务报文，不访问网络。
 */
final class HuifuUnitClient extends HuifuClient
{
    /**
     * @var array<int, array{path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'sys_id' => 'SYS-HUIFU',
            'product_id' => 'P-HUIFU',
            'merchant_private_key' => 'unused',
            'huifu_public_key' => 'unused',
        ]);
    }

    public function request(string $path, array $data): array
    {
        $this->calls[] = ['path' => $path, 'data' => $data];
        $result = ($this->responder)($path, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verifyNotify(string $data, string $sign): bool
    {
        return $this->signatureValid && $data !== '' && $sign === 'valid-sign';
    }
}

/**
 * 天阙插件单元测试客户端。只记录路径和业务报文，不访问网络。
 */
final class TianqueTechUnitClient extends TianqueTechClient
{
    /**
     * @var array<int, array{path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'org_id' => 'ORG-TEST',
            'merchant_no' => 'MNO-TEST',
            'platform_public_key' => 'unused',
            'merchant_private_key' => 'unused',
        ]);
    }

    public function submit(string $path, array $data): array
    {
        $this->calls[] = ['path' => $path, 'data' => $data];
        $result = ($this->responder)($path, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid && (string) ($payload['sign'] ?? '') === 'valid-sign';
    }

    public function lastRequestId(): string
    {
        return 'tianque-unit-request';
    }
}

/**
 * 随行付插件独立单元测试客户端。只记录路径和业务报文，不访问网络。
 */
final class SuixingpayUnitClient extends SuixingpayClient
{
    /**
     * @var array<int, array{path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'suixingpay_org_id' => 'SXP-ORG-TEST',
            'suixingpay_merchant_no' => 'SXP-MNO-TEST',
            'suixingpay_platform_public_key' => 'unused',
            'suixingpay_merchant_private_key' => 'unused',
        ]);
    }

    public function submit(string $path, array $data): array
    {
        $this->calls[] = ['path' => $path, 'data' => $data];
        $result = ($this->responder)($path, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid && (string) ($payload['sign'] ?? '') === 'valid-sign';
    }

    public function lastRequestId(): string
    {
        return 'suixingpay-unit-request';
    }
}

/**
 * 拉卡拉插件单元测试客户端。记录 LABS、v3、CCSS 和 MMS 调用，不访问网络。
 */
final class LakalaUnitClient extends LakalaOpenApiClient
{
    /**
     * @var array<int, array{kind:string,path:string,data:array<string,mixed>,term:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
    }

    public function execute(string $path, array $params): array
    {
        return $this->respond('legacy', $path, $params, []);
    }

    public function labs(string $path, array $params, array $termExtInfo): array
    {
        return $this->respond('labs', $path, $params, $termExtInfo);
    }

    public function cashier(string $path, array $params): array
    {
        return $this->respond('cashier', $path, $params, []);
    }

    public function mms(string $path, array $params): array
    {
        return $this->respond('mms', $path, $params, []);
    }

    public function verifyNotify(string $authorization, string $body): bool
    {
        return $this->signatureValid && $authorization !== '' && $body !== '';
    }

    /**
     * 生成模拟渠道响应。
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $term
     */
    private function respond(string $kind, string $path, array $data, array $term): array
    {
        $this->calls[] = compact('kind', 'path', 'data', 'term');
        $result = ($this->responder)($kind, $path, $data, $term, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 富友插件单元测试客户端。记录接口路径和 UTF-8 业务字段，不访问网络。
 */
final class FuiouUnitClient extends FuiouPayClient
{
    /**
     * @var array<int, array{path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'institution_code' => 'INS-TEST',
            'merchant_no' => 'MCH-TEST',
            'merchant_private_key' => 'unused',
            'platform_public_key' => 'unused',
        ]);
    }

    public function submit(string $path, array $params): array
    {
        $this->calls[] = ['path' => $path, 'data' => $params];
        $result = ($this->responder)($path, $params, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verifyNotify(array $payload): bool
    {
        return $this->signatureValid && trim((string) ($payload['sign'] ?? '')) !== '';
    }
}

/**
 * 通联收银宝单元测试客户端。记录接口、version 和整数分报文，不访问网络。
 */
final class AllinpayUnitClient extends AllinpayClient
{
    /**
     * @var array<int, array{kind:string,url:string,data:array<string,mixed>,version:string}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'merchant_no' => 'CUS-ALLINPAY',
            'app_id' => 'APP-ALLINPAY',
            'merchant_private_key' => 'unused',
            'platform_public_key' => 'unused',
        ]);
    }

    public function submit(string $url, array $params, string $version = self::VERSION): array
    {
        $this->calls[] = ['kind' => 'submit', 'url' => $url, 'data' => $params, 'version' => $version];
        $result = ($this->responder)($url, $params, $version, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function cashierPayload(array $params): array
    {
        $this->calls[] = ['kind' => 'cashier', 'url' => '', 'data' => $params, 'version' => self::CASHIER_VERSION];

        return [
            'cusid' => 'CUS-ALLINPAY',
            'appid' => 'APP-ALLINPAY',
            'version' => self::CASHIER_VERSION,
            'randomstr' => 'unit-random',
            'signtype' => self::SIGN_TYPE,
            ...$params,
            'sign' => 'unit-sign',
        ];
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid && (string) ($payload['sign'] ?? '') === 'valid-sign';
    }
}

/**
 * AdaPay 单元测试客户端。记录确定的产品动作与协议字段，不访问网络。
 */
final class AdapayUnitClient extends AdapayClient
{
    /**
     * @var array<int, array{action:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'app_id' => 'APP_ADAPAY_UNIT',
            'api_key' => 'adapay-unit-api-key',
            'merchant_private_key' => 'unused',
            'platform_public_key' => 'unused',
        ]);
    }

    public function createPayment(array $payload): array
    {
        return $this->respond('create', $payload);
    }

    public function pageRequest(string $funcCode, array $payload): array
    {
        return $this->respond('page', ['func_code' => $funcCode] + $payload);
    }

    public function queryPayment(string $paymentId): array
    {
        return $this->respond('query', ['payment_id' => $paymentId]);
    }

    public function refund(string $paymentId, array $payload): array
    {
        return $this->respond('refund', ['payment_id' => $paymentId] + $payload);
    }

    public function queryRefund(string $refundId): array
    {
        return $this->respond('query_refund', ['refund_id' => $refundId]);
    }

    public function verifyNotify(string $sign, string $data): bool
    {
        return $this->signatureValid && hash_equals('valid-sign', $sign) && $data !== '';
    }

    /**
     * 构造模拟上游响应。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function respond(string $action, array $data): array
    {
        $this->calls[] = compact('action', 'data');
        $result = ($this->responder)($action, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 易宝单元测试客户端。记录 YOP 方法、路径和业务参数，不访问网络。
 */
final class YeepayUnitClient extends YeepayYopClient
{
    /**
     * @var array<int, array{method:string,path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'app_key' => 'YOP-UNIT-APP',
            'merchant_private_key' => 'unused',
            'platform_public_key' => 'unused',
        ]);
    }

    public function post(string $path, array $params): array
    {
        return $this->respond('POST', $path, $params);
    }

    public function get(string $path, array $params): array
    {
        return $this->respond('GET', $path, $params);
    }

    public function notifyDecrypt(string $source): array
    {
        return $this->respond('NOTIFY', $source, []);
    }

    /**
     * 生成模拟渠道响应。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function respond(string $method, string $path, array $data): array
    {
        $this->calls[] = compact('method', 'path', 'data');
        $result = ($this->responder)($method, $path, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 杉德单元测试客户端。只记录 V4 路径与解密后的业务参数，不访问网络。
 */
final class SandpayUnitClient extends SandpayClient
{
    /**
     * @var array<int, array{path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
    }

    public function execute(string $path, array $params): array
    {
        $this->calls[] = ['path' => $path, 'data' => $params];
        $result = ($this->responder)($path, $params, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verify(string $bizData, string $sign): bool
    {
        return $this->signatureValid && $bizData !== '' && hash_equals('valid-sign', $sign);
    }
}

/**
 * XorPay 单元测试客户端。记录协议动作和参数，不访问网络。
 */
final class XorpayUnitClient extends XorpayClient
{
    /**
     * @var array<int, array{action:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'app_id' => 'XORPAY-UNIT-AID',
            'app_secret' => 'xorpay-unit-secret',
        ]);
    }

    public function pay(array $params): array
    {
        return $this->respond('pay', $params);
    }

    public function cashierPayload(array $params): array
    {
        $payload = parent::cashierPayload($params);
        $this->calls[] = ['action' => 'cashier', 'data' => $payload];

        return $payload;
    }

    public function query(string $xorpayOrderId): array
    {
        return $this->respond('query', ['xorpay_order_id' => $xorpayOrderId]);
    }

    public function refund(string $xorpayOrderId, string $amount): array
    {
        return $this->respond('refund', [
            'xorpay_order_id' => $xorpayOrderId,
            'amount' => $amount,
        ]);
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid && parent::verify($payload);
    }

    /**
     * 构造模拟上游响应。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function respond(string $action, array $data): array
    {
        $this->calls[] = ['action' => $action, 'data' => $data];
        $result = ($this->responder)($action, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 虎皮椒单元测试客户端。记录精确动作和业务字段，不访问网络。
 */
final class XunhupayUnitClient extends XunhupayClient
{
    /**
     * @var array<int, array{action:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    /**
     * @var array<int, string>
     */
    public array $qrcodeUrls = [];

    private \Closure $responder;

    public function __construct(
        callable $responder,
        private readonly bool $signatureValid = true,
        private readonly string $qrcodeContent = 'weixin://wxpay/bizpayurl?pr=unit-test'
    ) {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'appid' => 'XUNHU-APP-001',
            'api_key' => 'xunhu-unit-secret',
            'api_url' => 'https://api.xunhupay.com/payment/do.html',
        ]);
    }

    public function pay(array $params): array
    {
        return $this->respond('pay', $params);
    }

    public function query(array $params): array
    {
        return $this->respond('query', $params);
    }

    public function refund(array $params): array
    {
        return $this->respond('refund', $params);
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid && parent::verify($payload);
    }

    public function parseQrcode(string $qrcodeUrl): string
    {
        $this->qrcodeUrls[] = $qrcodeUrl;

        return $this->qrcodeContent;
    }

    /**
     * 构造模拟上游响应。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function respond(string $action, array $data): array
    {
        $this->calls[] = ['action' => $action, 'data' => $data];
        $result = ($this->responder)($action, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 掌易收单元测试客户端。记录 AddOrder 和退款字段，不访问网络。
 */
final class ZhangyishouUnitClient extends ZhangyishouClient
{
    /**
     * @var array<int, array{operation:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'merchant_no' => 'MNO-UNIT',
            'api_key' => 'secret-key',
        ]);
    }

    public function addOrder(array $params): array
    {
        return $this->respond('add_order', $params);
    }

    public function refund(array $params): array
    {
        return $this->respond('refund', $params);
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid && parent::verify($payload);
    }

    /**
     * 生成模拟渠道响应。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function respond(string $operation, array $data): array
    {
        $this->calls[] = compact('operation', 'data');
        $result = ($this->responder)($operation, $data, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 哆啦宝单元测试客户端。记录 rainbow_legacy 路径和业务报文，不访问网络。
 */
final class DuolabaoUnitClient extends DuolabaoClient
{
    /**
     * @var array<int, array{path:string,data:array<string,mixed>}>
     */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'access_key' => 'duolabao-unit-access',
            'secret_key' => 'duolabao-unit-secret',
        ]);
    }

    public function post(string $path, array $payload): array
    {
        $this->calls[] = ['path' => $path, 'data' => $payload];
        $result = ($this->responder)($path, $payload, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * 易生易企通单元测试客户端。记录确定接口与业务报文，不访问网络。
 */
final class EasypayUnitClient extends EasypayClient
{
    /** @var array<int, array{path:string,data:array<string,mixed>}> */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'req_id' => 'EASYPAY-UNIT',
            'req_type' => '2',
            'platform_public_key' => 'unused-public-key',
            'merchant_private_key' => 'unused-private-key',
            'sandbox' => true,
        ]);
    }

    public function execute(string $path, array $body): array
    {
        $this->calls[] = ['path' => $path, 'data' => $body];
        $result = ($this->responder)($path, $body, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verify(array $header, array $body, string $sign): bool
    {
        return $this->signatureValid && $sign === 'valid-sign';
    }
}

/**
 * 海科融通单元测试客户端。记录 SaaS V2 路径与业务报文，不访问网络。
 */
final class HaipayUnitClient extends HaipayClient
{
    /** @var array<int, array{path:string,data:array<string,mixed>}> */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder, private readonly bool $signatureValid = true)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'access_id' => 'HAIPAY-UNIT-ACCESS',
            'access_key' => 'haipay-unit-secret',
            'sandbox' => true,
            'sandbox_gateway' => 'http://39.106.187.68:8080',
        ]);
    }

    public function post(string $path, array $payload): array
    {
        $this->calls[] = ['path' => $path, 'data' => $payload];
        $result = ($this->responder)($path, $payload, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }

    public function verify(array $payload): bool
    {
        return $this->signatureValid;
    }
}

/**
 * Jeepay 单元测试客户端。记录商户 API 路径和已签名前业务报文，不访问网络。
 */
final class JeepayUnitClient extends JeepayClient
{
    /** @var array<int, array{path:string,data:array<string,mixed>}> */
    public array $calls = [];

    private \Closure $responder;

    public function __construct(callable $responder)
    {
        $this->responder = \Closure::fromCallable($responder);
        parent::__construct([
            'api_url' => 'https://jeepay.unit.test',
            'api_key' => 'jeepay-unit-secret',
        ]);
    }

    public function post(string $path, array $payload): array
    {
        $this->calls[] = ['path' => $path, 'data' => $payload];
        $result = ($this->responder)($path, $payload, count($this->calls));
        if ($result instanceof Throwable) {
            throw $result;
        }

        return is_array($result) ? $result : [];
    }
}

/**
 * MPAY 主链路轻量单元测试命令。
 *
 * 不依赖真实第三方通道和数据库写入，用于固定签名、插件契约、金额解析与路由过滤等核心规则。
 */
#[AsCommand('mpay:unit-test', '运行 MPAY 主链路轻量单元测试')]
class MpayUnitTest extends Command
{
    /**
     * @var array<int, string>
     */
    private array $failures = [];

    /**
     * 执行测试。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 输出对象
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cases = [
            'epay.md5_signer' => fn () => $this->testMd5Signer(),
            'epay.rsa_signer' => fn () => $this->testRsaSigner(),
            'epay.payment_presentation_contract' => fn () => $this->testEpayPaymentPresentationContract(),
            'alipay.rsa2_and_encoding' => fn () => $this->testAlipayRsa2AndEncoding(),
            'alipay.amount_conversion' => fn () => $this->testAlipayAmountConversion(),
            'alipay.gateway_response_signature' => fn () => $this->testAlipayGatewayResponseSignature(),
            'alipay.notify_business_validation' => fn () => $this->testAlipayNotifyBusinessValidation(),
            'alipay.product_and_identity_resolution' => fn () => $this->testAlipayProductAndIdentityResolution(),
            'alipay.certificate_mode' => fn () => $this->testAlipayCertificateMode(),
            'alipay.query_close_cancel_refund' => fn () => $this->testAlipayQueryCloseCancelRefund(),
            'wxpay.v2_signer_official_vectors' => fn () => $this->testWxpayV2SignerOfficialVectors(),
            'wxpay.v2_xml_empty_fields' => fn () => $this->testWxpayV2XmlEmptyFields(),
            'wxpay.v2_response_and_sandbox' => fn () => $this->testWxpayV2ResponseAndSandbox(),
            'wxpay.v3_public_key_verifier' => fn () => $this->testWxpayV3PublicKeyVerifier(),
            'plugin.wechat_api_notify_validation' => fn () => $this->testWechatApiNotifyValidation(),
            'chinaums.sdk_signature_and_form' => fn () => $this->testChinaumsSdkSignatureAndForm(),
            'chinaums.product_routing_and_h5_form' => fn () => $this->testChinaumsProductRoutingAndH5Form(),
            'chinaums.notify_business_validation' => fn () => $this->testChinaumsNotifyBusinessValidation(),
            'chinaums.query_close_refund' => fn () => $this->testChinaumsQueryCloseRefund(),
            'allinpay.sdk_rsa_and_response_signature' => fn () => $this->testAllinpaySdkRsaAndResponseSignature(),
            'allinpay.product_identity_and_presentation' => fn () => $this->testAllinpayProductIdentityAndPresentation(),
            'allinpay.notify_business_validation' => fn () => $this->testAllinpayNotifyBusinessValidation(),
            'allinpay.query_close_refund' => fn () => $this->testAllinpayQueryCloseRefund(),
            'adapay.sdk_rsa_sha1_headers_tls_and_response_signature' => fn () => $this->testAdapaySdkProtocolAndResponseSignature(),
            'adapay.five_products_identity_and_presentation' => fn () => $this->testAdapayProductsIdentityAndPresentation(),
            'adapay.notify_business_validation' => fn () => $this->testAdapayNotifyBusinessValidation(),
            'adapay.query_refund_and_unsupported_close' => fn () => $this->testAdapayQueryRefundAndUnsupportedClose(),
            'duolabao.token_and_raw_body' => fn () => $this->testDuolabaoTokenAndRawBody(),
            'duolabao.products_identity_and_presentation' => fn () => $this->testDuolabaoProductsIdentityAndPresentation(),
            'duolabao.notify_business_validation' => fn () => $this->testDuolabaoNotifyBusinessValidation(),
            'duolabao.refund_and_unsupported_operations' => fn () => $this->testDuolabaoRefundAndUnsupportedOperations(),
            'easypay.rsa2_digest_json_and_profiles' => fn () => $this->testEasypaySdkProtocolAndProfiles(),
            'easypay.six_products_and_strict_identity' => fn () => $this->testEasypayProductsAndIdentity(),
            'easypay.query_and_notify_validation' => fn () => $this->testEasypayQueryAndNotifyValidation(),
            'easypay.refund_and_capability_boundary' => fn () => $this->testEasypayRefundAndCapabilities(),
            'haipay.md5_recursive_tls_and_response_signature' => fn () => $this->testHaipaySdkProtocolAndEnvironment(),
            'haipay.six_products_exact_qr_and_identity' => fn () => $this->testHaipayProductsAndIdentity(),
            'haipay.passive_query_close_refund' => fn () => $this->testHaipayPassiveQueryCloseRefund(),
            'haipay.notify_business_validation' => fn () => $this->testHaipayNotifyValidation(),
            'jeepay.md5_https_and_response_signature' => fn () => $this->testJeepayMd5HttpsAndResponseSignature(),
            'jeepay.stable_products_identity_and_pay_data_types' => fn () => $this->testJeepayProductsIdentityAndPayDataTypes(),
            'jeepay.notify_business_validation' => fn () => $this->testJeepayNotifyBusinessValidation(),
            'jeepay.refund_and_refund_notify_capability' => fn () => $this->testJeepayRefundAndRefundNotify(),
            'kuaiqian.form_profile_security_and_notify' => fn () => KuaiqianPaymentTestSuite::run(),
            'unionpay.legacy_xml_products_identity_notify_refund' => fn () => UnionpayPaymentTestSuite::run(),
            'ysepay.rsa_products_identity_notify_refund' => fn () => YsepayPaymentTestSuite::run(),
            'fubei.sdk_signature_and_credentials' => fn () => $this->testFubeiSdkSignatureAndCredentials(),
            'fubei.product_identity_and_requests' => fn () => $this->testFubeiProductIdentityAndRequests(),
            'fubei.notify_business_validation' => fn () => $this->testFubeiNotifyBusinessValidation(),
            'fubei.query_close_refund' => fn () => $this->testFubeiQueryCloseRefund(),
            'fuiou.sdk_gbk_xml_rsa_md5_and_environment' => fn () => $this->testFuiouSdkProtocolVectors(),
            'fuiou.product_identity_and_payloads' => fn () => $this->testFuiouProductIdentityAndPayloads(),
            'fuiou.barcode_pending_query_and_revoke' => fn () => $this->testFuiouBarcodePendingAndRevoke(),
            'fuiou.notify_business_validation' => fn () => $this->testFuiouNotifyBusinessValidation(),
            'fuiou.query_close_and_refund' => fn () => $this->testFuiouQueryCloseRefund(),
            'huifu.sdk_signature_contract' => fn () => $this->testHuifuSdkSignatureContract(),
            'huifu.product_identity_and_routing' => fn () => $this->testHuifuProductIdentityAndRouting(),
            'huifu.notify_order_amount_merchant' => fn () => $this->testHuifuNotifyOrderAmountMerchant(),
            'huifu.query_close_refund_and_pending' => fn () => $this->testHuifuQueryCloseRefundAndPending(),
            'tianquetech.sdk_envelope_and_signature' => fn () => $this->testTianqueSdkEnvelopeAndSignature(),
            'tianquetech.product_identity_and_routing' => fn () => $this->testTianqueProductIdentityAndRouting(),
            'tianquetech.notify_business_validation' => fn () => $this->testTianqueNotifyBusinessValidation(),
            'tianquetech.query_close_refund_idempotency' => fn () => $this->testTianqueQueryCloseRefundIdempotency(),
            'suixingpay.sdk_envelope_and_signature' => fn () => $this->testSuixingpaySdkEnvelopeAndSignature(),
            'suixingpay.product_identity_and_routing' => fn () => $this->testSuixingpayProductIdentityAndRouting(),
            'suixingpay.notify_business_validation' => fn () => $this->testSuixingpayNotifyBusinessValidation(),
            'suixingpay.query_close_refund_idempotency' => fn () => $this->testSuixingpayQueryCloseRefundIdempotency(),
            'sandpay.sdk_crypto_and_certificates' => fn () => $this->testSandpaySdkCryptoAndCertificates(),
            'sandpay.product_identity_and_private_assets' => fn () => $this->testSandpayProductIdentityAndPrivateAssets(),
            'sandpay.notify_business_validation' => fn () => $this->testSandpayNotifyBusinessValidation(),
            'sandpay.query_refund_and_refund_notify' => fn () => $this->testSandpayQueryRefundAndRefundNotify(),
            'sandpay.refund_callback_lifecycle' => fn () => $this->testSandpayRefundCallbackLifecycle(),
            'yeepay.yop_auth_v3_and_notify_vectors' => fn () => $this->testYeepayYopAuthV3AndNotifyVectors(),
            'yeepay.product_identity_and_presentation' => fn () => $this->testYeepayProductIdentityAndPresentation(),
            'yeepay.notify_business_validation' => fn () => $this->testYeepayNotifyBusinessValidation(),
            'yeepay.query_close_refund' => fn () => $this->testYeepayQueryCloseRefund(),
            'lakala.signature_contract' => fn () => $this->testLakalaSignatureContract(),
            'lakala.product_identity_and_routing' => fn () => $this->testLakalaProductIdentityAndRouting(),
            'lakala.notify_business_validation' => fn () => $this->testLakalaNotifyBusinessValidation(),
            'lakala.pending_query_reverse_refund' => fn () => $this->testLakalaPendingQueryReverseRefund(),
            'lakala.onboarding_contract' => fn () => $this->testLakalaOnboardingContract(),
            'xorpay.md5_paths_and_response_semantics' => fn () => $this->testXorpayMd5PathsAndResponseSemantics(),
            'xorpay.product_routing_and_cashier_form' => fn () => $this->testXorpayProductRoutingAndCashierForm(),
            'xorpay.notify_business_validation' => fn () => $this->testXorpayNotifyBusinessValidation(),
            'xorpay.query_refund_and_unsupported_close' => fn () => $this->testXorpayQueryRefundAndUnsupportedClose(),
            'xunhupay.md5_https_and_official_requests' => fn () => $this->testXunhupayMd5HttpsAndOfficialRequests(),
            'xunhupay.product_routing_and_wap_fields' => fn () => $this->testXunhupayProductRoutingAndWapFields(),
            'xunhupay.qrcode_safety' => fn () => $this->testXunhupayQrcodeSafety(),
            'xunhupay.query_and_refund_semantics' => fn () => $this->testXunhupayQueryAndRefundSemantics(),
            'xunhupay.notify_business_validation' => fn () => $this->testXunhupayNotifyBusinessValidation(),
            'zhangyishou.signature_and_response_contract' => fn () => $this->testZhangyishouSignatureAndResponseContract(),
            'zhangyishou.product_routing_and_presentation' => fn () => $this->testZhangyishouProductRoutingAndPresentation(),
            'zhangyishou.notify_business_validation' => fn () => $this->testZhangyishouNotifyBusinessValidation(),
            'zhangyishou.refund_and_unsupported_operations' => fn () => $this->testZhangyishouRefundAndUnsupportedOperations(),
            'plugin.pay_result_contract' => fn () => $this->testPaymentPluginPayResultContract(),
            'plugin.notify_result_contract' => fn () => $this->testPaymentPluginNotifyResultContract(),
            'plugin.operation_result_contract' => fn () => $this->testPaymentPluginOperationResultContract(),
            'plugin.payment_context_contract' => fn () => $this->testPaymentContextContract(),
            'plugin.uncertain_result_never_fallback' => fn () => $this->testUncertainResultNeverFallback(),
            'payment.late_duplicate_presentation' => fn () => $this->testLateDuplicatePresentation(),
            'payment.recovery_architecture_contract' => fn () => $this->testPaymentRecoveryArchitectureContract(),
            'plugin.config_namespace' => fn () => $this->testPaymentPluginConfigNamespace(),
            'refund.dispatch_abnormal_presentation' => fn () => $this->testRefundDispatchAbnormalPresentation(),
            'cashier.payment_state_presentation' => fn () => $this->testCashierPaymentStatePresentation(),
            'plugin.receipt_watcher_runtime_contract' => fn () => $this->testReceiptWatcherRuntimeContract(),
            'plugin.receipt_watcher_stream_contract' => fn () => $this->testReceiptWatcherStreamContract(),
            'plugin.wechat_receipt_amount_parser' => fn () => $this->testWechatReceiptAmountParser(),
            'transfer.money_parser' => fn () => $this->testTransferMoneyParser(),
            'transfer.status_semantics' => fn () => $this->testTransferStatusSemantics(),
            'route.amount_and_daily_limit' => fn () => $this->testRouteAmountAndDailyLimit(),
            'route.default_channel_selection' => fn () => $this->testRouteDefaultChannelSelection(),
            'route.reject_reasons' => fn () => $this->testRouteRejectReasons(),
            'callback.payload_contract' => fn () => $this->testCallbackPayloadContract(),
            'callback.duplicate_request_hash' => fn () => $this->testCallbackDuplicateRequestHash(),
            'notify.retry_policy' => fn () => $this->testNotifyRetryPolicy(),
            'identity.contract_and_claim' => fn () => $this->testIdentityContractAndClaim(),
            'security.sensitive_masking' => fn () => $this->testSensitiveMasking(),
            'security.payment_tls_verification' => fn () => $this->testPaymentTlsVerification(),
        ];

        $passed = 0;
        foreach ($cases as $name => $case) {
            try {
                $case();
                $passed++;
                $output->writeln(sprintf('<info>[通过]</info> %s', $name));
            } catch (Throwable $e) {
                $this->failures[] = sprintf('%s: %s', $name, $e->getMessage());
                $output->writeln(sprintf('<error>[失败]</error> %s - %s', $name, $e->getMessage()));
            }
        }

        $output->writeln(sprintf('汇总: %d 通过, %d 失败', $passed, count($this->failures)));
        foreach ($this->failures as $failure) {
            $output->writeln('<error>- ' . $failure . '</error>');
        }

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 哆啦宝固定签名向量与实际 HTTP 原始报文保持一致。
     */
    private function testDuolabaoTokenAndRawBody(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], '{"success":true,"requestNum":"P-RAW"}'),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new DuolabaoClient(
            ['access_key' => 'unit-access', 'secret_key' => 'unit-secret'],
            new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => false]),
            static fn (): string => '1700000000'
        );
        $payload = [
            'customerNum' => 'C100',
            'orderAmount' => '1.00',
            'subject' => '中文/测试',
        ];
        $expectedBody = '{"customerNum":"C100","orderAmount":"1.00","subject":"中文/测试"}';
        $expectedToken = '416E5D1688119C6AF52B836FA579F48A324A67E3';

        $result = $client->post('/api/createPayWithCheck', $payload);
        $this->assertSame('P-RAW', (string) $result['requestNum'], '哆啦宝 SDK 应返回已验证的业务响应');
        $this->assertSame($expectedToken, $client->createToken('1700000000', '/api/createPayWithCheck', $expectedBody), '哆啦宝 token 固定向量不一致');
        $this->assertSame(1, count($history), '哆啦宝 SDK 应只发送一次 HTTP 请求');
        $request = $history[0]['request'];
        $this->assertSame($expectedBody, (string) $request->getBody(), '哆啦宝必须发送参与签名的同一份原始 JSON');
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'), '哆啦宝 Content-Type 必须为 application/json');
        $this->assertSame('1700000000', $request->getHeaderLine('timestamp'), '哆啦宝时间戳必须同时用于请求头和签名');
        $this->assertSame($expectedToken, $request->getHeaderLine('token'), '哆啦宝实际请求 token 必须匹配原始 body 固定向量');

        $notifyBody = '{"status":"SUCCESS"}';
        $notifyToken = $client->createToken('1700000001', '', $notifyBody);
        $this->assertTrue($client->verifyNotify($notifyBody, '1700000001', $notifyToken), '哆啦宝原始通知应通过验签');
        $this->assertFalse($client->verifyNotify($notifyBody, '1700000001', strtolower($notifyToken)), '哆啦宝通知 token 必须严格使用协议规定的大写 SHA1');
    }

    /**
     * 哆啦宝三产品选择、身份作用域和承接参数。
     */
    private function testDuolabaoProductsIdentityAndPresentation(): void
    {
        $client = new DuolabaoUnitClient(static function (string $path, array $payload): array {
            if ($path === '/api/generateQRCodeUrl') {
                return [
                    'success' => true,
                    'requestNum' => $payload['requestNum'],
                    'orderNum' => 'DLB-QR-ORDER',
                    'bankRequestNum' => 'DLB-QR-FLOW',
                    'url' => 'https://cashier.duolabao.unit/qr',
                ];
            }
            $bankRequest = match ($payload['bankType']) {
                'ALIPAY' => ['TRADENO' => 'ALI-TRADE-1'],
                'WX_XCX' => [
                    'APPID' => 'wx-mini-child', 'TIMESTAMP' => '1700000002',
                    'NONCESTR' => 'mini-nonce', 'PACKAGE' => 'prepay_id=mini',
                    'SIGNTYPE' => 'RSA', 'PAYSIGN' => 'mini-sign',
                ],
                default => [
                    'APPID' => 'wx-mp-child', 'TIMESTAMP' => '1700000001',
                    'NONCESTR' => 'mp-nonce', 'PACKAGE' => 'prepay_id=mp',
                    'SIBGTYPE' => 'MD5', 'PAYSIGN' => 'mp-sign',
                ],
            };

            return [
                'success' => true,
                'requestNum' => $payload['requestNum'],
                'orderNum' => 'DLB-JS-ORDER-' . count($bankRequest),
                'bankRequestNum' => 'DLB-JS-FLOW-' . count($bankRequest),
                'bankRequest' => $bankRequest,
            ];
        });
        $plugin = $this->duolabaoPlugin($client);
        $this->assertTrue($plugin instanceof PaymentIdentityRequirementInterface, '哆啦宝 JSAPI 必须实现统一身份需求接口');

        $qr = $plugin->pay($this->duolabaoOrder('alipay', 'pc', ['method' => 'qrcode']));
        $this->assertSame('QRCODE_TRAD', (string) $qr['pay_product'], '哆啦宝二维码只能映射聚合 QRCODE_TRAD');
        $this->assertSame('https://cashier.duolabao.unit/qr', (string) $qr['presentation']['pay_params']['qrcode'], '哆啦宝二维码承接地址映射错误');
        $this->assertSame('DLB-QR-ORDER', (string) $qr['chan_order_no'], '哆啦宝 chan_order_no 只能来自 orderNum');
        $this->assertSame('DLB-QR-FLOW', (string) $qr['chan_trade_no'], '哆啦宝 chan_trade_no 只能来自 bankRequestNum');
        $this->assertSame('QRCODE_TRAD', (string) $client->calls[0]['data']['businessType'], '聚合二维码业务产品字段错误');

        $aliMissing = $this->duolabaoOrder('alipay', 'alipay', ['sub_openid' => 'WRONG-PLATFORM-ID']);
        $this->assertSame('buyer_id', (string) ($plugin->identityRequirement($aliMissing)['identity_field'] ?? ''), '支付宝不得用 sub_openid 替代 buyer_id');
        $ali = $plugin->pay($this->duolabaoOrder('alipay', 'alipay', [
            'buyer_id' => 'ALI-BUYER-1',
            'mini_openid' => 'WRONG-MINI-ID',
        ]));
        $aliCall = $client->calls[1];
        $this->assertSame('ALIPAY', (string) $aliCall['data']['bankType'], '支付宝 JSAPI bankType 错误');
        $this->assertSame('ALI-BUYER-1', (string) $aliCall['data']['authCode'], '支付宝 authCode 必须精确取 buyer_id');
        $this->assertSame('ALI-TRADE-1', (string) $ali['presentation']['pay_params']['tradeNO'], '支付宝 JSAPI 必须映射 TRADENO');

        $mpMissing = $this->duolabaoOrder('wxpay', 'wechat', ['openid' => 'WRONG-DIRECT-OPENID']);
        $this->assertSame('sub_openid', (string) ($plugin->identityRequirement($mpMissing)['identity_field'] ?? ''), '子商户公众号必须精确要求 sub_openid');
        $mp = $plugin->pay($this->duolabaoOrder('wxpay', 'wechat', [
            'sub_openid' => 'WX-SUB-OPENID',
            'buyer_id' => 'WRONG-ALI-ID',
            'sub_appid' => 'wx-mp-child',
        ]));
        $mpCall = $client->calls[2];
        $this->assertSame('WX-SUB-OPENID', (string) $mpCall['data']['authCode'], '微信公众号 authCode 必须精确取 sub_openid');
        $this->assertSame('wx-platform', (string) $mpCall['data']['appId'], '微信主商户 appId 作用域错误');
        $this->assertSame('wx-mp-child', (string) $mpCall['data']['subAppId'], '微信公众号 subAppId 作用域错误');
        $this->assertTrue($mpCall['data']['appId'] !== $mpCall['data']['subAppId'], 'appId 与 subAppId 不得无条件写成相同值');
        $this->assertSame('MD5', (string) $mp['presentation']['pay_params']['signType'], '微信公众号必须精确映射旧合同 SIBGTYPE');

        $miniMissing = $this->duolabaoOrder('wxpay', 'mobile', [
            'method' => 'mini',
            'sub_openid' => 'WRONG-MP-ID',
        ]);
        $this->assertSame('mini_openid', (string) ($plugin->identityRequirement($miniMissing)['identity_field'] ?? ''), '微信小程序不得用 sub_openid 替代 mini_openid');
        $mini = $plugin->pay($this->duolabaoOrder('wxpay', 'mobile', [
            'method' => 'mini',
            'mini_openid' => 'WX-MINI-OPENID',
            'sub_openid' => 'WRONG-MP-ID',
            'sub_appid' => 'wx-mini-child',
        ]));
        $miniCall = $client->calls[3];
        $this->assertSame('WX_XCX', (string) $miniCall['data']['bankType'], '微信小程序必须使用 WX_XCX');
        $this->assertSame('WX-MINI-OPENID', (string) $miniCall['data']['authCode'], '微信小程序 authCode 必须精确取 mini_openid');
        $this->assertSame('wx-mini-child', (string) $miniCall['data']['subAppId'], '微信小程序 subAppId 作用域错误');
        $this->assertSame('wechatMini', (string) $mini['presentation']['pay_params']['_page'], '微信小程序承接页错误');
        $this->assertSame('RSA', (string) $mini['presentation']['pay_params']['request_payment']['signType'], '微信小程序必须精确映射 SIGNTYPE');

        $scanOnly = $this->duolabaoPlugin($client, ['enabled_products' => ['QRCODE_TRAD']]);
        $this->assertThrowsClass(
            fn () => $scanOnly->pay($this->duolabaoOrder('alipay', 'alipay', ['method' => 'jsapi', 'buyer_id' => 'ALI-BUYER'])),
            PaymentDefinitiveException::class,
            '禁用支付宝 JSAPI 后不得把聚合二维码伪装成同产品兜底'
        );
        $jsapiOnly = $this->duolabaoPlugin($client, ['enabled_products' => ['ALIPAY_JSAPI', 'WX_JSAPI']]);
        $this->assertThrowsClass(
            fn () => $jsapiOnly->pay($this->duolabaoOrder('wxpay', 'pc', ['method' => 'qrcode'])),
            PaymentDefinitiveException::class,
            '禁用 QRCODE_TRAD 后不得生成聚合二维码'
        );
        $aliAndQrOnly = $this->duolabaoPlugin($client, ['enabled_products' => ['ALIPAY_JSAPI', 'QRCODE_TRAD']]);
        $this->assertThrowsClass(
            fn () => $aliAndQrOnly->pay($this->duolabaoOrder('wxpay', 'wechat', [
                'method' => 'jsapi',
                'sub_openid' => 'WX-SUB-OPENID',
            ])),
            PaymentDefinitiveException::class,
            '禁用 WX_JSAPI 后不得借用聚合二维码或支付宝产品'
        );

        $directClient = new DuolabaoUnitClient(static fn (string $path, array $payload): array => [
            'success' => true,
            'requestNum' => $payload['requestNum'],
            'orderNum' => 'DLB-DIRECT-ORDER',
            'bankRequestNum' => 'DLB-DIRECT-FLOW',
            'bankRequest' => [
                'APPID' => 'wx-mp-direct', 'TIMESTAMP' => '1700000003',
                'NONCESTR' => 'direct-nonce', 'PACKAGE' => 'prepay_id=direct',
                'SIBGTYPE' => 'MD5', 'PAYSIGN' => 'direct-sign',
            ],
        ]);
        $directPlugin = $this->duolabaoPlugin($directClient, [
            'wx_platform_app_id' => '',
            'wx_platform_app_secret' => '',
            'wx_mp_app_id' => 'wx-mp-direct',
            'wx_mp_app_secret' => 'wx-mp-direct-secret',
        ]);
        $this->assertSame('openid', (string) ($directPlugin->identityRequirement(
            $this->duolabaoOrder('wxpay', 'wechat')
        )['identity_field'] ?? ''), '直连公众号必须要求 openid，而不是子商户 sub_openid');
        $directPlugin->pay($this->duolabaoOrder('wxpay', 'wechat', [
            'openid' => 'WX-DIRECT-OPENID',
            'sub_openid' => 'WRONG-SUB-OPENID',
            'sub_appid' => 'wx-mp-direct',
        ]));
        $this->assertSame('wx-mp-direct', (string) $directClient->calls[0]['data']['appId'], '直连公众号 AppID 应写入 appId');
        $this->assertFalse(array_key_exists('subAppId', $directClient->calls[0]['data']), '直连公众号不得虚构 subAppId');
        $this->assertSame('WX-DIRECT-OPENID', (string) $directClient->calls[0]['data']['authCode'], '直连公众号必须精确取 openid');

        $rejectingClient = new DuolabaoUnitClient(static fn (): DuolabaoSdkException => new DuolabaoSdkException('明确拒绝', false, 'PRODUCT_NOT_OPEN'));
        $rejectingPlugin = $this->duolabaoPlugin($rejectingClient);
        $this->assertThrowsClass(
            fn () => $rejectingPlugin->pay($this->duolabaoOrder('alipay', 'alipay', ['buyer_id' => 'ALI-BUYER'])),
            PaymentDefinitiveException::class,
            '哆啦宝 JSAPI 明确失败不得跨产品重试'
        );
        $this->assertSame(1, count($rejectingClient->calls), '哆啦宝结果失败后不得再次请求聚合二维码');
    }

    /**
     * 哆啦宝通知验签、商户/订单/金额核验和状态归一化。
     */
    private function testDuolabaoNotifyBusinessValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-DLB-NOTIFY',
            'pay_amount' => 123,
            'channel_id' => 77,
            'channel_order_no' => 'DLB-ORDER-1',
            'channel_trade_no' => 'DLB-FLOW-1',
        ]);
        $repository = new class ($payOrder) extends PayOrderRepository {
            public function __construct(private readonly PayOrder $unitOrder)
            {
            }

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->unitOrder->pay_no ? $this->unitOrder : null;
            }
        };
        $client = new DuolabaoUnitClient(static fn (): array => []);
        $plugin = $this->duolabaoPlugin($client, [], $repository);
        $successPayload = [
            'customerNum' => 'DLB-CUSTOMER',
            'shopNum' => 'DLB-SHOP',
            'requestNum' => 'P-DLB-NOTIFY',
            'orderNum' => 'DLB-ORDER-1',
            'bankRequestNum' => 'DLB-FLOW-1',
            'bankOutTradeNum' => 'BANK-OUT-MUST-NOT-MAP',
            'orderAmount' => '1.23',
            'status' => 'SUCCESS',
        ];
        $request = $this->duolabaoNotifyRequest($successPayload, $client);
        $success = $plugin->notify($request);
        $duplicate = $plugin->notify($request);
        $this->assertSame($success, $duplicate, '哆啦宝重复通知必须稳定归一化为同一结果');
        $this->assertSame('success', (string) $success['status'], '哆啦宝 SUCCESS 状态映射错误');
        $this->assertSame(123, (int) $success['paid_amount'], '哆啦宝通知金额必须返回整数分');
        $this->assertSame('DLB-ORDER-1', (string) $success['chan_order_no'], '哆啦宝通知 chan_order_no 必须来自 orderNum');
        $this->assertSame('DLB-FLOW-1', (string) $success['chan_trade_no'], '哆啦宝通知 chan_trade_no 必须来自 bankRequestNum');

        $this->assertThrowsClass(
            fn () => $plugin->notify($this->duolabaoNotifyRequest($successPayload, $client, '1700000100', '0000000000000000000000000000000000000000')),
            PaymentException::class,
            '哆啦宝错签通知必须拒绝'
        );
        $wrongMerchant = $successPayload;
        $wrongMerchant['customerNum'] = 'OTHER-CUSTOMER';
        $this->assertThrowsClass(
            fn () => $plugin->notify($this->duolabaoNotifyRequest($wrongMerchant, $client)),
            PaymentException::class,
            '哆啦宝错商户通知必须拒绝'
        );
        $wrongRequest = $successPayload;
        $wrongRequest['requestNum'] = 'P-OTHER';
        $this->assertThrowsClass(
            fn () => $plugin->notify($this->duolabaoNotifyRequest($wrongRequest, $client)),
            PaymentException::class,
            '哆啦宝错本地订单通知必须拒绝'
        );
        $wrongOrder = $successPayload;
        $wrongOrder['orderNum'] = 'DLB-OTHER-ORDER';
        $this->assertThrowsClass(
            fn () => $plugin->notify($this->duolabaoNotifyRequest($wrongOrder, $client)),
            PaymentException::class,
            '哆啦宝错渠道订单通知必须拒绝'
        );
        $wrongAmount = $successPayload;
        $wrongAmount['orderAmount'] = '1.24';
        $this->assertThrowsClass(
            fn () => $plugin->notify($this->duolabaoNotifyRequest($wrongAmount, $client)),
            PaymentException::class,
            '哆啦宝错金额通知必须拒绝'
        );

        $pendingPayload = $successPayload;
        $pendingPayload['status'] = 'PROCESSING';
        $pending = $plugin->notify($this->duolabaoNotifyRequest($pendingPayload, $client));
        $this->assertSame('pending', (string) $pending['status'], '哆啦宝处理中状态不得按 HTTP 成功误判支付成功');
        $this->assertSame(null, $pending['paid_amount'], '哆啦宝非成功通知不得返回实付金额');
        $failedPayload = $successPayload;
        $failedPayload['status'] = 'FAILED';
        $this->assertSame('failed', (string) $plugin->notify($this->duolabaoNotifyRequest($failedPayload, $client))['status'], '哆啦宝失败状态映射错误');
        $unknownPayload = $successPayload;
        $unknownPayload['status'] = 'MERCHANT_REVIEW';
        $unknown = $plugin->notify($this->duolabaoNotifyRequest($unknownPayload, $client));
        $this->assertSame('pending', (string) $unknown['status'], '通知验证器不支持 unknown 时应保守保持 pending');
        $this->assertSame('DUOLABAO_STATUS_UNKNOWN', (string) $unknown['channel_error_code'], '哆啦宝未知状态必须保留显式诊断码');
    }

    /**
     * 哆啦宝退款只确认受理，明确拒绝与超时分别处理；查单关单保持不支持。
     */
    private function testDuolabaoRefundAndUnsupportedOperations(): void
    {
        $client = new DuolabaoUnitClient(static function (string $path, array $payload): array {
            return [
                'success' => true,
                'orderNum' => 'DLB-ORIGINAL-ORDER',
                'refundRequestNum' => $payload['refundRequestNum'],
                'refundAmount' => '0.50',
                'bankRequestNum' => 'DLB-REFUND-FLOW',
            ];
        });
        $plugin = $this->duolabaoPlugin($client);
        $order = [
            'pay_no' => 'P-DLB-REFUND',
            'refund_no' => 'R-DLB-1',
            'refund_amount' => 50,
            'chan_order_no' => 'DLB-ORIGINAL-ORDER',
            'chan_trade_no' => 'DLB-PAY-FLOW',
        ];
        $result = $plugin->refund($order);
        $this->assertSame('pending', (string) $result['status'], '哆啦宝退款 API 成功只表示受理，不得标资金成功');
        $this->assertSame('DLB-REFUND-FLOW', (string) $result['chan_refund_no'], '哆啦宝 chan_refund_no 只能来自 bankRequestNum');
        $call = $client->calls[0];
        $this->assertSame('/api/refundByRequestNum', (string) $call['path'], '哆啦宝退款路径错误');
        $this->assertSame('V4.0', (string) $call['data']['requestVersion'], '哆啦宝退款必须使用 requestVersion');
        $this->assertFalse(array_key_exists('version', $call['data']), '哆啦宝退款不得把 requestVersion 猜成 version');
        $this->assertSame('P-DLB-REFUND', (string) $call['data']['requestNum'], '哆啦宝退款原支付 requestNum 错误');
        $this->assertSame('R-DLB-1', (string) $call['data']['refundRequestNum'], '哆啦宝退款幂等号必须使用 refund_no');
        $this->assertSame('0.50', (string) $call['data']['refundPartAmount'], '哆啦宝退款金额格式错误');

        $definitiveClient = new DuolabaoUnitClient(static fn (): DuolabaoSdkException => new DuolabaoSdkException('退款明确拒绝', false, 'REFUND_REJECTED'));
        $this->assertThrowsClass(
            fn () => $this->duolabaoPlugin($definitiveClient)->refund($order),
            PaymentDefinitiveException::class,
            '哆啦宝退款明确拒绝必须作为确定失败'
        );
        $timeoutClient = new DuolabaoUnitClient(static fn (): DuolabaoSdkException => new DuolabaoSdkException('网关超时', true));
        $this->assertThrowsClass(
            fn () => $this->duolabaoPlugin($timeoutClient)->refund($order),
            PaymentUncertainException::class,
            '哆啦宝退款超时必须作为结果不确定'
        );
        $this->assertThrowsClass(fn () => $plugin->query($order), UnsupportedPaymentOperationException::class, 'rainbow_legacy 无完整证据时不得实现主动查单');
        $this->assertThrowsClass(fn () => $plugin->close($order), UnsupportedPaymentOperationException::class, 'rainbow_legacy 无完整证据时不得实现关单');
        $this->assertFalse($plugin instanceof RefundNotifyInterface, '没有独立退款通知证据时不得实现 RefundNotifyInterface');
    }

    /**
     * ePay V1 MD5 签名规则。
     *
     * @return void
     */
    private function testMd5Signer(): void
    {
        $signer = new Md5Signer();
        $params = [
            'b' => '2',
            'a' => '1',
            'empty' => '',
            'sign_type' => 'MD5',
            'sign' => 'old',
        ];

        $signature = $signer->sign($params, 'secret');
        $this->assertSame(md5('a=1&b=2secret'), $signature, 'MD5 签名原文排序或字段排除不符合预期');
        $this->assertTrue($signer->verify($params, strtoupper($signature), 'secret'), 'MD5 验签应忽略大小写');

        $legacyParams = [
            'pid' => '1001',
            'type' => 'wxpay',
            'money' => '1.00',
            'clientip' => '127.0.0.1',
            'sitename' => '签名兼容测试',
            'sign_type' => 'MD5',
        ];
        $legacyContent = 'clientip=127.0.0.1&money=1.00&pid=1001&sitename=签名兼容测试&type=wxpay';
        $this->assertSame(
            md5($legacyContent . 'test-secret'),
            $signer->sign($legacyParams, 'test-secret'),
            'V1 文档外的非空标量参数也必须参与签名'
        );
    }

    /**
     * ePay V1/V2 必须从标准 presentation 读取支付承接类型。
     */
    private function testEpayPaymentPresentationContract(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-EPAY-PRESENTATION',
            'device' => 'pc',
        ]);
        $paymentResult = [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => 'P-EPAY-PRESENTATION',
            'pay_type' => 'alipay',
            'pay_product' => 'qrcode',
            'pay_action' => 'qrcode',
            'presentation' => [
                'pay_page' => 'qrcode',
                'pay_params' => ['qrcode' => 'https://pay.example/epay-qr'],
            ],
        ];
        $attempt = [
            'pay_order' => $payOrder,
            'payment_result' => $paymentResult,
            'pay_params' => (array) $paymentResult['presentation']['pay_params'],
            'payment_page_url' => 'https://cashier.example/payment/P-EPAY-PRESENTATION',
        ];

        $v1 = (new ReflectionClass(\app\service\payment\epay\EpayV1ProtocolService::class))
            ->newInstanceWithoutConstructor();
        $v1Response = (new ReflectionClass($v1))->getMethod('buildMapiResponse')->invoke($v1, $attempt);
        $this->assertSame('https://pay.example/epay-qr', (string) ($v1Response['qrcode'] ?? ''), 'ePay V1 未读取标准 presentation.pay_page');

        $v2 = (new ReflectionClass(\app\service\payment\epay\EpayV2ProtocolService::class))
            ->newInstanceWithoutConstructor();
        $v2Response = (new ReflectionClass($v2))->getMethod('buildCreateResponse')->invoke(
            $v2,
            $payOrder,
            $paymentResult,
            (array) $paymentResult['presentation']['pay_params']
        );
        $this->assertSame('qrcode', (string) ($v2Response['pay_type'] ?? ''), 'ePay V2 支付动作映射错误');
        $this->assertSame('https://pay.example/epay-qr', (string) ($v2Response['pay_info'] ?? ''), 'ePay V2 未读取标准 presentation.pay_page');

        $buildCreatePayInfo = (new ReflectionClass($v2))->getMethod('buildCreatePayInfo');
        $publicPageInfo = (array) $buildCreatePayInfo->invoke($v2, 'page', [
            '_page' => 'wechatMini',
            'params' => [
                'request_payment' => [
                    'timeStamp' => '1784262645',
                    'raw' => ['upstream_response' => 'private'],
                ],
                'raw' => ['upstream_response' => 'private'],
            ],
        ]);
        $this->assertFalse(array_key_exists('raw', $publicPageInfo), 'ePay V2 公开支付参数不得包含顶层原始诊断数据');
        $this->assertFalse(
            array_key_exists('raw', (array) $publicPageInfo['request_payment']),
            'ePay V2 公开支付参数不得包含嵌套原始诊断数据'
        );
    }

    /**
     * ePay V2 RSA 签名规则。
     *
     * @return void
     */
    private function testRsaSigner(): void
    {
        $pair = RsaKeyPairGenerator::generate(1024);
        $signer = new RsaSigner();
        $params = [
            'pid' => '10001',
            'money' => '10.00',
            'out_trade_no' => 'T202605170001',
            'sign_type' => 'RSA',
        ];

        $signature = $signer->sign($params, $pair['private_key']);
        $this->assertTrue($signer->verify($params, $signature, $pair['public_key']), 'RSA 签名后应能用同组公钥验签');
        $tampered = $params;
        $tampered['money'] = '10.01';
        $this->assertFalse($signer->verify($tampered, $signature, $pair['public_key']), 'RSA 篡改参数后不应验签通过');
    }

    /**
     * 支付宝 RSA2、参数排序和 URL 编码规则。
     *
     * @return void
     */
    private function testAlipayRsa2AndEncoding(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $params = [
            'z_empty' => '',
            'b_text' => '中文 空格+&=%',
            'a_no' => 'A-001',
            'sign_type' => 'RSA2',
            'sign' => 'ignored',
        ];

        $this->assertSame(
            'a_no=A-001&b_text=中文 空格+&=%&sign_type=RSA2',
            AlipaySigner::requestContent($params),
            '支付宝请求签名应排序、排除 sign 和空值且保留未编码原值'
        );
        $this->assertSame(
            'a_no=A-001&b_text=中文 空格+&=%&z_empty=',
            AlipaySigner::notifyContent($params),
            '支付宝通知验签应排除 sign/sign_type 并保留通知空值'
        );

        $content = AlipaySigner::requestContent($params);
        $signature = AlipaySigner::sign($content, $pair['private_key']);
        $this->assertSame(
            $signature,
            AlipaySigner::sign($content, $pair['private_key']),
            '同一 RSA2 密钥和固定原文应产生固定 PKCS#1 v1.5 签名'
        );
        $this->assertTrue(AlipaySigner::verify($content, $signature, $pair['public_key']), 'RSA2 固定原文应验签通过');
        $this->assertFalse(AlipaySigner::verify($content . '&amount=0.01', $signature, $pair['public_key']), 'RSA2 原文篡改必须验签失败');

        $transport = $params;
        $transport['sign'] = $signature;
        parse_str(http_build_query($transport, '', '&', PHP_QUERY_RFC3986), $decoded);
        $this->assertSame($params['b_text'], (string) $decoded['b_text'], '表单参数只能经过一次 URL 编解码');
        $this->assertSame(
            AlipaySigner::notifyContent($transport),
            AlipaySigner::notifyContent($decoded),
            'URL 编码传输不应改变通知验签原文'
        );
        $this->assertThrows(
            fn () => AlipaySigner::notifyContent(['nested' => ['invalid']]),
            '嵌套通知参数不得重新 JSON 编码后参与验签'
        );
    }

    /**
     * 支付宝元/分精确转换。
     *
     * @return void
     */
    private function testAlipayAmountConversion(): void
    {
        $plugin = (new ReflectionClass(AlipayApiPayment::class))->newInstanceWithoutConstructor();
        $amountToCents = $this->privateMethod(AlipayApiPayment::class, 'amountToCents');

        $this->assertSame('0.01', \app\common\util\FormatHelper::amount(1), '1 分应精确格式化为 0.01 元');
        $this->assertSame('1.00', \app\common\util\FormatHelper::amount(100), '100 分应精确格式化为 1.00 元');
        $this->assertSame('-0.01', \app\common\util\FormatHelper::amount(-1), '负数格式化不应丢失符号');
        $this->assertSame(100, $amountToCents->invoke($plugin, '1'), '整数元应转成整数分');
        $this->assertSame(100, $amountToCents->invoke($plugin, '1.0'), '一位小数应精确补零');
        $this->assertSame(100, $amountToCents->invoke($plugin, '1.00'), '两位小数应精确转换');
        $this->assertSame(1, $amountToCents->invoke($plugin, '0.01'), '最小分单位应精确转换');
        foreach (['-1', '1.001', '1e2', '01.00', 'abc', ''] as $invalid) {
            $this->assertThrows(
                fn () => $amountToCents->invoke($plugin, $invalid),
                '非法支付宝金额必须拒绝：' . $invalid
            );
        }
    }

    /**
     * 支付宝网关响应必须对原始 JSON 响应节点验签。
     *
     * @return void
     */
    private function testAlipayGatewayResponseSignature(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $client = new AlipayClient($this->alipayKeyConfig($pair));
        $node = '{"code":"10000","msg":"Success","out_trade_no":"P202607150001","total_amount":"1.00","trade_status":"TRADE_SUCCESS","trade_no":"20260715001","note":"中文\\/值"}';
        $sign = AlipaySigner::sign($node, $pair['private_key']);
        $raw = '{"alipay_trade_query_response":' . $node . ',"sign":' . json_encode($sign) . '}';
        $decoded = json_decode($raw, true);
        $verify = $this->privateMethod(AlipayClient::class, 'verifyGatewayResponse');

        $this->assertTrue(
            $verify->invoke($client, AlipayClient::METHOD_TRADE_QUERY, $raw, $decoded),
            '原始支付宝响应节点和签名匹配时应验签通过'
        );
        $tampered = str_replace('"total_amount":"1.00"', '"total_amount":"1.01"', $raw);
        $this->assertThrows(
            fn () => $verify->invoke($client, AlipayClient::METHOD_TRADE_QUERY, $tampered, json_decode($tampered, true)),
            '支付宝响应金额被篡改后必须验签失败'
        );
        $unsigned = '{"alipay_trade_query_response":' . $node . '}';
        $this->assertThrows(
            fn () => $verify->invoke($client, AlipayClient::METHOD_TRADE_QUERY, $unsigned, json_decode($unsigned, true)),
            '支付宝成功响应缺少 sign 时必须失败'
        );
    }

    /**
     * 支付宝通知验签后的订单级业务校验。
     *
     * @return void
     */
    private function testAlipayNotifyBusinessValidation(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $config = $this->alipayKeyConfig($pair, [
            'seller_id' => '2088000000000001',
            'channel_id' => 12,
            'enabled_products' => ['scan'],
        ]);
        $client = new AlipayClient($config);
        $plugin = new AlipayApiPayment(new PayOrderRepository());
        $plugin->init($config);
        $order = new PayOrder();
        $order->forceFill([
            'pay_no' => 'P202607150001',
            'channel_id' => 12,
            'pay_amount' => 100,
            'channel_trade_no' => '',
            'ext_json' => [
                'payment_context' => ['pay_product' => 'scan'],
            ],
        ]);

        $payload = [
            'notify_time' => '2026-07-15 12:00:00',
            'notify_type' => 'trade_status_sync',
            'notify_id' => 'notify-test-001',
            'app_id' => '2026000000000001',
            'seller_id' => '2088000000000001',
            'out_trade_no' => 'P202607150001',
            'trade_no' => '2026071522000000000001',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '1.00',
            'gmt_payment' => '2026-07-15 12:00:00',
            'passback_params' => rawurlencode((string) json_encode(['mpay_pay_product' => 'scan'])),
            'sign_type' => 'RSA2',
        ];
        $payload = $this->signAlipayNotify($payload, $pair['private_key']);
        $parsed = $client->parseNotify($payload, true);
        $validate = $this->privateMethod(AlipayApiPayment::class, 'validatedNotifyResult');
        $result = $validate->invoke($plugin, $parsed, $order);
        $this->assertSame('P202607150001', (string) $result['pay_no'], '支付宝通知应返回 pay_no 供核心校验回调 URL');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '仅成功交易状态应归一化为成功');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        $tamperedStatus = $payload;
        $tamperedStatus['trade_status'] = 'WAIT_BUYER_PAY';
        $this->assertThrows(fn () => $client->parseNotify($tamperedStatus, true), '篡改通知 trade_status 后原签名必须失败');
        $waiting = $this->signAlipayNotify($tamperedStatus, $pair['private_key']);
        $waitingResult = $validate->invoke($plugin, $client->parseNotify($waiting, true), $order);
        $this->assertSame(
            PaymentPluginStatusConstant::PENDING,
            (string) $waitingResult['status'],
            '合法签名的 WAIT_BUYER_PAY 只能保持 pending，不能认定成功'
        );
        $unknownStatus = $waiting;
        $unknownStatus['trade_status'] = 'UNKNOWN_STATUS';
        $unknownStatus = $this->signAlipayNotify($unknownStatus, $pair['private_key']);
        $this->assertThrows(
            fn () => $validate->invoke($plugin, $client->parseNotify($unknownStatus, true), $order),
            '合法签名但未知的 trade_status 必须拒绝'
        );

        foreach ([
            ['total_amount', '1.01', '金额'],
            ['out_trade_no', 'P202607150002', '订单号'],
            ['seller_id', '2088000000000002', 'seller_id'],
        ] as [$field, $value, $label]) {
            $changed = $payload;
            $changed[$field] = $value;
            $changed = $this->signAlipayNotify($changed, $pair['private_key']);
            $changedParsed = $client->parseNotify($changed, true);
            $this->assertThrows(
                fn () => $validate->invoke($plugin, $changedParsed, $order),
                '即使重新合法签名，错误支付宝通知' . $label . '也必须被业务校验拒绝'
            );
        }

        $wrongApp = $payload;
        $wrongApp['app_id'] = '2026000000000002';
        $wrongApp = $this->signAlipayNotify($wrongApp, $pair['private_key']);
        $this->assertThrows(fn () => $client->parseNotify($wrongApp, true), '重新签名的错误 app_id 仍必须拒绝');

        $missingTradeNo = $payload;
        $missingTradeNo['trade_no'] = '';
        $missingTradeNo = $this->signAlipayNotify($missingTradeNo, $pair['private_key']);
        $this->assertThrows(
            fn () => $validate->invoke($plugin, $client->parseNotify($missingTradeNo, true), $order),
            '成功通知缺少 trade_no 必须拒绝'
        );

        $wrongProduct = $payload;
        $wrongProduct['passback_params'] = rawurlencode((string) json_encode(['mpay_pay_product' => 'web']));
        $wrongProduct = $this->signAlipayNotify($wrongProduct, $pair['private_key']);
        $this->assertThrows(
            fn () => $validate->invoke($plugin, $client->parseNotify($wrongProduct, true), $order),
            '通知产品标记与持久化 presentation 不一致必须拒绝'
        );

        $wrongChannelOrder = clone $order;
        $wrongChannelOrder->channel_id = 13;
        $this->assertThrows(
            fn () => $validate->invoke($plugin, $parsed, $wrongChannelOrder),
            '支付宝通知支付单不属于当前通道必须拒绝'
        );
        $wrongTradeOrder = clone $order;
        $wrongTradeOrder->channel_trade_no = '2026071522000000000099';
        $this->assertThrows(
            fn () => $validate->invoke($plugin, $parsed, $wrongTradeOrder),
            '重复通知的支付宝 trade_no 与已保存交易号不一致必须拒绝'
        );

        $repeat = $validate->invoke($plugin, $parsed, $order);
        $this->assertSame($result, $repeat, '重复支付宝通知应产生稳定结果并交由通用生命周期幂等处理');
    }

    /**
     * 支付宝产品选择、APP/网页参数和 buyer 身份流程。
     *
     * @return void
     */
    private function testAlipayProductAndIdentityResolution(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $config = $this->alipayKeyConfig($pair, [
            'seller_id' => '2088000000000001',
            'channel_id' => 12,
            'mini_app_id' => '2026000000000009',
            'enabled_products' => ['web', 'h5', 'app', 'mini', 'pos', 'scan'],
        ]);
        $plugin = new AlipayApiPayment(new PayOrderRepository());
        $plugin->init($config);
        $this->assertSame(AlipayConfig::GATEWAY_SANDBOX, (new AlipayConfig($config))->gateway(), '沙箱环境必须使用支付宝新版沙箱网关');
        $this->assertSame(
            AlipayConfig::GATEWAY_PRODUCTION,
            (new AlipayConfig(array_replace($config, ['sandbox' => false])))->gateway(),
            '生产环境必须使用支付宝正式网关'
        );
        $resolve = $this->privateMethod(AlipayApiPayment::class, 'resolveProduct');
        $schema = [];
        foreach ($plugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $this->assertSame('textarea', (string) ($schema['private_key']['type'] ?? ''), 'PEM 应用私钥必须使用 textarea');
        $this->assertSame('password', (string) ($schema['app_auth_token']['type'] ?? ''), '应用授权 Token 必须使用 password');
        $this->assertSame('upload', (string) ($schema['app_cert_path']['type'] ?? ''), '应用证书必须使用私有文件上传');
        $this->assertSame('object_key', (string) ($schema['app_cert_path']['props']['fileUpload']['getKey'] ?? ''), '证书上传必须回填 object_key');
        $this->assertTrue((bool) ($schema['seller_id']['validate'][0]['required'] ?? false), 'seller_id 必须在配置表单中强制填写');

        $this->assertSame('app', $resolve->invoke($plugin, $this->alipayOrder('app')), '显式 app 方法必须选择 APP 支付');
        $this->assertSame('mini', $resolve->invoke($plugin, $this->alipayOrder('jsapi')), '显式 jsapi 方法必须选择小程序支付');
        $this->assertSame('mini', $resolve->invoke($plugin, $this->alipayOrder('applet')), '显式 applet 方法必须选择小程序支付');
        $this->assertSame('web', $resolve->invoke($plugin, $this->alipayOrder('jump', 'pc')), 'PC jump 应选择电脑网站支付');
        $this->assertSame('h5', $resolve->invoke($plugin, $this->alipayOrder('jump', 'mobile')), '移动 jump 应选择手机网站支付');
        $posOrder = $this->alipayOrder('', 'pc');
        $posOrder['extra']['payment']['auth_code'] = '281234567890123456';
        $this->assertSame('pos', $resolve->invoke($plugin, $posOrder), 'auth_code 只能选择付款码支付');

        $miniOrder = $this->alipayOrder('mini', 'alipay');
        $requirement = $plugin->identityRequirement($miniOrder);
        $this->assertSame('buyer_id', (string) ($requirement['identity_field'] ?? ''), '小程序缺少 buyer 身份时应进入身份流程');
        $miniOrder['extra']['payment']['buyer_open_id'] = 'buyer-open-test';
        $this->assertSame(null, $plugin->identityRequirement($miniOrder), '已有 buyer_open_id 时不应重复进入身份流程');

        $baseBiz = $this->privateMethod(AlipayApiPayment::class, 'baseBizContent');
        $biz = $baseBiz->invoke($plugin, array_replace($this->alipayOrder('scan'), ['subject' => '订单/=&标题']), 'scan');
        $this->assertSame('1.00', (string) $biz['total_amount'], '支付宝业务金额必须使用精确两位小数');
        $this->assertFalse(str_contains((string) $biz['subject'], '/'), '支付宝 subject 不应包含受限字符');

        $appResult = $plugin->pay($this->alipayOrder('app'));
        $this->assertSame('app', (string) $appResult['pay_product'], 'APP 支付应可通过标准 method 到达');
        PaymentPluginPayResultValidator::make($appResult)->withScene('pay_result')->validate();
        parse_str((string) $appResult['presentation']['pay_params']['order_string'], $appParams);
        $appBiz = json_decode((string) ($appParams['biz_content'] ?? ''), true);
        $this->assertSame(AlipayClient::PRODUCT_APP, (string) ($appBiz['product_code'] ?? ''), 'APP 支付 product_code 不正确');

        $client = new AlipayClient($config);
        $page = $client->pagePay(['out_trade_no' => 'P1', 'subject' => 'test', 'total_amount' => '1.00']);
        $wap = $client->wapPay(['out_trade_no' => 'P2', 'subject' => 'test', 'total_amount' => '1.00']);
        $this->assertSame('POST', (string) $page['method'], '电脑网站支付应生成 POST 表单');
        $this->assertSame('POST', (string) $wap['method'], '手机网站支付应生成 POST 表单');
        $this->assertSame(AlipayClient::PRODUCT_PAGE, (string) json_decode((string) $page['params']['biz_content'], true)['product_code'], '电脑网站 product_code 不正确');
        $this->assertSame(AlipayClient::PRODUCT_WAP, (string) json_decode((string) $wap['params']['biz_content'], true)['product_code'], '手机网站 product_code 不正确');
        $this->assertSame('FACE_TO_FACE_PAYMENT', AlipayClient::PRODUCT_FACE_TO_FACE, '付款码 product_code 不正确');
        $this->assertSame('QR_CODE_OFFLINE', AlipayClient::PRODUCT_QR_CODE, '预创建 product_code 不正确');
        $this->assertSame('JSAPI_PAY', AlipayClient::PRODUCT_JSAPI, 'JSAPI product_code 不正确');

        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], $this->signedAlipayGatewayBody(
                AlipayClient::METHOD_TRADE_PAY,
                ['code' => '10000', 'msg' => 'Success', 'out_trade_no' => 'POS1', 'trade_no' => 'T-POS1'],
                $pair['private_key']
            )),
            new \GuzzleHttp\Psr7\Response(200, [], $this->signedAlipayGatewayBody(
                AlipayClient::METHOD_TRADE_PRECREATE,
                ['code' => '10000', 'msg' => 'Success', 'out_trade_no' => 'SCAN1', 'qr_code' => 'https://qr.example.test/1'],
                $pair['private_key']
            )),
            new \GuzzleHttp\Psr7\Response(200, [], $this->signedAlipayGatewayBody(
                AlipayClient::METHOD_TRADE_CREATE,
                ['code' => '10000', 'msg' => 'Success', 'out_trade_no' => 'MINI1', 'trade_no' => 'T-MINI1'],
                $pair['private_key']
            )),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $apiClient = new AlipayClient($config);
        $this->setObjectProperty($apiClient, 'httpClient', new \GuzzleHttp\Client(['handler' => $stack]));
        $apiClient->faceToFacePay(['out_trade_no' => 'POS1', 'subject' => 'test', 'total_amount' => '1.00', 'auth_code' => '281234567890123456']);
        $apiClient->precreate(['out_trade_no' => 'SCAN1', 'subject' => 'test', 'total_amount' => '1.00']);
        $apiClient->jsapiCreate([
            'out_trade_no' => 'MINI1',
            'subject' => 'test',
            'total_amount' => '1.00',
            'op_app_id' => '2026000000000009',
            'buyer_open_id' => 'buyer-open-test',
        ]);
        $expectedProducts = [
            [AlipayClient::PRODUCT_FACE_TO_FACE, 'bar_code'],
            [AlipayClient::PRODUCT_QR_CODE, ''],
            [AlipayClient::PRODUCT_JSAPI, ''],
        ];
        foreach ($history as $index => $transaction) {
            parse_str((string) $transaction['request']->getBody(), $requestParams);
            $requestBiz = json_decode((string) ($requestParams['biz_content'] ?? ''), true);
            [$expectedProduct, $expectedScene] = $expectedProducts[$index];
            $this->assertSame($expectedProduct, (string) ($requestBiz['product_code'] ?? ''), '支付宝服务端产品 product_code 不正确');
            if ($expectedScene !== '') {
                $this->assertSame($expectedScene, (string) ($requestBiz['scene'] ?? ''), '支付宝付款码 scene 不正确');
            }
        }
    }

    /**
     * 支付宝公钥证书模式、证书序列号和响应证书选择。
     *
     * @return void
     */
    private function testAlipayCertificateMode(): void
    {
        $fixture = $this->alipayCertificateFixture();
        try {
            $config = new AlipayConfig([
                'mode' => 'cert',
                'app_id' => '2026000000000001',
                'private_key' => $fixture['app_private_key'],
                'app_cert_path' => $fixture['app_cert_path'],
                'alipay_cert_path' => $fixture['alipay_cert_path'],
                'alipay_root_cert_path' => $fixture['root_cert_path'],
            ]);
            $this->assertTrue($config->isCertMode(), '完整证书配置应启用证书模式');
            $client = new AlipayClient($config);
            $params = $client->signedParams(AlipayClient::METHOD_TRADE_QUERY, ['out_trade_no' => 'P202607150001'], [
                'timestamp' => '2026-07-15 12:00:00',
            ]);
            $this->assertSame(
                AlipayCertificate::appCertSn($config->appCertContent()),
                (string) $params['app_cert_sn'],
                '证书模式请求 app_cert_sn 不正确'
            );
            $this->assertSame(
                AlipayCertificate::alipayRootCertSn($config->alipayRootCertContent()),
                (string) $params['alipay_root_cert_sn'],
                '证书模式请求 alipay_root_cert_sn 不正确'
            );

            $certSn = AlipayCertificate::alipayCertSn($config->alipayCertContent());
            $node = '{"code":"10000","msg":"Success","out_trade_no":"P202607150001"}';
            $sign = AlipaySigner::sign($node, $fixture['alipay_private_key']);
            $raw = '{"alipay_trade_query_response":' . $node
                . ',"alipay_cert_sn":' . json_encode($certSn)
                . ',"sign":' . json_encode($sign) . '}';
            $verify = $this->privateMethod(AlipayClient::class, 'verifyGatewayResponse');
            $this->assertTrue(
                $verify->invoke($client, AlipayClient::METHOD_TRADE_QUERY, $raw, json_decode($raw, true)),
                '证书序列号匹配时应使用配置的支付宝证书验签'
            );

            $wrongSnRaw = str_replace($certSn, str_repeat('0', 32), $raw);
            $this->assertThrows(
                fn () => $verify->invoke($client, AlipayClient::METHOD_TRADE_QUERY, $wrongSnRaw, json_decode($wrongSnRaw, true)),
                '支付宝响应 alipay_cert_sn 不匹配必须失败'
            );

            $notify = $this->signAlipayNotify([
                'app_id' => '2026000000000001',
                'seller_id' => '2088000000000001',
                'out_trade_no' => 'P202607150001',
                'trade_no' => '2026071522000000000001',
                'trade_status' => 'TRADE_SUCCESS',
                'total_amount' => '1.00',
                'alipay_cert_sn' => $certSn,
                'sign_type' => 'RSA2',
            ], $fixture['alipay_private_key']);
            $this->assertTrue($client->verifyNotify($notify), '证书模式通知应按 alipay_cert_sn 选择并验证支付宝公钥证书');
            $notify['alipay_cert_sn'] = str_repeat('0', 32);
            $notify = $this->signAlipayNotify($notify, $fixture['alipay_private_key']);
            $this->assertFalse($client->verifyNotify($notify), '证书模式通知证书序列号不匹配必须拒绝');

            $otherPair = RsaKeyPairGenerator::generate(2048);
            $this->assertThrows(
                fn () => new AlipayConfig([
                    'mode' => 'cert',
                    'app_id' => '2026000000000001',
                    'private_key' => $otherPair['private_key'],
                    'app_cert_path' => $fixture['app_cert_path'],
                    'alipay_cert_path' => $fixture['alipay_cert_path'],
                    'alipay_root_cert_path' => $fixture['root_cert_path'],
                ]),
                '应用私钥与应用公钥证书不匹配必须在初始化阶段失败'
            );
        } finally {
            $this->cleanupTestDirectory($fixture['directory']);
        }
    }

    /**
     * 支付宝查询、关闭、撤销、退款和退款查询状态映射。
     *
     * @return void
     */
    private function testAlipayQueryCloseCancelRefund(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $config = $this->alipayKeyConfig($pair, [
            'seller_id' => '2088000000000001',
            'channel_id' => 12,
            'enabled_products' => ['scan'],
        ]);
        $responses = [
            'query' => [$this->alipayResponse(AlipayClient::METHOD_TRADE_QUERY, [
                'code' => '10000',
                'msg' => 'Success',
                'out_trade_no' => 'P202607150001',
                'trade_no' => '2026071522000000000001',
                'total_amount' => '1.00',
                'trade_status' => 'TRADE_SUCCESS',
                'send_pay_date' => '2026-07-15 12:00:00',
            ])],
            'close' => [$this->alipayResponse(AlipayClient::METHOD_TRADE_CLOSE, ['code' => '10000', 'msg' => 'Success'])],
            'cancel' => [$this->alipayResponse(AlipayClient::METHOD_TRADE_CANCEL, [
                'code' => '10000',
                'msg' => 'Success',
                'action' => 'close',
                'retry_flag' => 'N',
            ])],
            'refund' => [$this->alipayResponse(AlipayClient::METHOD_TRADE_REFUND, [
                'code' => '10000',
                'msg' => 'Success',
                'fund_change' => 'N',
                'trade_no' => '2026071522000000000001',
            ])],
            'refund_query' => [$this->alipayResponse(AlipayClient::METHOD_TRADE_REFUND_QUERY, [
                'code' => '10000',
                'msg' => 'Success',
                'refund_status' => 'REFUND_SUCCESS',
            ])],
        ];
        $fake = new class($config, $responses) extends AlipayClient {
            /**
             * 创建按调用顺序返回结果的支付宝测试客户端。
             *
             * @param array<string, mixed> $config SDK 配置
             * @param array<string, array<int, AlipayResponse>> $responses 模拟响应队列
             */
            public function __construct(array $config, private array $responses)
            {
                parent::__construct($config);
            }

            public function query(array $bizContent, array $options = []): AlipayResponse
            {
                return $this->next('query');
            }

            public function close(array $bizContent, array $options = []): AlipayResponse
            {
                return $this->next('close');
            }

            public function cancel(array $bizContent, array $options = []): AlipayResponse
            {
                return $this->next('cancel');
            }

            public function refund(array $bizContent, array $options = []): AlipayResponse
            {
                return $this->next('refund');
            }

            public function refundQuery(array $bizContent, array $options = []): AlipayResponse
            {
                return $this->next('refund_query');
            }

            /**
             * 取出指定接口的下一条模拟响应。
             *
             * @param string $key 响应队列键
             * @return AlipayResponse 模拟响应
             */
            private function next(string $key): AlipayResponse
            {
                $response = array_shift($this->responses[$key]);
                if (!$response instanceof AlipayResponse) {
                    throw new \RuntimeException('缺少支付宝模拟响应：' . $key);
                }

                return $response;
            }
        };
        $plugin = new AlipayApiPayment(new PayOrderRepository());
        $plugin->init($config);
        $this->setObjectProperty($plugin, 'client', $fake);
        $order = [
            'pay_no' => 'P202607150001',
            'amount' => 100,
            'chan_trade_no' => '',
        ];

        $query = $plugin->query($order);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $query['status'], 'TRADE_SUCCESS 应映射为支付成功');
        $this->assertSame('2026071522000000000001', (string) $query['chan_trade_no'], '成功查单应返回支付宝 trade_no');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '支付宝关单 code=10000 应成功');
        $cancel = $plugin->cancel($order);
        $this->assertTrue((bool) $cancel['success'], '支付宝撤销 code=10000 应成功');
        $this->assertSame('close', (string) $cancel['action'], '支付宝撤销应保留 action 状态');

        $refund = $plugin->refund($order + [
            'refund_amount' => 50,
            'refund_no' => 'R202607150001',
            'refund_reason' => '测试退款',
        ]);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $refund['status'], 'fund_change=N 时必须由退款查询 REFUND_SUCCESS 确认成功');
        $this->assertSame('R202607150001', (string) $refund['chan_refund_no'], '退款结果必须返回约定的渠道退款号');

        $tradeStatus = $this->privateMethod(AlipayApiPayment::class, 'tradeStatus');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, $tradeStatus->invoke($plugin, 'TRADE_FINISHED'), 'TRADE_FINISHED 应映射成功');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, $tradeStatus->invoke($plugin, 'TRADE_CLOSED'), 'TRADE_CLOSED 应映射关闭');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, $tradeStatus->invoke($plugin, 'WAIT_BUYER_PAY'), 'WAIT_BUYER_PAY 应保持处理中');

        $pendingFake = new class(
            $config,
            $this->alipayResponse(AlipayClient::METHOD_TRADE_REFUND, [
                'code' => '10000',
                'msg' => 'Success',
                'fund_change' => 'N',
            ]),
            $this->alipayResponse(AlipayClient::METHOD_TRADE_REFUND_QUERY, [
                'code' => '10000',
                'msg' => 'Success',
                'refund_status' => 'REFUND_PROCESSING',
            ])
        ) extends AlipayClient {
            /**
             * 创建退款状态测试客户端。
             *
             * @param array<string, mixed> $config SDK 配置
             * @param AlipayResponse $refundResponse 退款响应
             * @param AlipayResponse $queryResponse 退款查询响应
             */
            public function __construct(
                array $config,
                private readonly AlipayResponse $refundResponse,
                private readonly AlipayResponse $queryResponse
            ) {
                parent::__construct($config);
            }

            public function refund(array $bizContent, array $options = []): AlipayResponse
            {
                return $this->refundResponse;
            }

            public function refundQuery(array $bizContent, array $options = []): AlipayResponse
            {
                return $this->queryResponse;
            }
        };
        $pendingPlugin = new AlipayApiPayment(new PayOrderRepository());
        $pendingPlugin->init($config);
        $this->setObjectProperty($pendingPlugin, 'client', $pendingFake);
        $pendingRefund = $pendingPlugin->refund($order + [
            'refund_amount' => 50,
            'refund_no' => 'R202607150002',
        ]);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pendingRefund['status'], '退款查询未返回 REFUND_SUCCESS 时不能确认本地退款成功');
    }

    /**
     * 微信支付官方 V2 MD5/HMAC-SHA256 签名向量与密钥轮换。
     *
     * @return void
     */
    private function testWxpayV2SignerOfficialVectors(): void
    {
        $params = [
            'appid' => 'wxd930ea5d5a258f4f',
            'mch_id' => '10000100',
            'device_info' => '1000',
            'body' => 'test',
            'nonce_str' => 'ibuaiVcKdpRxkhJA',
        ];
        $apiKey = '192006250b4c09247ec02edce69f6a2d';

        $this->assertSame(
            '9A0A8659F005D6984697E2CA0A9CF3B7',
            WxpaySigner::signV2($params, $apiKey, 'MD5'),
            '微信支付官方 V2 MD5 签名向量不匹配'
        );
        $this->assertSame(
            '6A9AE1657590FD6257D693A078E1C3E4BB6BA4DC30B23E0EE2496E54170DACD6',
            WxpaySigner::signV2($params, $apiKey, 'HMAC-SHA256'),
            '微信支付官方 V2 HMAC-SHA256 签名向量不匹配'
        );

        $notification = $params + ['sign' => WxpaySigner::signV2($params, $apiKey, 'MD5')];
        $this->assertTrue(
            WxpaySigner::verifyV2($notification, [str_repeat('n', 32), $apiKey]),
            'V2 密钥轮换期应允许通知使用显式配置的上一把密钥验签'
        );
        $this->assertTrue(
            WxpaySigner::verifyV2(array_replace($notification, ['sign' => strtolower($notification['sign'])]), $apiKey),
            'V2 十六进制签名比较应兼容大小写'
        );

        $hmacWithoutSignType = $params + [
            'sign' => WxpaySigner::signV2($params, $apiKey, 'HMAC-SHA256'),
        ];
        $this->assertTrue(
            WxpaySigner::verifyV2($hmacWithoutSignType, $apiKey),
            'V2 响应未回传 sign_type 时应按 64 位签名识别 HMAC-SHA256'
        );
        $this->assertSame(
            'zero=0&key=' . $apiKey,
            WxpaySigner::v2SignContent(['empty' => false, 'zero' => 0], $apiKey),
            'V2 签名应排除字符串化后的空值但保留数值 0'
        );

        $this->assertThrows(
            fn () => WxpayConfig::fromArray([
                'api_version' => WxpayClient::API_VERSION_V2,
                'mode' => 'merchant',
                'app_id' => 'wx-test',
                'mch_id' => '1900000109',
                'api_key' => str_repeat('x', 31),
            ]),
            'APIv2 密钥不是 32 字节时必须拒绝配置'
        );
    }

    /**
     * 微信支付 V2 XML 空节点必须保持空字符串且不参与签名。
     *
     * @return void
     */
    private function testWxpayV2XmlEmptyFields(): void
    {
        $apiKey = '192006250b4c09247ec02edce69f6a2d';
        $params = [
            'return_code' => 'SUCCESS',
            'appid' => 'wx2421b1c4370ec43b',
            'mch_id' => '10000100',
            'nonce_str' => '5d2b6c2a8db53831f7eda20af46e531c',
        ];
        $sign = WxpaySigner::signV2($params, $apiKey, 'MD5');
        $xml = '<xml>'
            . '<return_code><![CDATA[SUCCESS]]></return_code>'
            . '<appid><![CDATA[wx2421b1c4370ec43b]]></appid>'
            . '<mch_id><![CDATA[10000100]]></mch_id>'
            . '<nonce_str><![CDATA[5d2b6c2a8db53831f7eda20af46e531c]]></nonce_str>'
            . '<device_info><![CDATA[]]></device_info>'
            . '<coupon_fee></coupon_fee>'
            . '<sign><![CDATA[' . $sign . ']]></sign>'
            . '</xml>';

        $decoded = WxpayXml::decode($xml);
        $this->assertSame('', $decoded['device_info'], 'CDATA 空节点必须解析为空字符串');
        $this->assertSame('', $decoded['coupon_fee'], '普通空节点必须解析为空字符串');
        $this->assertTrue(WxpaySigner::verifyV2($decoded, $apiKey), '空节点不应破坏 V2 通知验签');
        $this->assertThrows(
            fn () => WxpayXml::decode('<!DOCTYPE xml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><xml><a>&xxe;</a></xml>'),
            'V2 XML 必须拒绝 DTD 和外部实体'
        );
        $this->assertThrows(
            fn () => WxpayXml::decode('<xml><a>1</a><a>2</a></xml>'),
            'V2 XML 必须拒绝重复字段'
        );
    }

    /**
     * 微信支付 V2 成功响应必须验签，沙箱请求必须使用 sandbox_signkey。
     *
     * @return void
     */
    private function testWxpayV2ResponseAndSandbox(): void
    {
        $apiKey = str_repeat('r', 32);
        $responseData = [
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'nonce_str' => 'response-nonce',
        ];
        $responseXml = WxpayXml::encode($responseData + [
            'sign' => WxpaySigner::signV2($responseData, $apiKey, 'HMAC-SHA256'),
        ]);
        $client = new WxpayClient([
            'api_version' => WxpayClient::API_VERSION_V2,
            'mode' => 'merchant',
            'app_id' => 'wx-v2-test',
            'mch_id' => '1900000109',
            'api_key' => $apiKey,
            'v2_sign_type' => 'HMAC-SHA256',
        ]);
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, [], $responseXml),
            ]),
        ]));
        $response = $client->requestV2('/pay/orderquery', [
            'appid' => 'wx-v2-test',
            'mch_id' => '1900000109',
            'nonce_str' => 'request-nonce',
            'sign_type' => 'HMAC-SHA256',
            'out_trade_no' => 'P202607150001',
        ]);
        $this->assertTrue($response->success(), 'V2 有效签名响应应正常返回');

        $invalidClient = new WxpayClient([
            'api_version' => WxpayClient::API_VERSION_V2,
            'mode' => 'merchant',
            'app_id' => 'wx-v2-test',
            'mch_id' => '1900000109',
            'api_key' => $apiKey,
            'v2_sign_type' => 'HMAC-SHA256',
        ]);
        $invalidXml = WxpayXml::encode($responseData + ['sign' => str_repeat('0', 64)]);
        $this->setObjectProperty($invalidClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, [], $invalidXml),
            ]),
        ]));
        $this->assertThrows(
            fn () => $invalidClient->requestV2('/pay/orderquery', [
                'appid' => 'wx-v2-test',
                'mch_id' => '1900000109',
                'nonce_str' => 'request-nonce',
                'sign_type' => 'HMAC-SHA256',
                'out_trade_no' => 'P202607150001',
            ]),
            'V2 成功响应签名错误时必须拒绝'
        );

        $sandboxApiKey = str_repeat('s', 32);
        $sandboxSignKey = str_repeat('b', 32);
        $sandboxKeyXml = WxpayXml::encode([
            'return_code' => 'SUCCESS',
            'sandbox_signkey' => $sandboxSignKey,
        ]);
        $sandboxResponseData = [
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'sign_type' => 'MD5',
            'nonce_str' => 'sandbox-response',
        ];
        $sandboxResponseXml = WxpayXml::encode($sandboxResponseData + [
            'sign' => WxpaySigner::signV2($sandboxResponseData, $sandboxSignKey, 'MD5'),
        ]);
        $sandboxClient = new WxpayClient([
            'api_version' => WxpayClient::API_VERSION_V2,
            'mode' => 'merchant',
            'app_id' => 'wx-sandbox-test',
            'mch_id' => '1900000110',
            'api_key' => $sandboxApiKey,
            'v2_sign_type' => 'MD5',
            'sandbox' => true,
        ]);
        $this->setObjectProperty($sandboxClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, [], $sandboxKeyXml),
                new \GuzzleHttp\Psr7\Response(200, [], $sandboxResponseXml),
            ]),
        ]));
        $sandboxResponse = $sandboxClient->requestV2('/pay/orderquery', [
            'appid' => 'wx-sandbox-test',
            'mch_id' => '1900000110',
            'nonce_str' => 'sandbox-request',
            'sign_type' => 'MD5',
            'out_trade_no' => 'P202607150002',
        ]);
        $this->assertTrue($sandboxResponse->success(), 'V2 沙箱响应应使用 sandbox_signkey 验签');

        $partnerClient = new WxpayClient([
            'api_version' => WxpayClient::API_VERSION_V2,
            'mode' => 'partner',
            'app_id' => 'wx-sp-app',
            'mch_id' => '1800000001',
            'sub_mch_id' => '1900000109',
            'api_key' => str_repeat('p', 32),
        ]);
        $frontendPartnerId = $this->privateMethod(WxpayClient::class, 'frontendPartnerId');
        $this->assertSame(
            '1900000109',
            $frontendPartnerId->invoke($partnerClient, WxpayClient::API_VERSION_V2, [
                'appid' => 'wx-sp-app',
                'sub_appid' => 'wx-sub-app',
                'sub_mch_id' => '1900000109',
            ]),
            'V2 服务商 APP 调起支付必须使用子商户号 partnerid'
        );
    }

    /**
     * 微信支付 V3 公钥 ID 必须与消息头 Wechatpay-Serial 一致。
     *
     * @return void
     */
    private function testWxpayV3PublicKeyVerifier(): void
    {
        $pair = RsaKeyPairGenerator::generate(1024);
        $publicKeyId = 'PUB_KEY_ID_MPAY_UNIT_TEST';
        $client = new WxpayClient([
            'api_version' => WxpayClient::API_VERSION_V3,
            'mode' => 'merchant',
            'app_id' => 'wx-test',
            'mch_id' => '1900000109',
            'serial_no' => 'MERCHANT_SERIAL',
            'private_key' => $pair['private_key'],
            'api_v3_key' => str_repeat('v', 32),
            'wechatpay_public_key' => $pair['public_key'],
            'wechatpay_public_key_id' => $publicKeyId,
        ]);
        $timestamp = (string) time();
        $nonce = 'mpay-v3-nonce';
        $body = '{"id":"notify-id"}';
        $signature = WxpaySigner::rsaSign($timestamp . "\n" . $nonce . "\n" . $body . "\n", $pair['private_key']);
        $headers = [
            'Wechatpay-Timestamp' => $timestamp,
            'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Signature' => $signature,
            'Wechatpay-Serial' => $publicKeyId,
        ];

        $this->assertTrue($client->verifyV3Message($headers, $body), 'V3 公钥和公钥 ID 匹配时应验签通过');
        $headers['Wechatpay-Serial'] = 'PUB_KEY_ID_OTHER';
        $this->assertFalse($client->verifyV3Message($headers, $body), 'V3 公钥 ID 不匹配时必须拒绝消息');
        $headers['Wechatpay-Serial'] = $publicKeyId;
        $headers['Wechatpay-Timestamp'] = (string) (time() - 301);
        $this->assertThrows(
            fn () => $client->parseV3Notify($headers, $body),
            'V3 通知时间戳超过五分钟时必须拒绝'
        );

        $headers['Wechatpay-Timestamp'] = (string) time();
        $headers['Wechatpay-Signature'] = WxpaySigner::rsaSign(
            $headers['Wechatpay-Timestamp'] . "\n" . $nonce . "\n" . $body . "\n",
            $pair['private_key']
        );
        $this->assertThrows(
            fn () => $client->parseV3Notify($headers, $body),
            'V3 支付回调必须拒绝非 TRANSACTION.SUCCESS 事件'
        );
    }

    /**
     * 微信官方 API 插件通知必须校验订单金额、通道和普通商户/服务商身份。
     *
     * @return void
     */
    private function testWechatApiNotifyValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P202607150001',
            'pay_amount' => 100,
            'channel_id' => 12,
            'ext_json' => [
                'payment_context' => ['pay_product' => 'mp'],
            ],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            /**
             * 创建固定返回支付单的测试仓库。
             *
             * @param PayOrder $payOrder 支付单
             */
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };

        $merchantPlugin = new WechatApiPayment($repository);
        $merchantPlugin->init([
            'api_version' => WxpayClient::API_VERSION_V2,
            'mode' => 'merchant',
            'app_id' => 'wx-merchant-app',
            'mp_app_id' => 'wx-merchant-app',
            'mini_app_id' => 'wx-mini-app',
            'mch_id' => '1900000109',
            'api_key' => str_repeat('k', 32),
            'v2_sign_type' => 'HMAC-SHA256',
            'enabled_products' => ['mp'],
            'channel_id' => 12,
        ]);
        $validateV2 = $this->privateMethod(WechatApiPayment::class, 'validateV2Notify');
        $v2Data = [
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'appid' => 'wx-merchant-app',
            'mch_id' => '1900000109',
            'trade_type' => 'JSAPI',
            'out_trade_no' => 'P202607150001',
            'transaction_id' => '4200000000001',
            'total_fee' => '100',
            'fee_type' => 'CNY',
        ];
        $validated = $validateV2->invoke($merchantPlugin, $v2Data, PaymentPluginStatusConstant::SUCCESS);
        $this->assertSame('P202607150001', (string) $validated->pay_no, 'V2 普通商户通知应定位到支付单');
        $this->assertThrows(
            fn () => $validateV2->invoke(
                $merchantPlugin,
                array_replace($v2Data, ['total_fee' => '101']),
                PaymentPluginStatusConstant::SUCCESS
            ),
            'V2 通知金额与支付单金额不一致时必须拒绝'
        );
        $this->assertThrows(
            fn () => $validateV2->invoke(
                $merchantPlugin,
                array_replace($v2Data, ['appid' => 'wx-mini-app']),
                PaymentPluginStatusConstant::SUCCESS
            ),
            'JSAPI 通知 AppID 必须与支付单实际公众号/小程序产品精确匹配'
        );

        $partnerPlugin = new WechatApiPayment($repository);
        $partnerPlugin->init([
            'api_version' => WxpayClient::API_VERSION_V2,
            'mode' => 'partner',
            'sp_app_id' => 'wx-sp-app',
            'mch_id' => '1800000001',
            'sub_mch_id' => '1900000109',
            'mp_app_id' => 'wx-sub-app',
            'api_key' => str_repeat('p', 32),
            'v2_sign_type' => 'MD5',
            'enabled_products' => ['mp'],
            'channel_id' => 12,
        ]);
        $partnerData = array_replace($v2Data, [
            'appid' => 'wx-sp-app',
            'mch_id' => '1800000001',
            'sub_appid' => 'wx-sub-app',
            'sub_mch_id' => '1900000109',
        ]);
        $validated = $validateV2->invoke($partnerPlugin, $partnerData, PaymentPluginStatusConstant::SUCCESS);
        $this->assertSame('P202607150001', (string) $validated->pay_no, 'V2 服务商通知应校验服务商和子商户身份');

        $validateV3 = $this->privateMethod(WechatApiPayment::class, 'validateV3Notify');
        $v3Data = [
            'trade_state' => 'SUCCESS',
            'trade_type' => 'JSAPI',
            'appid' => 'wx-merchant-app',
            'mchid' => '1900000109',
            'out_trade_no' => 'P202607150001',
            'transaction_id' => '4200000000002',
            'amount' => ['total' => 100, 'currency' => 'CNY'],
        ];
        $validated = $validateV3->invoke($merchantPlugin, $v3Data, PaymentPluginStatusConstant::SUCCESS);
        $this->assertSame('P202607150001', (string) $validated->pay_no, 'V3 通知应校验订单、金额和商户身份');
    }

    /**
     * 银联商务 OPEN-BODY-SIG、OPEN-FORM-PARAM 和官方通知签名固定向量。
     */
    private function testChinaumsSdkSignatureAndForm(): void
    {
        $config = [
            'app_id' => 'APP-TEST-001',
            'app_key' => 'chinaums-unit-app-key',
            'communication_key' => 'impARTxrQcfwmRijpDNCw6hPxaWCddKEpYxjaKXDhCaTCXJ6',
            'sandbox' => true,
        ];
        $client = new ChinaumsClient($config);
        $body = '{"mid":"898000000000001","totalAmount":100}';
        $timestamp = '20260716123045';
        $nonce = '00112233445566778899aabbccddeeff';
        $signature = 'f+iAiRJCAmIBqvJA/ClbFzWYbj5T3XxNpi27vW9N6XE=';
        $this->assertSame($signature, $client->signature($timestamp, $nonce, $body), '银联商务报文 HMAC-SHA256 固定向量不匹配');
        $this->assertSame(
            'OPEN-BODY-SIG AppId="APP-TEST-001", Timestamp="20260716123045", '
                . 'Nonce="00112233445566778899aabbccddeeff", Signature="' . $signature . '"',
            $client->bodyAuthorization($body, $timestamp, $nonce),
            '银联商务 OPEN-BODY-SIG 请求头格式不正确'
        );

        $form = $client->formParameters([
            'mid' => '898000000000001',
            'orderDesc' => '银联商务表单原文',
            'totalAmount' => 100,
        ], $timestamp, $nonce);
        $this->assertSame(
            '{"mid":"898000000000001","orderDesc":"银联商务表单原文","totalAmount":100}',
            (string) $form['content'],
            'OPEN-FORM-PARAM content 必须保留签名时 JSON 原文'
        );
        $this->assertSame(
            $client->signature($timestamp, $nonce, (string) $form['content']),
            (string) $form['signature'],
            'OPEN-FORM-PARAM 签名必须基于原始 content'
        );
        $this->assertSame(
            ChinaumsClient::TEST_GATEWAY . '/v1/netpay/trade/h5-pay',
            $client->endpoint('/v1/netpay/trade/h5-pay'),
            '银联商务沙箱环境地址选择错误'
        );
        $production = new ChinaumsClient(array_replace($config, ['sandbox' => false]));
        $this->assertSame(ChinaumsClient::PROD_GATEWAY, $production->gatewayUrl(), '银联商务生产环境地址选择错误');

        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"errCode":"SUCCESS"}'),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $stack]));
        $client->request('/v1/netpay/query', ['mid' => '898000000000001', 'totalAmount' => 100]);
        $sentBody = (string) $history[0]['request']->getBody();
        $authorization = (string) $history[0]['request']->getHeaderLine('Authorization');
        $this->assertSame($body, $sentBody, '银联商务 SDK 签名 JSON 与实际发送原文必须完全相同');
        $this->assertSame(
            ChinaumsClient::TEST_GATEWAY . '/v1/netpay/query',
            (string) $history[0]['request']->getUri(),
            '银联商务 SDK 实际请求未使用测试环境'
        );
        $matched = preg_match(
            '/^OPEN-BODY-SIG AppId="APP-TEST-001", Timestamp="(\d{14})", Nonce="([^"]+)", Signature="([^"]+)"$/',
            $authorization,
            $parts
        );
        $this->assertSame(1, $matched, '银联商务实际 Authorization 头格式不正确');
        $this->assertSame(
            $client->signature((string) $parts[1], (string) $parts[2], $sentBody),
            (string) $parts[3],
            '银联商务实际请求头签名与发送原文不匹配'
        );

        // 银联商务官方通知签名规则页给出的完整示例和固定摘要。
        $notify = [
            'Ue' => 'hobh',
            'billFunds' => '支付宝余额:1',
            'billFundsDesc' => '支付宝余额支付0.01元。',
            'buyerCashPayAmt' => '1',
            'buyerId' => '2088202932263863',
            'buyerPayAmount' => '1',
            'buyerUsername' => 'tj.***@gmail.com',
            'cardAttr' => 'BALANCE',
            'connectSys' => 'UNIONPAY',
            'couponAmount' => '0',
            'createTime' => '2022-11-16 09:48:12',
            'freeSettlementAmt' => '0',
            'instMid' => 'H5DEFAULT',
            'invoiceAmount' => '1',
            'mchntUuid' => '6d47dc12a4c847eaba2eb456d201d5cd',
            'merName' => '中保付测试商户(中保付测试商户)',
            'merOrderId' => '101720221116094809280000006',
            'mid' => '898201612345678',
            'msgType' => 'trade.notify',
            'notifyId' => '2bbcfd87-6e93-4fc4-b38f-1c5d853ecbb8',
            'orderDesc' => '中保付测试商户(中保付测试商户)',
            'payTime' => '2022-11-16 09:48:41',
            'receiptAmount' => '1',
            'seqId' => '01193401135N',
            'settleDate' => '2022-11-16',
            'signType' => 'SHA256',
            'status' => 'TRADE_SUCCESS',
            'subInst' => '100200',
            'targetOrderId' => '2022111622001463861446080716',
            'targetSys' => 'Alipay 2.0',
            'tid' => '88880001',
            'totalAmount' => '1',
        ];
        $this->assertSame(
            '7070E720EE550D9A60FB5B99B7201826',
            $client->notifySignature($notify, 'MD5'),
            '银联商务官方通知 MD5 固定向量不匹配'
        );
        $this->assertSame(
            '68CFB169494DD0EA83DB11AA132A1C6EC46DED75F8E1DCAADDC0011D91B6B4FE',
            $client->notifySignature($notify, 'SHA256'),
            '银联商务官方通知 SHA256 固定向量不匹配'
        );
        $signed = $notify + ['sign' => strtolower($client->notifySignature($notify))];
        $this->assertTrue($client->verifyNotify($signed), '银联商务通知签名应兼容十六进制大小写');
        $this->assertFalse(
            $client->verifyNotify(array_replace($signed, ['signType' => 'SHA1'])),
            '银联商务未知 signType 不得回退为 MD5'
        );
    }

    /**
     * 银联商务环境候选、产品开关、显式 H5 转小程序与 POST 表单承接。
     */
    private function testChinaumsProductRoutingAndH5Form(): void
    {
        $client = $this->chinaumsClient(static fn (string $path): array => [
            'errCode' => 'SUCCESS',
            'billQRCode' => 'https://qr.chinaums.unit/pay?id=QR-CLOSE-001',
        ]);
        $plugin = $this->chinaumsPlugin($client);

        $pc = $plugin->pay($this->chinaumsOrder('alipay', 'pc'));
        $this->assertSame('alipay_scan', (string) $pc['pay_product'], 'PC 支付宝必须选择扫码而不是误用 H5');
        $this->assertSame('qrcode', (string) $pc['presentation']['pay_page'], '银联商务扫码 presentation 不正确');
        $this->assertSame('/v1/netpay/bills/get-qrcode', $client->calls[0]['path'], '银联商务扫码接口路径不正确');
        $this->assertSame(100, $client->calls[0]['data']['totalAmount'], '银联商务下单金额必须使用整数分');
        $this->assertFalse(array_key_exists('raw', (array) $pc['presentation']['pay_params']), '扫码 pay_params 不得保存完整上游原文');
        $this->assertSame('QR-CLOSE-001', (string) $pc['channel_context']['qr_code_id'], '扫码关单所需 qrCodeId 应进入渠道上下文');
        $this->assertTrue(isset($pc['channel_context']['bill_date']), '扫码下单必须持久化查单/退款所需 billDate');
        PaymentPluginPayResultValidator::make($pc)->withScene('pay_result')->validate();
        $this->assertSame(
            'wxpay_scan',
            (string) $plugin->pay($this->chinaumsOrder('wxpay', 'pc'))['pay_product'],
            'PC 微信支付必须选择微信扫码产品'
        );
        $this->assertSame(
            'bank_scan',
            (string) $plugin->pay($this->chinaumsOrder('bank', 'pc'))['pay_product'],
            'PC 云闪付必须选择独立 bank_scan 产品'
        );

        $mobile = $plugin->pay($this->chinaumsOrder('alipay', 'mobile'));
        $this->assertSame('alipay_h5', (string) $mobile['pay_product'], '移动支付宝必须选择已开通 H5 产品');
        $this->assertSame('post', (string) $mobile['presentation']['pay_params']['method'], '银联商务 H5 必须由收银台 POST 原始表单');
        $this->assertSame(
            ChinaumsClient::TEST_GATEWAY . '/v1/netpay/trade/h5-pay',
            (string) $mobile['presentation']['pay_params']['action'],
            '银联商务 H5 表单 action 或环境选择不正确'
        );
        $payload = (array) $mobile['presentation']['pay_params']['payload'];
        $content = json_decode((string) ($payload['content'] ?? ''), true);
        $this->assertTrue(is_array($content), '银联商务 H5 content 必须是完整 JSON 原文');
        $this->assertSame(100, (int) $content['totalAmount'], '银联商务 H5 金额必须保持整数分');
        $this->assertSame('H5DEFAULT', (string) $content['instMid'], '银联商务 H5 instMid 不正确');
        $this->assertSame(
            $client->signature((string) $payload['timestamp'], (string) $payload['nonce'], (string) $payload['content']),
            (string) $payload['signature'],
            '收银台 POST 的 content/signature 原文不匹配'
        );
        $this->assertFalse(array_key_exists('url', (array) $mobile['presentation']['pay_params']), 'H5 不得再拼接签名 query URL');
        PaymentPluginPayResultValidator::make($mobile)->withScene('pay_result')->validate();

        $wxH5 = $plugin->pay($this->chinaumsOrder('wxpay', 'mobile'));
        $wxContent = json_decode((string) $wxH5['presentation']['pay_params']['payload']['content'], true);
        $this->assertSame('wxpay_h5', (string) $wxH5['pay_product'], '普通微信移动支付不得自动切换 H5 转小程序');
        $this->assertSame('AND_WAP', (string) $wxContent['sceneType'], '微信 H5 sceneType 不正确');
        $this->assertSame('https://merchant.unit.test', (string) $wxContent['merAppId'], '微信 H5 必须使用已配置备案域名');

        $mini = $plugin->pay($this->chinaumsOrder('wxpay', 'mobile', ['method' => 'urlscheme']));
        $this->assertSame('wxpay_mini_h5', (string) $mini['pay_product'], '显式 urlscheme 才能选择微信 H5 转小程序');
        $this->assertTrue(
            str_ends_with((string) $mini['presentation']['pay_params']['action'], '/v1/netpay/wxpay/h5-to-minipay'),
            '微信 H5 转小程序接口路径不正确'
        );

        $withoutMiniClient = $this->chinaumsClient(static fn (): array => ['errCode' => 'SUCCESS', 'billQRCode' => 'QR']);
        $withoutMini = $this->chinaumsPlugin($withoutMiniClient, [
            'enabled_products' => ['wxpay_h5', 'wxpay_scan'],
        ]);
        $this->assertThrows(
            fn () => $withoutMini->pay($this->chinaumsOrder('wxpay', 'mobile', ['method' => 'urlscheme'])),
            '显式 H5 转小程序未开通时必须失败，不能静默回退普通微信产品'
        );
        $this->assertSame(0, count($withoutMiniClient->calls), '未开通 H5 转小程序时不得请求任何上游产品');

        $scanOnlyClient = $this->chinaumsClient(static fn (): array => ['errCode' => 'SUCCESS', 'billQRCode' => 'QR']);
        $scanOnly = $this->chinaumsPlugin($scanOnlyClient, ['enabled_products' => ['alipay_scan']]);
        $fallback = $scanOnly->pay($this->chinaumsOrder('alipay', 'mobile'));
        $this->assertSame('alipay_scan', (string) $fallback['pay_product'], '未开通 H5 时只能回退已开通扫码产品');
        $this->assertSame('/v1/netpay/bills/get-qrcode', $scanOnlyClient->calls[0]['path'], '产品开关必须阻止请求未开通的 H5 接口');
    }

    /**
     * 银联商务通知签名、订单关联、产品、身份、整数分、币种和重放校验。
     */
    private function testChinaumsNotifyBusinessValidation(): void
    {
        $h5Order = new PayOrder();
        $h5Order->forceFill([
            'pay_no' => 'P202607160001',
            'pay_amount' => 100,
            'channel_id' => 12,
            'channel_order_no' => '1017P202607160001',
            'channel_trade_no' => '',
            'ext_json' => ['payment_context' => ['pay_product' => 'alipay_h5']],
        ]);
        $qrOrder = new PayOrder();
        $qrOrder->forceFill([
            'pay_no' => 'P202607160002',
            'pay_amount' => 200,
            'channel_id' => 12,
            'channel_order_no' => '1017P202607160002',
            'channel_trade_no' => '',
            'ext_json' => ['payment_context' => ['pay_product' => 'wxpay_scan']],
        ]);
        $repository = new class([$h5Order, $qrOrder]) extends PayOrderRepository {
            /**
             * 构造测试客户端。
             *
             * @param array<int, PayOrder> $orders
             */
            public function __construct(private array $orders) {}
            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                foreach ($this->orders as $order) {
                    if ((string) $order->pay_no === $payNo) {
                        return $order;
                    }
                }
                return null;
            }
        };
        $client = $this->chinaumsClient(static fn (): array => []);
        $plugin = $this->chinaumsPlugin($client, [], $repository);
        $payload = [
            'instMid' => 'H5DEFAULT',
            'merOrderId' => '1017P202607160001',
            'mid' => '898UNITMERCHANT',
            'tid' => 'UNITTERM01',
            'status' => 'TRADE_SUCCESS',
            'totalAmount' => '100',
            'currency' => 'CNY',
            'targetOrderId' => 'ALI202607160001',
            'targetSys' => 'Alipay 2.0',
            'payTime' => '20260716123045',
            'signType' => 'SHA256',
        ];
        $payload = $this->chinaumsSignedNotify($client, $payload);
        $result = $plugin->notify($this->rawFormRequest($payload));
        $repeat = $plugin->notify($this->rawFormRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '银联商务明确成功通知应归一为成功');
        $this->assertSame('P202607160001', (string) $result['pay_no'], '银联商务通知必须从来源订单号还原当前 pay_no');
        $this->assertSame(100, (int) $result['paid_amount'], '银联商务通知金额必须返回整数分');
        $this->assertSame($result, $repeat, '相同银联商务通知重放应稳定并由核心生命周期幂等处理');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        foreach ([
            ['merOrderId', '1017P202607169999', '错单'],
            ['totalAmount', '101', '错金额'],
            ['mid', 'WRONG-MID', '错商户'],
            ['tid', 'WRONG-TID', '错终端'],
            ['status', 'WAIT_BUYER_PAY', '非明确成功状态'],
            ['currency', 'USD', '错币种'],
            ['targetSys', 'WXPay', '错产品'],
        ] as [$field, $value, $label]) {
            $changed = array_replace($payload, [$field => $value]);
            unset($changed['sign']);
            $this->assertThrows(
                fn () => $plugin->notify($this->rawFormRequest($this->chinaumsSignedNotify($client, $changed))),
                '合法重新签名的银联商务' . $label . '通知必须拒绝'
            );
        }

        $badSignature = array_replace($payload, ['sign' => str_repeat('0', 64)]);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($badSignature)), '银联商务错签名通知必须拒绝');
        $h5Order->channel_trade_no = 'ALI-OTHER-TRADE';
        $this->assertThrows(
            fn () => $plugin->notify($this->rawFormRequest($payload)),
            '银联商务重放通知渠道流水与已保存值不一致必须拒绝'
        );
        $h5Order->channel_trade_no = '';

        $billPayment = json_encode([
            'targetOrderId' => 'WX202607160002',
            'targetSys' => 'WXPay',
            'payTime' => '20260716123100',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertTrue(is_string($billPayment), '银联商务扫码测试明细 JSON 生成失败');
        $qrPayload = $this->chinaumsSignedNotify($client, [
            'instMid' => 'QRPAYDEFAULT',
            'billNo' => '1017P202607160002',
            'mid' => '898UNITMERCHANT',
            'tid' => 'UNITTERM01',
            'billStatus' => 'PAID',
            'totalAmount' => '200',
            'billPayment' => $billPayment,
            'signType' => 'MD5',
        ]);
        $qrResult = $plugin->notify($this->rawFormRequest($qrPayload));
        $this->assertSame('WX202607160002', (string) $qrResult['chan_trade_no'], '扫码回调应从 billPayment 提取渠道交易号');
    }

    /**
     * 银联商务 H5/扫码查单、关单、退款路径、状态和金额关联。
     */
    private function testChinaumsQueryCloseRefund(): void
    {
        $client = $this->chinaumsClient(static function (string $path, array $data): array {
            return match ($path) {
                '/v1/netpay/query' => [
                    'errCode' => 'SUCCESS',
                    'mid' => '898UNITMERCHANT',
                    'tid' => 'UNITTERM01',
                    'merOrderId' => (string) $data['merOrderId'],
                    'totalAmount' => 100,
                    'status' => 'TRADE_SUCCESS',
                    'targetOrderId' => 'ALI-QUERY-001',
                    'targetSys' => 'Alipay 2.0',
                    'payTime' => '20260716123045',
                ],
                '/v1/netpay/bills/query' => [
                    'errCode' => 'SUCCESS',
                    'mid' => '898UNITMERCHANT',
                    'tid' => 'UNITTERM01',
                    'billNo' => (string) $data['billNo'],
                    'totalAmount' => '200',
                    'billStatus' => 'PAID',
                    'targetOrderId' => 'WX-QUERY-001',
                    'targetSys' => 'WXPay',
                ],
                '/v1/netpay/close' => ['errCode' => 'SUCCESS', 'status' => 'TRADE_CLOSED'],
                '/v1/netpay/bills/close-qrcode' => ['errCode' => 'SUCCESS', 'billStatus' => 'CLOSED'],
                '/v1/netpay/refund' => [
                    'errCode' => 'SUCCESS',
                    'refundOrderId' => (string) $data['refundOrderId'],
                    'refundAmount' => (int) $data['refundAmount'],
                    'status' => 'TRADE_SUCCESS',
                ],
                '/v1/netpay/bills/refund' => [
                    'errCode' => 'SUCCESS',
                    'refundOrderId' => (string) $data['refundOrderId'],
                    'refundAmount' => (int) $data['refundAmount'],
                    'refundStatus' => 'SUCCESS',
                ],
                default => ['errCode' => 'UNKNOWN'],
            };
        });
        $plugin = $this->chinaumsPlugin($client);
        $h5 = [
            'pay_no' => 'P202607160003',
            'amount' => 100,
            'chan_order_no' => '1017P202607160003',
            'pay_product' => 'alipay_h5',
            'channel_context' => [],
        ];
        $qr = [
            'pay_no' => 'P202607160004',
            'amount' => 200,
            'chan_order_no' => '1017P202607160004',
            'pay_product' => 'wxpay_scan',
            'channel_context' => [
                'bill_date' => '2026-07-16',
                'qr_code_id' => 'QR-CLOSE-004',
            ],
        ];

        $h5Query = $plugin->query($h5);
        $qrQuery = $plugin->query($qr);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $h5Query['status'], '银联商务 TRADE_SUCCESS 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $qrQuery['status'], '银联商务 PAID 查单映射错误');
        $this->assertSame('ALI-QUERY-001', (string) $h5Query['chan_trade_no'], '银联商务 H5 查单渠道流水错误');
        $this->assertSame('2026-07-16', (string) $client->calls[1]['data']['billDate'], '扫码查单必须复用下单 billDate');
        $this->assertFalse(array_key_exists('mid', (array) $h5Query['raw_data']), '查单 raw_data 不得保存商户身份');
        $this->assertFalse(array_key_exists('tid', (array) $h5Query['raw_data']), '查单 raw_data 不得保存终端身份');

        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($h5)['status'], '银联商务 H5 关单成功状态映射错误');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($qr)['status'], '银联商务扫码关单成功状态映射错误');
        $this->assertSame('QR-CLOSE-004', (string) $client->calls[3]['data']['qrCodeId'], '扫码关单必须使用官方 qrCodeId');

        $h5Refund = $plugin->refund($h5 + ['refund_no' => 'R202607160003', 'refund_amount' => 50]);
        $qrRefund = $plugin->refund($qr + ['refund_no' => 'R202607160004', 'refund_amount' => 80]);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $h5Refund['status'], '银联商务 H5 退款成功状态映射错误');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $qrRefund['status'], '银联商务扫码退款成功状态映射错误');
        $this->assertSame(50, $client->calls[4]['data']['refundAmount'], '银联商务 H5 退款必须使用整数分');
        $this->assertSame('2026-07-16', (string) $client->calls[5]['data']['billDate'], '扫码退款必须复用原交易 billDate');

        $this->assertThrows(
            fn () => $plugin->query(array_replace_recursive($qr, ['channel_context' => ['bill_date' => '']])),
            '扫码查单缺少原 billDate 时不得猜测交易日期'
        );
        $this->assertThrows(
            fn () => $plugin->close(array_replace_recursive($qr, ['channel_context' => ['qr_code_id' => '']])),
            '扫码关单缺少 qrCodeId 时不得假实现'
        );

        $badClient = $this->chinaumsClient(static fn (string $path, array $data): array => [
            'errCode' => 'SUCCESS',
            'mid' => '898UNITMERCHANT',
            'tid' => 'UNITTERM01',
            'merOrderId' => (string) ($data['merOrderId'] ?? ''),
            'totalAmount' => 101,
            'status' => 'TRADE_SUCCESS',
            'targetOrderId' => 'ALI-BAD-AMOUNT',
            'targetSys' => 'Alipay 2.0',
        ]);
        $bad = $this->chinaumsPlugin($badClient);
        $this->assertThrows(fn () => $bad->query($h5), '银联商务查单金额与支付单不一致必须拒绝');

        $queryStatuses = ['WAIT_BUYER_PAY', 'TRADE_CLOSED', 'NOT_A_REAL_STATUS'];
        $stateClient = $this->chinaumsClient(static function (string $path, array $data) use (&$queryStatuses): array {
            $status = array_shift($queryStatuses) ?? 'NOT_A_REAL_STATUS';
            return [
                'errCode' => 'SUCCESS',
                'mid' => '898UNITMERCHANT',
                'tid' => 'UNITTERM01',
                'merOrderId' => (string) $data['merOrderId'],
                'totalAmount' => 100,
                'status' => $status,
            ];
        });
        $statePlugin = $this->chinaumsPlugin($stateClient);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $statePlugin->query($h5)['status'], 'WAIT_BUYER_PAY 应保持 pending');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $statePlugin->query($h5)['status'], 'TRADE_CLOSED 应映射 closed');
        $this->assertThrows(fn () => $statePlugin->query($h5), '银联商务未知查单状态不得猜测映射');

        $pendingRefundClient = $this->chinaumsClient(static fn (string $path, array $data): array => [
            'errCode' => 'SUCCESS',
            'refundOrderId' => (string) $data['refundOrderId'],
            'refundAmount' => (int) $data['refundAmount'],
            'status' => 'PROCESSING',
        ]);
        $pendingPlugin = $this->chinaumsPlugin($pendingRefundClient);
        $pendingRefund = $pendingPlugin->refund($h5 + ['refund_no' => 'R202607160005', 'refund_amount' => 50]);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pendingRefund['status'], '银联商务退款处理中不得提前确认成功');
    }

    /**
     * 易生递归排序、摘要、RSA2、实际 JSON 字节和双 profile 固定向量。
     */
    private function testEasypaySdkProtocolAndProfiles(): void
    {
        $keys = $this->yeepayFixedKeyPair();
        $config = [
            'req_id' => 'INST-UNIT',
            'req_type' => '2',
            'certificate_id' => 'CERT-01',
            'platform_public_key' => $keys['public_key'],
            'merchant_private_key' => $keys['private_key'],
            'sandbox' => true,
        ];
        $client = new EasypayClient($config);
        $header = [
            'transTime' => '20260717123045',
            'reqType' => '2',
            'reqId' => 'INST-UNIT',
            'certificateId' => 'CERT-01',
        ];
        $body = [
            'payInfo' => ['transDate' => '20260717', 'payType' => 'AliPayNative'],
            'reqOrderInfo' => ['transAmount' => 1, 'orgTrace' => 'P-EASYPAY-VECTOR'],
            'reqInfo' => ['mchtCode' => 'MCH-UNIT'],
            'list' => [['b' => 2, 'a' => 1], ['z' => '中文']],
        ];
        $this->assertSame(
            '{"certificateId":"CERT-01","reqId":"INST-UNIT","reqType":"2","transTime":"20260717123045"}'
                . 'EAA1A836F03C3426785B56CB96181F45',
            $client->signContent($header, $body),
            '易生签名原文必须是排序请求头 JSON 拼接大写 MD5 摘要'
        );
        $signature = $client->sign($header, $body);
        $this->assertSame(
            'W8HtbYZMPYRkg1/E/DGs1gxxQu5nHgf5AowOoS8qgMURYjCQzhqwEVFn01raPGZB/LYPy3XuQKO7nlj3TbtrIDeCKyoT7JN7CV+4wo39X/l3lC4QdTpjemCzKqbDhPTfTOrDkkAL1rpQiozeyPCQsdqG5NNFzgOP2e4+t74obGIftJz3M8cZTp8FawNkKrT5UTJ8w6QOvQRcTGCgZXu/Zhi11rnEu4Os125xg2WM4oPfj8ollb0Fk1rwned7GxkppJOBF+qWlYjhG9stIIKmGVMGemUusMekuP9QhkmdcWokgFzh6VudLWCd3IIGfSVMbllySfpS1lG/Q5ymCsqt/A==',
            $signature,
            '易生 RSA-SHA256 固定向量不匹配'
        );
        $this->assertTrue($client->verify($header, $body, $signature), '易生固定向量验签失败');
        $this->assertSame(
            '{"list":[{"a":1,"b":2},{"z":"中文"}],"payInfo":{"payType":"AliPayNative","transDate":"20260717"},"reqInfo":{"mchtCode":"MCH-UNIT"},"reqOrderInfo":{"orgTrace":"P-EASYPAY-VECTOR","transAmount":1}}',
            $client->canonicalJson($body),
            '易生递归排序必须保持列表顺序并使用 UTF-8 JSON'
        );

        $privateKey = openssl_pkey_get_private($keys['private_key']);
        $opensslOptions = [
            'digest_alg' => 'sha256',
            'config' => base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ];
        $csr = $privateKey === false
            ? false
            : openssl_csr_new(['commonName' => 'easypay.unit.test'], $privateKey, $opensslOptions);
        $certificate = $csr === false || $privateKey === false
            ? false
            : openssl_csr_sign($csr, null, $privateKey, 1, $opensslOptions, 3001);
        $certificatePem = '';
        if ($certificate === false || !openssl_x509_export($certificate, $certificatePem)) {
            throw new \RuntimeException('易生证书格式测试夹具生成失败');
        }
        $certificateDer = base64_decode((string) preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $certificatePem
        ), true);
        if (!is_string($certificateDer)) {
            throw new \RuntimeException('易生 DER 证书测试夹具生成失败');
        }
        $certificateClient = new EasypayClient(array_replace($config, ['platform_public_key' => $certificateDer]));
        $this->assertTrue($certificateClient->verify($header, $body, $signature), '易生 DER X.509 公钥证书必须可验签');

        $responseHeader = ['rspCode' => '000000', 'rspInfo' => '成功', 'rspTime' => '20260717123046'];
        $responseBody = ['respStateInfo' => ['respCode' => '000000', 'transState' => '9']];
        $responseSign = $client->sign($responseHeader, $responseBody);
        $history = [];
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'rspHeader' => $responseHeader,
                'rspBody' => $responseBody,
                'rspSign' => $responseSign,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $transport = new EasypayClient(array_replace($config, [
            'http_client' => new \GuzzleHttp\Client(['handler' => $stack]),
        ]));
        $this->assertSame($responseBody, $transport->execute('/trade/native', $body), '易生已验签响应体解析错误');
        $sent = (string) $history[0]['request']->getBody();
        $sentPayload = json_decode($sent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($body, $sentPayload['reqBody'], '易生实际发送 reqBody 不得被 Guzzle 二次编码');
        $this->assertSame(
            json_encode($sentPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            $sent,
            '易生签名请求包必须只 JSON 编码一次'
        );
        $this->assertTrue(
            $client->verify($sentPayload['reqHeader'], $sentPayload['reqBody'], (string) $sentPayload['reqSign']),
            '易生实际发送请求签名无法按原报文复验'
        );
        $this->assertSame(
            'application/json; charset=UTF-8',
            $history[0]['request']->getHeaderLine('Content-Type'),
            '易生请求字符集必须显式使用 UTF-8'
        );

        $badSignatureClient = new EasypayClient(array_replace($config, [
            'http_client' => new \GuzzleHttp\Client(['handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'rspHeader' => ['rspCode' => '999999', 'rspInfo' => '未知'],
                    'rspBody' => [],
                    'rspSign' => base64_encode('bad-sign'),
                ])),
            ])]),
        ]));
        try {
            $badSignatureClient->execute('/trade/native', $body);
            throw new \RuntimeException('易生错签响应未被拒绝');
        } catch (EasypaySdkException $e) {
            $this->assertTrue(str_contains($e->getMessage(), '验签失败'), '易生必须先验签再解释 rspCode');
        }

        $institutionClient = new EasypayUnitClient(fn (string $path, array $request): array => $this->easypayTradeResponse($request));
        $institution = $this->easypayPlugin($institutionClient);
        $institution->pay($this->easypayOrder('alipay', 'pc', ['method' => 'qrcode']));
        $this->assertSame('MCH-EASYPAY', (string) $institutionClient->calls[0]['data']['reqInfo']['mchtCode'], '机构模式必须显式使用子商户号');

        $merchantClient = new EasypayUnitClient(fn (string $path, array $request): array => $this->easypayTradeResponse($request));
        $merchant = $this->easypayPlugin($merchantClient, ['req_type' => '1', 'req_id' => 'DIRECT-MCH', 'sub_merchant_no' => 'IGNORED']);
        $merchant->pay($this->easypayOrder('alipay', 'pc', ['method' => 'qrcode']));
        $this->assertSame('DIRECT-MCH', (string) $merchantClient->calls[0]['data']['reqInfo']['mchtCode'], '商户模式必须使用 req_id 作为 mchtCode');
    }

    /**
     * 易生六产品开关、环境选择和三平台严格身份字段。
     */
    private function testEasypayProductsAndIdentity(): void
    {
        $client = new EasypayUnitClient(function (string $path, array $request): array {
            return $this->easypayTradeResponse($request);
        });
        $plugin = $this->easypayPlugin($client);
        $this->assertTrue($plugin instanceof PaymentIdentityRequirementInterface, '易生 JSAPI 必须实现身份需求接口');
        $schema = array_values(array_filter(
            $plugin->getConfigSchema(),
            static fn (array $field): bool => ($field['field'] ?? '') === 'enabled_products'
        ));
        $this->assertFalse(
            in_array('WeChatNative', (array) ($schema[0]['value'] ?? []), true),
            'rainbow_legacy WeChatNative 不得默认启用'
        );

        $cases = [
            ['alipay', 'alipay', ['method' => 'jsapi', 'buyer_id' => '2088UNIT'], 'AliPayJsapi', 'jsapi'],
            ['wxpay', 'wechat', ['method' => 'jsapi', 'sub_openid' => 'WX-MP-UNIT'], 'WeChatJsapi', 'jsapi'],
            ['bank', 'mobile', $this->easypayUnionPayment(), 'UnionPayJsapi', 'jump'],
            ['alipay', 'pc', ['method' => 'qrcode'], 'AliPayNative', 'qrcode'],
            ['wxpay', 'pc', ['method' => 'qrcode'], 'WeChatNative', 'qrcode'],
            ['bank', 'pc', ['method' => 'qrcode'], 'UnionPayNative', 'qrcode'],
        ];
        foreach ($cases as [$payType, $env, $payment, $product, $page]) {
            $result = $plugin->pay($this->easypayOrder($payType, $env, $payment));
            $this->assertSame($product, (string) $result['pay_product'], '易生产品映射错误：' . $product);
            $this->assertSame($page, (string) $result['presentation']['pay_page'], '易生产品承接方式错误：' . $product);
            $this->assertFalse(array_key_exists('raw_data', $result), '易生标准结果不得返回 raw_data');
            $this->assertFalse(array_key_exists('currency', $result), '易生标准结果不得新增 currency');
            $this->assertFalse(array_key_exists('raw', (array) $result['presentation']['pay_params']), '易生承接参数不得夹带原始渠道报文');
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();
        }
        $this->assertSame('buyerId', array_key_first($client->calls[0]['data']['aliBizParam']), '支付宝 JSAPI 必须只发送 buyerId');
        $this->assertSame('WX-MP-UNIT', (string) $client->calls[1]['data']['wxBizParam']['subOpenId'], '微信 JSAPI 必须精确使用 sub_openid');
        $this->assertFalse(isset($client->calls[1]['data']['wxBizParam']['miniOpenId']), '微信 JSAPI 不得混入 mini_openid');
        $this->assertSame('UNION-AUTH-UNIT', (string) $client->calls[2]['data']['qrBizParam']['userAuthCode'], '银联 JSAPI 必须精确使用 userAuth');

        try {
            $plugin->identityRequirement($this->easypayOrder('alipay', 'alipay', ['sub_openid' => 'WRONG']));
            throw new \RuntimeException('易生支付宝错误作用域身份被接受');
        } catch (PaymentDefinitiveException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'buyer_id'), '支付宝缺失身份必须精确指向 buyer_id');
        }
        $wxRequirement = $plugin->identityRequirement($this->easypayOrder('wxpay', 'wechat', ['mini_openid' => 'WRONG']));
        $unionRequirement = $plugin->identityRequirement($this->easypayOrder('bank', 'mobile', [
            'buyer_id' => 'WRONG',
            'sub_openid' => 'WRONG',
            'mini_openid' => 'WRONG',
            'unionpay_user_id' => 'UNION-USER-UNIT',
        ]));
        $this->assertSame('sub_openid', (string) ($wxRequirement['identity_field'] ?? ''), '微信公众号不得使用 mini_openid 兜底');
        $this->assertSame('unionpay_auth_code', (string) ($unionRequirement['identity_field'] ?? ''), '银联不得使用 buyer_id/openid 兜底');

        $nativeOnlyClient = new EasypayUnitClient(fn (string $path, array $request): array => $this->easypayTradeResponse($request));
        $nativeOnly = $this->easypayPlugin($nativeOnlyClient, ['enabled_products' => ['AliPayNative']]);
        $nativeResult = $nativeOnly->pay($this->easypayOrder('alipay', 'alipay', ['method' => 'jsapi', 'buyer_id' => '2088UNIT']));
        $this->assertSame('AliPayNative', (string) $nativeResult['pay_product'], '未启用 JSAPI 时只能在请求前选择已启用 Native');

        $uncertainClient = new EasypayUnitClient(fn (): EasypaySdkException => new EasypaySdkException('timeout', true));
        $uncertain = $this->easypayPlugin($uncertainClient);
        $this->assertThrowsClass(
            fn () => $uncertain->pay($this->easypayOrder('alipay', 'alipay', ['method' => 'jsapi', 'buyer_id' => '2088UNIT'])),
            PaymentUncertainException::class,
            '易生结果不确定后不得切换 Native 重试'
        );
        $this->assertSame(1, count($uncertainClient->calls), '易生结果不确定后不得发起第二产品请求');
    }

    /**
     * 易生查单确定字段和通知错签、错单、错金额校验。
     */
    private function testEasypayQueryAndNotifyValidation(): void
    {
        $queryClient = new EasypayUnitClient(fn (string $path, array $request): array => [
            'respStateInfo' => ['respCode' => '000000', 'transState' => '0', 'transStatusDesc' => '成功'],
            'respOrderInfo' => [
                'orgTrace' => (string) $request['reqOrderInfo']['oriOrgTrace'],
                'outTrace' => (string) $request['reqOrderInfo']['oriOutTrace'],
                'transAmount' => 100,
            ],
        ]);
        $plugin = $this->easypayPlugin($queryClient);
        $queryOrder = $this->easypayOperationOrder();
        $query = $plugin->query($queryOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $query['status'], '易生查单成功状态映射错误');
        $call = $queryClient->calls[0]['data'];
        $this->assertSame('P-EASYPAY-STATE', (string) $call['reqOrderInfo']['oriOrgTrace'], '易生查单 oriOrgTrace 必须是原商户订单号');
        $this->assertSame('OUT-EASYPAY-STATE', (string) $call['reqOrderInfo']['oriOutTrace'], '易生查单 oriOutTrace 必须是原易生流水');
        $this->assertTrue($call['reqOrderInfo']['orgTrace'] !== $call['reqOrderInfo']['oriOrgTrace'], '易生查单当前请求流水不得冒充原订单号');
        $this->assertSame('AliPayNative', (string) $call['payInfo']['payType'], '易生查单必须复用确定产品');
        PaymentPluginQueryResultValidator::make($query)->withScene('query_result')->validate();

        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-EASYPAY-NOTIFY',
            'pay_amount' => 100,
            'channel_id' => 88,
            'channel_order_no' => 'P-EASYPAY-NOTIFY',
            'channel_trade_no' => 'OUT-EASYPAY-NOTIFY',
            'ext_json' => [
                'payment_context' => [
                    'pay_product' => 'AliPayNative',
                    'channel_context' => [
                        'req_id' => 'INST-EASYPAY',
                        'req_type' => '2',
                        'mcht_code' => 'MCH-EASYPAY',
                        'pay_product' => 'AliPayNative',
                    ],
                ],
            ],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $notifyClient = new EasypayUnitClient(fn (): array => []);
        $notifyPlugin = $this->easypayPlugin($notifyClient, [], $repository);
        $payload = $this->easypayNotifyPayload();
        $notify = $notifyPlugin->notify($this->rawJsonRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $notify['status'], '易生通知成功状态映射错误');
        $this->assertSame(100, (int) $notify['paid_amount'], '易生通知金额必须保持整数分');
        PaymentPluginNotifyResultValidator::make($notify)->withScene('notify_result')->validate();
        $this->assertSame('{"code":"000000","msg":"Success"}', $notifyPlugin->notifySuccess(), '易生通知 ACK 不符合协议');

        $badSign = $this->easypayPlugin(new EasypayUnitClient(fn (): array => [], false), [], $repository);
        $this->assertThrows(fn () => $badSign->notify($this->rawJsonRequest($payload)), '易生错签通知必须拒绝');
        $wrongOrder = $payload;
        $wrongOrder['reqBody']['respOrderInfo']['orgTrace'] = 'P-EASYPAY-WRONG';
        $this->assertThrows(fn () => $notifyPlugin->notify($this->rawJsonRequest($wrongOrder)), '易生错单通知必须拒绝');
        $wrongAmount = $payload;
        $wrongAmount['reqBody']['respOrderInfo']['transAmount'] = 101;
        $this->assertThrows(fn () => $notifyPlugin->notify($this->rawJsonRequest($wrongAmount)), '易生错金额通知必须拒绝');
        $wrongProfile = $payload;
        $wrongProfile['reqHeader']['reqType'] = '1';
        $this->assertThrows(fn () => $notifyPlugin->notify($this->rawJsonRequest($wrongProfile)), '易生错 profile 通知必须拒绝');
    }

    /**
     * 易生退款成功、受理、未知状态及退款查询/关单能力边界。
     */
    private function testEasypayRefundAndCapabilities(): void
    {
        $states = ['0', '9', 'Z', 'X'];
        $client = new EasypayUnitClient(static function (string $path, array $request) use (&$states): array {
            $state = array_shift($states) ?? 'X';
            return [
                'respStateInfo' => [
                    'respCode' => '000000',
                    'transState' => $state,
                    'transStatusDesc' => $state === 'X' ? '退款失败' : '已受理',
                ],
                'respOrderInfo' => [
                    'orgTrace' => (string) $request['reqOrderInfo']['orgTrace'],
                    'outTrace' => 'REFUND-OUT-EASYPAY',
                    'oriOrgTrace' => (string) $request['reqOrderInfo']['oriOrgTrace'],
                    'oriOutTrace' => (string) $request['reqOrderInfo']['oriOutTrace'],
                    'refundAmount' => (int) $request['reqOrderInfo']['refundAmount'],
                ],
            ];
        });
        $plugin = $this->easypayPlugin($client);
        $refundOrder = $this->easypayOperationOrder() + [
            'refund_no' => 'R-EASYPAY-STATE',
            'refund_amount' => 50,
            'refund_callback_url' => 'https://merchant.unit.test/refund/easypay',
        ];
        $success = $plugin->refund($refundOrder);
        $pending = $plugin->refund($refundOrder);
        $unknown = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], '易生退款 0 必须映射成功');
        $this->assertSame('REFUND-OUT-EASYPAY', (string) $success['chan_refund_no'], '易生退款必须返回真实 outTrace');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], '易生退款 9 必须保持受理中');
        $this->assertSame(PaymentPluginStatusConstant::UNKNOWN, (string) $unknown['status'], '易生未知退款状态不得猜测');
        PaymentPluginRefundResultValidator::make($success)->withScene('refund_result')->validate();
        PaymentPluginRefundResultValidator::make($pending)->withScene('refund_result')->validate();
        PaymentPluginRefundResultValidator::make($unknown)->withScene('refund_result')->validate();
        $this->assertThrowsClass(fn () => $plugin->refund($refundOrder), PaymentDefinitiveException::class, '易生退款失败状态必须明确失败');

        $request = $client->calls[0]['data']['reqOrderInfo'];
        $this->assertSame('R-EASYPAY-STATE', (string) $request['orgTrace'], '易生退款请求号必须稳定复用 refund_no');
        $this->assertSame('P-EASYPAY-STATE', (string) $request['oriOrgTrace'], '易生退款必须发送原商户订单号');
        $this->assertSame('OUT-EASYPAY-STATE', (string) $request['oriOutTrace'], '易生退款必须发送原易生流水');
        $this->assertFalse(array_key_exists('backUrl', $request), '未实现退款通知能力时不得发送退款 backUrl');
        $this->assertFalse(array_key_exists('payType', $client->calls[0]['data']['payInfo']), '退款请求不得补猜未确定的 payType 字段');
        $this->assertSame($client->calls[0]['data'], $client->calls[1]['data'], '易生退款重试必须保持同一幂等请求字段');
        $this->assertFalse($plugin instanceof RefundQueryInterface, '易生当前不得以彩虹示例值冒充退款查询能力');
        $this->assertThrowsClass(
            fn () => $plugin->close($this->easypayOperationOrder()),
            UnsupportedPaymentOperationException::class,
            '易生关单必须保持明确不支持'
        );
    }

    /**
     * 海科递归 MD5、响应验签、固定路径与生产/sandbox 传输边界。
     */
    private function testHaipaySdkProtocolAndEnvironment(): void
    {
        $config = [
            'access_id' => 'cpostest',
            'access_key' => '123456789ABCDEF',
            'sandbox' => false,
            'sandbox_gateway' => 'http://39.106.187.68:8080',
        ];
        $client = new HaipayClient($config);
        $this->assertSame('https://saas-front.hkrt.cn', $client->gateway(), '生产模式必须固定使用 HTTPS 网关');

        $simple = ['version' => '1.0.0', 'return_code' => '0', 'empty' => '', 'null' => null, 'sign' => 'IGNORED'];
        $this->assertSame(
            'return_code=0&version=1.0.0',
            $client->signatureContent($simple),
            '海科签名必须排除 sign、空字符串和 null 后按键排序'
        );
        $this->assertSame(
            'AC58F7A8575958A2D0E212461701B371',
            $client->sign($simple),
            '海科简单签名固定向量不一致'
        );

        $nested = [
            'b' => [['d' => '3', 'c' => '2'], ['d' => '5', 'c' => '4']],
            'a' => '1',
            'empty_array' => [],
        ];
        $this->assertSame(
            'a=1&b=c=2&d=3&c=4&d=5',
            $client->signatureContent($nested),
            '海科对象列表必须保留列表顺序且不得加入数字索引名'
        );
        $this->assertSame(
            '42DDAD8072BACD089000E7F622900FDC',
            $client->sign($nested),
            '海科嵌套对象列表签名固定向量不一致'
        );
        $list = ['b' => ['2', '3'], 'a' => '1'];
        $this->assertSame('a=1&b=2&3', $client->signatureContent($list), '海科标量列表签名原文不一致');
        $this->assertSame('294F97CBCC59E43FD223544809A19049', $client->sign($list), '海科标量列表固定向量不一致');
        $uppercaseSign = $client->sign(['result_code' => '10000']);
        $this->assertTrue($client->verify(['result_code' => '10000', 'sign' => $uppercaseSign]), '海科大写 MD5 验签应通过');
        $this->assertFalse($client->verify(['result_code' => '10000', 'sign' => strtolower($uppercaseSign)]), '海科协议要求大写 MD5，不得静默接受小写签名');

        $response = ['result_code' => '10000', 'result_msg' => 'success'];
        $response['sign'] = $client->sign($response);
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($response)),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $stack]));
        $client->post('/api/v2/pay/order-query', ['merch_no' => 'M-UNIT', 'trade_no' => 'T-UNIT']);
        $request = $history[0]['request'];
        $this->assertSame('https', $request->getUri()->getScheme(), '海科生产请求必须通过 TLS');
        $this->assertSame('/api/v2/pay/order-query', $request->getUri()->getPath(), '海科查单路径不正确');
        $requestPayload = json_decode((string) $request->getBody(), true);
        $this->assertSame('cpostest', (string) ($requestPayload['accessid'] ?? ''), '海科协议字段必须使用 accessid');
        $this->assertTrue($client->verify($requestPayload), '海科实际发送 JSON 必须与签名对象一致');

        $badSignClient = new HaipayClient($config);
        $badStack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], '{"result_code":"10000","sign":"00000000000000000000000000000000"}'),
        ]));
        $this->setObjectProperty($badSignClient, 'httpClient', new \GuzzleHttp\Client(['handler' => $badStack]));
        try {
            $badSignClient->post('/api/v2/pay/order-query', ['merch_no' => 'M-UNIT', 'trade_no' => 'T-UNIT']);
            throw new \RuntimeException('海科响应错签必须失败');
        } catch (HaipaySdkException $e) {
            $this->assertTrue($e->isUncertain(), '响应错签时不能断言渠道未受理');
        }

        $this->assertThrowsClass(
            fn () => new HaipayClient(['access_id' => 'A', 'access_key' => 'K', 'sandbox' => true]),
            HaipaySdkException::class,
            'sandbox 必须显式配置测试网关'
        );
        $sandboxClient = new HaipayClient([
            'access_id' => 'A',
            'access_key' => 'K',
            'sandbox' => true,
            'sandbox_gateway' => 'http://39.106.187.68:8080',
        ]);
        $this->assertSame('http://39.106.187.68:8080', $sandboxClient->gateway(), '显式 sandbox 才允许 HTTP 测试地址');
        $this->assertThrowsClass(
            fn () => $client->post('/api/v2/pay/not-allowed', []),
            HaipaySdkException::class,
            '海科 SDK 必须拒绝未声明路径'
        );
    }

    /**
     * 海科六产品开关、环境选择、精确二维码字段及三种身份作用域。
     */
    private function testHaipayProductsAndIdentity(): void
    {
        $client = new HaipayUnitClient(function (string $path, array $request): array {
            if ($path === '/api/v2/pay/passive-pay') {
                return $this->haipayPaymentResponse($request, [
                    'pay_type' => 'ALI',
                    'trade_status' => '1',
                ]);
            }
            $extra = match ([(string) ($request['pay_type'] ?? ''), (string) ($request['pay_mode'] ?? '')]) {
                ['ALI', 'NATIVE'] => [
                    'ali_qr_code' => 'https://haipay.unit.test/ali-exact',
                    'wc_qr_code' => 'https://haipay.unit.test/wx-wrong',
                    'uniqr_qr_code' => 'https://haipay.unit.test/union-wrong',
                ],
                ['WX', 'NATIVE'] => [
                    'ali_qr_code' => 'https://haipay.unit.test/ali-wrong',
                    'wc_qr_code' => 'https://haipay.unit.test/wx-exact',
                    'uniqr_qr_code' => 'https://haipay.unit.test/union-wrong',
                ],
                ['UNIONQR', 'NATIVE'] => [
                    'ali_qr_code' => 'https://haipay.unit.test/ali-wrong',
                    'wc_qr_code' => 'https://haipay.unit.test/wx-wrong',
                    'uniqr_qr_code' => 'https://haipay.unit.test/union-exact',
                ],
                ['ALI', 'JSAPI'] => ['ali_trade_no' => 'ALI-TRADE-UNIT'],
                ['WX', 'JSAPI'] => ['wc_pay_data' => json_encode([
                    'appId' => (string) ($request['appid'] ?? ''),
                    'timeStamp' => '1784262645',
                    'nonceStr' => 'haipay-unit',
                    'package' => 'prepay_id=HAIPAY-UNIT',
                    'signType' => 'MD5',
                    'paySign' => 'UNIT-SIGN',
                ], JSON_UNESCAPED_SLASHES)],
                default => throw new \RuntimeException('未覆盖的海科产品报文'),
            };

            return $this->haipayPaymentResponse($request, $extra);
        });
        $plugin = $this->haipayPlugin($client);
        $this->assertTrue($plugin instanceof PaymentIdentityRequirementInterface, '海科 JSAPI 必须实现身份需求接口');

        $aliIdentity = $plugin->identityRequirement($this->haipayOrder('alipay', 'alipay', ['sub_openid' => 'WRONG-SCOPE']));
        $mpIdentity = $plugin->identityRequirement($this->haipayOrder('wxpay', 'wechat', ['buyer_id' => 'WRONG-SCOPE']));
        $miniIdentity = $plugin->identityRequirement($this->haipayOrder('wxpay', 'mobile', ['method' => 'mini', 'sub_openid' => 'MP-ONLY']));
        $this->assertSame('buyer_id', (string) ($aliIdentity['identity_field'] ?? ''), '支付宝身份必须精确要求 buyer_id');
        $this->assertSame('openid', (string) ($mpIdentity['identity_field'] ?? ''), '微信公众号身份必须要求公众号 openid');
        $this->assertSame('mini_openid', (string) ($miniIdentity['identity_field'] ?? ''), '微信小程序不得回退使用公众号 openid');

        $aliQr = $plugin->pay($this->haipayOrder('alipay', 'pc'));
        $wxQr = $plugin->pay($this->haipayOrder('wxpay', 'pc'));
        $unionQr = $plugin->pay($this->haipayOrder('bank', 'pc'));
        $aliJs = $plugin->pay($this->haipayOrder('alipay', 'alipay', ['buyer_id' => 'ALI-BUYER-UNIT']));
        $wxJs = $plugin->pay($this->haipayOrder('wxpay', 'wechat', ['openid' => 'WX-MP-UNIT']));
        $miniJs = $plugin->pay($this->haipayOrder('wxpay', 'mobile', [
            'method' => 'mini',
            'mini_openid' => 'WX-MINI-UNIT',
            'mini_app_id' => 'wx-haipay-mini',
        ], 'P-HAIPAY-WX-MINI'));
        $barcode = $plugin->pay($this->haipayOrder('alipay', 'pc', ['auth_code' => '281234567890123456'], 'P-HAIPAY-BARCODE'));

        $this->assertSame('https://haipay.unit.test/ali-exact', (string) $aliQr['presentation']['pay_params']['qrcode'], '支付宝二维码只能读取 ali_qr_code');
        $this->assertSame('https://haipay.unit.test/wx-exact', (string) $wxQr['presentation']['pay_params']['qrcode'], '微信二维码只能读取 wc_qr_code');
        $this->assertSame('https://haipay.unit.test/union-exact', (string) $unionQr['presentation']['pay_params']['qrcode'], '银联二维码只能读取 uniqr_qr_code');
        $this->assertSame('ALI_JSAPI', (string) $aliJs['pay_product'], '支付宝环境必须选择 ALI_JSAPI');
        $this->assertSame('WX_JSAPI', (string) $wxJs['pay_product'], '微信环境必须选择 WX_JSAPI');
        $this->assertSame('wx-haipay-mp', (string) $wxJs['presentation']['pay_params']['appId'], '公众号 JSAPI 必须使用公众号 AppID');
        $this->assertSame('wx-haipay-mini', (string) $miniJs['presentation']['pay_params']['appId'], '小程序 JSAPI 必须使用小程序 AppID');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $barcode['status'], '付款码 trade_status=1 必须明确映射成功');
        foreach ([$aliQr, $wxQr, $unionQr, $aliJs, $wxJs, $miniJs, $barcode] as $result) {
            $this->assertFalse(array_key_exists('raw_data', $result), '海科标准支付结果不得返回 raw_data');
            $this->assertFalse(array_key_exists('currency', $result), '海科标准支付结果不得新增 currency');
            $this->assertFalse(array_key_exists('channel_order_no', $result), '海科渠道订单字段必须使用 chan_order_no');
            $this->assertFalse(array_key_exists('channel_trade_no', $result), '海科渠道流水字段必须使用 chan_trade_no');
            $payParams = (array) (($result['presentation'] ?? [])['pay_params'] ?? []);
            $this->assertFalse(array_key_exists('raw', $payParams), '海科承接参数不得保存完整渠道响应');
        }

        $aliJsCall = $client->calls[3]['data'];
        $wxJsCall = $client->calls[4]['data'];
        $miniCall = $client->calls[5]['data'];
        $barcodeCall = $client->calls[6];
        $this->assertSame('ALI-BUYER-UNIT', (string) $aliJsCall['openid'], '支付宝 buyer_id 必须映射到海科 openid');
        $this->assertFalse(array_key_exists('buyer_id', $aliJsCall), '海科上游报文不得混入未定义 buyer_id 字段');
        $this->assertSame('WX-MP-UNIT', (string) $wxJsCall['openid'], '公众号支付只能发送公众号 openid');
        $this->assertSame('WX-MINI-UNIT', (string) $miniCall['openid'], '小程序支付只能发送 mini_openid');
        $this->assertSame('/api/v2/pay/passive-pay', (string) $barcodeCall['path'], 'auth_code 必须固定选择 passive-pay');
        $this->assertSame('281234567890123456', (string) $barcodeCall['data']['auth_code'], '付款码只允许读取明确 auth_code 字段');

        $missingQrClient = new HaipayUnitClient(function (string $path, array $request): array {
            return $this->haipayPaymentResponse($request, [
                'wc_qr_code' => 'https://haipay.unit.test/wrong-candidate',
                'uniqr_qr_code' => 'https://haipay.unit.test/wrong-candidate-2',
            ]);
        });
        $missingQr = $this->haipayPlugin($missingQrClient);
        $this->assertThrowsClass(
            fn () => $missingQr->pay($this->haipayOrder('alipay', 'pc')),
            PaymentUncertainException::class,
            '所选产品缺少二维码字段时不得遍历候选字段或降级'
        );
        $this->assertSame(1, count($missingQrClient->calls), '二维码字段缺失后不得切换产品重复下单');

        $disabledClient = new HaipayUnitClient(fn (): array => []);
        $disabled = $this->haipayPlugin($disabledClient, ['enabled_products' => ['ALI']]);
        $this->assertThrows(
            fn () => $disabled->pay($this->haipayOrder('alipay', 'pc', ['auth_code' => '281234567890123456'])),
            '付款码产品未开通时不得降级成扫码产品'
        );
        $this->assertSame(0, count($disabledClient->calls), '付款码开关拒绝必须发生在请求上游之前');
    }

    /**
     * 海科付款码四态、唯一查单标识、关单/撤销语义及退款状态。
     */
    private function testHaipayPassiveQueryCloseRefund(): void
    {
        foreach ([
            '1' => PaymentPluginStatusConstant::SUCCESS,
            '3' => PaymentPluginStatusConstant::PENDING,
        ] as $tradeStatus => $expectedStatus) {
            $client = new HaipayUnitClient(fn (string $path, array $request): array => $this->haipayPaymentResponse($request, [
                'pay_type' => 'ALI',
                'trade_status' => $tradeStatus,
            ]));
            $result = $this->haipayPlugin($client)->pay($this->haipayOrder(
                'alipay',
                'pc',
                ['auth_code' => '281234567890123456'],
                'P-HAIPAY-PASSIVE-' . $tradeStatus
            ));
            $this->assertSame($expectedStatus, (string) $result['status'], '海科付款码状态映射错误');
            if ($tradeStatus === '3') {
                $this->assertSame('paymentPending', (string) $result['presentation']['pay_params']['_page'], '付款码处理中必须使用标准等待组件');
            }
        }

        $failed = $this->haipayPlugin(new HaipayUnitClient(fn (string $path, array $request): array => $this->haipayPaymentResponse($request, [
            'pay_type' => 'ALI',
            'trade_status' => '2',
        ])));
        $this->assertThrowsClass(
            fn () => $failed->pay($this->haipayOrder('alipay', 'pc', ['auth_code' => '281234567890123456'])),
            PaymentDefinitiveException::class,
            '海科付款码明确失败不得返回等待或二维码'
        );

        $timeoutClient = new HaipayUnitClient(fn (): HaipaySdkException => new HaipaySdkException(
            'transport timeout auth_code=281234567890123456 accesskey=secret',
            true,
            'TRANSPORT_ERROR'
        ));
        try {
            $this->haipayPlugin($timeoutClient)->pay($this->haipayOrder(
                'alipay',
                'pc',
                ['auth_code' => '281234567890123456']
            ));
            throw new \RuntimeException('海科付款码超时必须抛结果不确定异常');
        } catch (PaymentUncertainException $e) {
            $this->assertFalse(str_contains($e->getMessage(), '281234567890123456'), '异常不得泄露完整 auth_code');
            $this->assertFalse(str_contains(strtolower($e->getMessage()), 'accesskey'), '异常不得泄露 accesskey');
        }
        $this->assertSame(1, count($timeoutClient->calls), '付款码超时不得重复下单或切换扫码产品');

        $queryStates = ['1', '3', '2', '9'];
        $refundStates = ['1', '3', '2', '9'];
        $stateClient = new HaipayUnitClient(function (string $path, array $request) use (&$queryStates, &$refundStates): array {
            if ($path === '/api/v2/pay/order-query') {
                return [
                    'agent_no' => 'AGENT-HAIPAY',
                    'merch_no' => 'MCH-HAIPAY',
                    'pay_type' => 'ALI',
                    'pay_mode' => 'BARPAY',
                    'out_trade_no' => 'P-HAIPAY-STATE',
                    'trade_no' => 'H-HAIPAY-STATE',
                    'order_amount' => '1.00',
                    'trade_status' => array_shift($queryStates) ?? '9',
                ];
            }
            if ($path === '/api/v2/pay/close-order') {
                return [
                    'out_trade_no' => 'P-HAIPAY-STATE',
                    'trade_no' => 'H-HAIPAY-STATE',
                ];
            }
            if ($path === '/api/v2/pay/refund') {
                return [
                    'merch_no' => 'MCH-HAIPAY',
                    'trade_no' => 'H-HAIPAY-STATE',
                    'out_refund_no' => (string) $request['out_refund_no'],
                    'refund_no' => 'HR-HAIPAY-STATE',
                    'refund_amount' => (string) $request['refund_amount'],
                    'pay_type' => 'ALI',
                    'trade_status' => array_shift($refundStates) ?? '9',
                ];
            }
            throw new \RuntimeException('未覆盖的海科状态接口');
        });
        $plugin = $this->haipayPlugin($stateClient);
        $stateOrder = $this->haipayStateOrder();
        foreach ([
            PaymentPluginStatusConstant::SUCCESS,
            PaymentPluginStatusConstant::PENDING,
            PaymentPluginStatusConstant::FAILED,
            PaymentPluginStatusConstant::UNKNOWN,
        ] as $expected) {
            $query = $plugin->query($stateOrder);
            $this->assertSame($expected, (string) $query['status'], '海科查单 trade_status 必须精确映射');
            PaymentPluginQueryResultValidator::make($query)->withScene('query_result')->validate();
        }
        $queryCall = $stateClient->calls[0]['data'];
        $this->assertSame('H-HAIPAY-STATE', (string) $queryCall['trade_no'], '海科查单必须优先且固定使用 trade_no');
        $this->assertFalse(array_key_exists('out_trade_no', $queryCall), '海科查单不得同时发送候选 out_trade_no');

        $normalClose = $plugin->close(array_replace($stateOrder, ['pay_product' => 'ALI']));
        $passiveClose = $plugin->close($stateOrder);
        $this->assertSame('关单成功', (string) $normalClose['message'], '普通主扫必须使用关单语义');
        $this->assertSame('付款码撤销成功', (string) $passiveClose['message'], '付款码必须使用撤销语义');
        $closeCalls = array_values(array_filter($stateClient->calls, static fn (array $call): bool => $call['path'] === '/api/v2/pay/close-order'));
        $this->assertSame($closeCalls[0]['data'], $closeCalls[1]['data'], '关单与付款码撤销必须稳定复用同一海科 trade_no');
        $this->assertThrowsClass(
            fn () => $plugin->close(array_replace($stateOrder, ['pay_type_code' => 'bank', 'pay_product' => 'UNIONQR'])),
            UnsupportedPaymentOperationException::class,
            '当前协议只支持微信支付宝关单，不得猜测银联关闭能力'
        );

        $refundOrder = $stateOrder + ['refund_no' => 'R-HAIPAY-STATE', 'refund_amount' => 50];
        $refundSuccess = $plugin->refund($refundOrder);
        $refundPending = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $refundSuccess['status'], '海科退款状态 1 必须成功');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $refundPending['status'], '海科退款状态 3 必须处理中');
        $this->assertSame('HR-HAIPAY-STATE', (string) $refundSuccess['chan_refund_no'], '海科退款必须返回 refund_no');
        $this->assertThrowsClass(fn () => $plugin->refund($refundOrder), PaymentDefinitiveException::class, '海科退款状态 2 必须明确失败');
        $refundUnknown = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::UNKNOWN, (string) $refundUnknown['status'], '海科未知退款状态不得猜测');
        PaymentPluginRefundResultValidator::make($refundSuccess)->withScene('refund_result')->validate();
        PaymentPluginRefundResultValidator::make($refundPending)->withScene('refund_result')->validate();
        PaymentPluginRefundResultValidator::make($refundUnknown)->withScene('refund_result')->validate();
    }

    /**
     * 海科通知错签、错单、错金额、错状态与 trade_no 精确映射。
     */
    private function testHaipayNotifyValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-HAIPAY-NOTIFY',
            'pay_amount' => 100,
            'channel_id' => 77,
            'channel_order_no' => 'P-HAIPAY-NOTIFY',
            'channel_trade_no' => 'H-HAIPAY-NOTIFY',
            'ext_json' => [
                'payment_context' => [
                    'pay_type' => 'alipay',
                    'pay_product' => 'ALI',
                    'pay_action' => 'pre-pay',
                ],
            ],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder)
            {
            }

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $client = new HaipayUnitClient(fn (): array => []);
        $plugin = $this->haipayPlugin($client, [], $repository);
        $payload = $this->haipayNotifyPayload();
        $notify = $plugin->notify($this->rawJsonRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $notify['status'], '海科成功通知状态映射错误');
        $this->assertSame(100, (int) $notify['paid_amount'], '海科通知金额必须转为整数分');
        $this->assertSame('H-HAIPAY-NOTIFY', (string) $notify['chan_trade_no'], '海科 chan_trade_no 必须精确使用 trade_no');
        $this->assertFalse(str_contains(json_encode($notify), 'BANK-HAIPAY-NOTIFY'), 'bank_trade_no 不得冒充海科 trade_no');
        PaymentPluginNotifyResultValidator::make($notify)->withScene('notify_result')->validate();
        $this->assertSame('{"return_code":"SUCCESS"}', $plugin->notifySuccess(), '海科成功 ACK 不符合协议');
        $this->assertSame('{"return_code":"FAIL","return_msg":"FAIL"}', $plugin->notifyFail(), '海科失败 ACK 不符合协议');

        $badSign = $this->haipayPlugin(new HaipayUnitClient(fn (): array => [], false), [], $repository);
        $this->assertThrows(fn () => $badSign->notify($this->rawJsonRequest($payload)), '海科错签通知必须拒绝');
        $wrongOrder = array_replace($payload, ['out_trade_no' => 'P-HAIPAY-WRONG']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($wrongOrder)), '海科错单通知必须拒绝');
        $wrongAmount = array_replace($payload, ['order_amount' => '1.01']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($wrongAmount)), '海科错金额通知必须拒绝');
        $wrongStatus = array_replace($payload, ['trade_status' => '3']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($wrongStatus)), '海科非成功通知不得推进成功');
        $wrongMerchant = array_replace($payload, ['merch_no' => 'MCH-HAIPAY-WRONG']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($wrongMerchant)), '海科错商户通知必须拒绝');
        $wrongTrade = array_replace($payload, ['trade_no' => 'H-HAIPAY-WRONG']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($wrongTrade)), '海科 trade_no 与本地记录不一致必须拒绝');
        $bankOnly = $payload;
        unset($bankOnly['trade_no']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($bankOnly)), '海科通知不得以 bank_trade_no 候选替代 trade_no');
        $missingBank = $payload;
        unset($missingBank['bank_trade_no']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($missingBank)), '当前通知协议要求 bank_trade_no 时不得静默忽略');
    }

    /**
     * 付呗公共参数、MD5 签名、JSON 请求和凭据级别。
     */
    private function testFubeiSdkSignatureAndCredentials(): void
    {
        $client = new FubeiClient([
            'vendor_sn' => 'VENDOR-001',
            'app_secret' => 'secret-001',
            'api_gateway' => 'https://fubei.unit.test/gateway',
        ]);
        $vector = [
            'vendor_sn' => 'VENDOR-001',
            'method' => 'fbpay.order.query',
            'format' => 'json',
            'sign_method' => 'md5',
            'nonce' => 'ABC123',
            'version' => '1.0',
            'biz_content' => '{"merchant_order_sn":"P-FUBEI-001"}',
        ];
        $expected = strtoupper(md5(
            'biz_content={"merchant_order_sn":"P-FUBEI-001"}'
            . '&format=json&method=fbpay.order.query&nonce=ABC123&sign_method=md5'
            . '&vendor_sn=VENDOR-001&version=1.0secret-001'
        ));
        $this->assertSame($expected, $client->sign($vector), '付呗 ASCII 排序或密钥拼接签名不正确');
        $this->assertTrue(
            $client->verify($vector + ['sign' => strtolower($expected)]),
            '付呗回调 MD5 十六进制验签应兼容大小写'
        );
        $this->assertFalse(
            $client->verify(array_replace($vector, ['nonce' => 'CHANGED']) + ['sign' => $expected]),
            '付呗签名参数被篡改后必须失败'
        );

        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'result_code' => 200,
                'result_message' => 'success',
                'data' => ['order_sn' => 'FUBEI-ORDER-001'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $stack]));
        $response = $client->execute('fbpay.order.query', ['merchant_order_sn' => 'P-FUBEI-001']);
        $this->assertSame('FUBEI-ORDER-001', (string) $response['order_sn'], '付呗 SDK 应返回成功响应 data');
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertTrue(is_array($payload), '付呗请求必须是 JSON 对象');
        $this->assertSame('VENDOR-001', (string) ($payload['vendor_sn'] ?? ''), '服务商接入必须发送 vendor_sn');
        $this->assertFalse(array_key_exists('app_id', $payload), '服务商接入不能同时发送 app_id');
        $this->assertSame(
            'application/json; charset=utf-8',
            strtolower($history[0]['request']->getHeaderLine('Content-Type')),
            '付呗请求 Content-Type 不正确'
        );
        $sentSign = (string) ($payload['sign'] ?? '');
        $this->assertSame($client->sign($payload), $sentSign, '付呗实际请求签名不正确');
    }

    /**
     * 付呗产品选择、产品开关、公众号作用域和支付宝/微信身份字段。
     */
    private function testFubeiProductIdentityAndRequests(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $client = new FubeiUnitClient(function (string $method, array $data, int $index): array {
            if ($method === 'fbpay.order.wap.create') {
                return ['order_sn' => 'FUBEI-H5-' . $index, 'html' => 'https://pay.fubei.unit.test/h5/' . $index];
            }
            if ((string) ($data['pay_type'] ?? '') === 'wxpay') {
                return [
                    'order_sn' => 'FUBEI-WX-' . $index,
                    'sign_package' => [
                        'appId' => (string) ($data['sub_appid'] ?? ''),
                        'timeStamp' => '1784174400',
                        'nonceStr' => 'nonce-' . $index,
                        'package' => 'prepay_id=wx-prepay-' . $index,
                        'signType' => 'MD5',
                        'paySign' => 'pay-sign-' . $index,
                    ],
                ];
            }

            return ['order_sn' => 'FUBEI-ALI-' . $index, 'prepay_id' => 'ALI-PREPAY-' . $index];
        });
        $plugin = $this->fubeiPlugin($client, [
            'enabled_products' => ['alipay_h5', 'alipay_jsapi', 'wxpay_jsapi'],
            'alipay_oauth_app_id' => '2026000000000001',
            'alipay_oauth_private_key' => $pair['private_key'],
            'alipay_oauth_public_key' => $pair['public_key'],
        ]);

        $this->assertSame(['alipay', 'wxpay'], $plugin->getEnabledPayTypes(), '付呗不得继续声明无现行证据的 bank 支付方式');
        $schema = [];
        foreach ($plugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $productValues = array_map(
            static fn (array $option): string => (string) ($option['value'] ?? ''),
            (array) ($schema['enabled_products']['options'] ?? [])
        );
        $this->assertFalse(in_array('bank_scan', $productValues, true), '云闪付旧扫码能力不得出现在可启用产品中');

        $miniOnly = $this->fubeiOrder('wxpay', 'wechat', ['mini_openid' => 'mini-openid-must-not-be-used']);
        $requirement = $plugin->identityRequirement($miniOnly);
        $this->assertSame('openid', (string) ($requirement['identity_field'] ?? ''), 'mini_openid 不能满足付呗公众号身份需求');
        $before = count($client->calls);
        $this->assertThrows(fn () => $plugin->pay($miniOnly), '付呗公众号支付不得读取 mini_openid 请求上游');
        $this->assertSame($before, count($client->calls), '缺少公众号 openid 时不得请求付呗');

        $wxOrder = $this->fubeiOrder('wxpay', 'wechat', ['sub_openid' => 'wx-openid-001']);
        $this->assertSame(null, $plugin->identityRequirement($wxOrder), '已有公众号 openid 时不应重复授权');
        $wxResult = $plugin->pay($wxOrder);
        $wxCall = $client->calls[0];
        $this->assertSame('wxpay_jsapi', (string) $wxResult['pay_product'], '微信环境必须选择微信公众号 JSAPI');
        $this->assertSame('02', (string) $wxCall['data']['pay_way'], '微信公众号 pay_way 必须为 02');
        $this->assertSame('wx-openid-001', (string) $wxCall['data']['user_id'], '微信公众号应使用公众号 openid');
        $this->assertSame('wx-fubei-mp-app', (string) $wxCall['data']['sub_appid'], '微信公众号 sub_appid 必须来自通道配置');
        $this->assertSame('1.00', (string) $wxCall['data']['total_amount'], '付呗上游金额必须在 SDK 边界使用元字符串');
        $this->assertFalse(
            in_array('sign_package', (array) ($wxResult['presentation']['pay_params']['raw']['response_fields'] ?? []), true),
            'pay_params.raw 摘要不能保留 JSAPI 签名包字段'
        );

        $wrongScope = $this->fubeiOrder('wxpay', 'wechat', [
            'openid' => 'wx-openid-002',
            'sub_appid' => 'wx-wrong-app',
        ]);
        $before = count($client->calls);
        $this->assertThrows(fn () => $plugin->pay($wrongScope), '付呗必须拒绝错误公众号 AppID 作用域');
        $this->assertSame($before, count($client->calls), 'AppID 作用域错误时不得请求付呗');

        $alipayOrder = $this->fubeiOrder('alipay', 'alipay', ['buyer_open_id' => 'ali-open-id-001']);
        $this->assertSame(null, $plugin->identityRequirement($alipayOrder), '已有 buyer_open_id 时不应重复授权');
        $alipayResult = $plugin->pay($alipayOrder);
        $aliCall = $client->calls[1];
        $this->assertSame('alipay_jsapi', (string) $alipayResult['pay_product'], '支付宝环境必须优先生活号/JSAPI');
        $this->assertSame('ali-open-id-001', (string) $aliCall['data']['user_id'], '支付宝 JSAPI 必须使用 buyer_id/open_id');
        $this->assertSame('ALI-PREPAY-2', (string) $alipayResult['presentation']['pay_params']['tradeNO'], '支付宝 JSAPI tradeNO 不正确');

        $h5Result = $plugin->pay($this->fubeiOrder('alipay', 'mobile'));
        $this->assertSame('alipay_h5', (string) $h5Result['pay_product'], '移动浏览器应选择显式启用的支付宝 H5');
        $this->assertSame('fbpay.order.wap.create', $client->calls[2]['method'], '支付宝 H5 应调用彩虹有证据的 WAP 接口');

        $before = count($client->calls);
        $this->assertThrows(
            fn () => $plugin->pay($this->fubeiOrder('bank', 'pc')),
            '无当前证据的云闪付扫码必须明确不支持'
        );
        $this->assertSame($before, count($client->calls), '云闪付扫码被禁用时不得请求上游');

        $identityService = (new ReflectionClass(\app\service\payment\identity\PaymentIdentityService::class))
            ->newInstanceWithoutConstructor();
        $buildAuthUrl = $this->privateMethod(\app\service\payment\identity\PaymentIdentityService::class, 'buildAuthUrl');
        $alipayRequirement = $plugin->identityRequirement($this->fubeiOrder('alipay', 'alipay'));
        $alipayAuthUrl = (string) $buildAuthUrl->invoke($identityService, 'resume-token', $alipayRequirement);
        $this->assertTrue(str_starts_with($alipayAuthUrl, 'https://openauth.alipay.com/'), '支付宝生活号身份流程必须生成官方网页授权地址');
        $this->assertTrue(str_contains($alipayAuthUrl, 'identity%2Falipay-callback'), '支付宝身份授权必须回到 MPAY 统一身份服务');
        $wechatAuthUrl = (string) $buildAuthUrl->invoke($identityService, 'resume-token', $requirement);
        $this->assertTrue(str_starts_with($wechatAuthUrl, 'https://open.weixin.qq.com/'), '微信公众号身份流程必须生成公众号网页授权地址');

        $wechatContext = [
            'input' => ['ext_json' => []],
            'requirement' => $requirement,
        ];
        $this->assertThrows(
            fn () => $identityService->restoreInput($wechatContext, ['mini_openid' => 'mini-cannot-fallback']),
            'MPAY 身份服务不得把 mini_openid 回填成公众号 openid'
        );
        $restoredWechat = $identityService->restoreInput($wechatContext, ['openid' => 'wx-openid-restored']);
        $this->assertSame(
            'wx-openid-restored',
            (string) $restoredWechat['ext_json']['payment']['openid'],
            'MPAY 身份服务应把公众号 openid 回填到 extra.payment'
        );
        $restoredAlipay = $identityService->restoreInput([
            'input' => ['ext_json' => []],
            'requirement' => $alipayRequirement,
        ], ['buyer_open_id' => 'ali-open-restored']);
        $this->assertSame(
            'ali-open-restored',
            (string) $restoredAlipay['ext_json']['payment']['buyer_id'],
            '支付宝 open_id 应按需求字段回填为付呗可消费的 buyer 身份'
        );

        $wxOnlyClient = new FubeiUnitClient(fn (): array => ['order_sn' => 'UNUSED']);
        $wxOnly = $this->fubeiPlugin($wxOnlyClient, ['enabled_products' => ['wxpay_jsapi']]);
        $this->assertThrows(
            fn () => $wxOnly->pay($this->fubeiOrder('alipay', 'alipay', ['buyer_id' => '2088'])),
            '未勾选支付宝产品时必须在本地拒绝'
        );
        $this->assertSame(0, count($wxOnlyClient->calls), '未勾选产品不得请求付呗');
    }

    /**
     * 付呗回调验签后的订单、通道、商户、门店、产品、分金额和重放校验。
     */
    private function testFubeiNotifyBusinessValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-FUBEI-NOTIFY-A',
            'pay_amount' => 100,
            'channel_id' => 12,
            'channel_order_no' => 'FUBEI-ORDER-A',
            'channel_trade_no' => '',
            'ext_json' => ['payment_context' => ['pay_product' => 'wxpay_jsapi']],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}
            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $client = new FubeiUnitClient(fn (): array => []);
        $plugin = $this->fubeiPlugin($client, ['enabled_products' => ['wxpay_jsapi']], $repository);
        $data = [
            'merchant_order_sn' => 'P-FUBEI-NOTIFY-A',
            'order_sn' => 'FUBEI-ORDER-A',
            'channel_order_sn' => 'WX-TRANSACTION-A',
            'ins_order_sn' => 'INS-A',
            'order_status' => 'SUCCESS',
            'pay_type' => 'wxpay',
            'total_amount' => '1.00',
            'uid' => 'FUBEI-MERCHANT-001',
            'store_id' => 'FUBEI-STORE-001',
            'finish_time' => '20260716123045',
        ];
        $payload = $this->fubeiSignedNotify($client, $data);
        $result = $plugin->notify($this->rawFormRequest($payload));
        $repeat = $plugin->notify($this->rawFormRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '付呗 SUCCESS 通知应归一为成功');
        $this->assertSame('P-FUBEI-NOTIFY-A', (string) $result['pay_no'], '付呗通知必须返回真实 pay_no 供 URL 关联');
        $this->assertSame(100, (int) $result['paid_amount'], '付呗通知必须返回整数分供核心二次校验');
        $this->assertSame('WX-TRANSACTION-A', (string) $result['chan_trade_no'], '付呗通知渠道交易号不正确');
        $this->assertSame($result, $repeat, '相同付呗通知重放应得到稳定结果并交由生命周期幂等处理');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        foreach ([
            ['merchant_order_sn', 'P-FUBEI-WRONG', '错订单'],
            ['total_amount', '1.01', '错金额'],
            ['uid', 'FUBEI-MERCHANT-WRONG', '错商户'],
            ['store_id', 'FUBEI-STORE-WRONG', '错门店'],
            ['pay_type', 'alipay', '错支付产品'],
            ['order_status', 'USERPAYING', '非成功状态'],
            ['currency', 'USD', '错币种'],
            ['order_sn', 'FUBEI-ORDER-WRONG', '错付呗订单号'],
        ] as [$field, $value, $label]) {
            $changed = array_replace($data, [$field => $value]);
            $this->assertThrows(
                fn () => $plugin->notify($this->rawFormRequest($this->fubeiSignedNotify($client, $changed))),
                '合法重新签名的付呗' . $label . '通知必须拒绝'
            );
        }

        $badSignature = $payload;
        $badSignature['sign'] = str_repeat('0', 32);
        $this->assertThrows(
            fn () => $plugin->notify($this->rawFormRequest($badSignature)),
            '付呗签名错误通知必须拒绝'
        );
        $payOrder->channel_trade_no = 'WX-TRANSACTION-OTHER';
        $this->assertThrows(
            fn () => $plugin->notify($this->rawFormRequest($payload)),
            '付呗重复通知渠道交易号与已保存交易号不一致必须拒绝'
        );
        $payOrder->channel_trade_no = '';

        // URL 指向 B 单、通知内容仍为 A 单时，核心回调服务必须返回失败应答且不推进状态。
        $urlOrder = clone $payOrder;
        $urlOrder->pay_no = 'P-FUBEI-NOTIFY-B';
        $coreRepository = new class($urlOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}
            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $manager = new class($plugin) extends \app\service\payment\runtime\PaymentPluginManager {
            public function __construct(private FubeiApiPayment $plugin) {}
            public function createByPayOrder(PayOrder $payOrder, bool $allowDisabled = true): \app\common\interface\PaymentInterface & \app\common\interface\PayPluginInterface
            {
                return $this->plugin;
            }
        };
        $notifyService = new class extends NotifyService {
            /** @var array<int, array<string,mixed>> */
            public array $records = [];
            public function __construct() {}
            public function recordPayCallback(array $input): ?\app\model\admin\PayCallbackLog
            {
                $this->records[] = $input;
                return null;
            }
        };
        $callbackService = new PayOrderCallbackService(
            $notifyService,
            $manager,
            new \app\repository\payment\config\PaymentChannelRepository(),
            $coreRepository,
            (new ReflectionClass(\app\service\payment\order\PayOrderLifecycleService::class))->newInstanceWithoutConstructor()
        );
        $this->assertSame(
            'fail',
            $callbackService->handlePluginCallback('P-FUBEI-NOTIFY-B', $this->rawFormRequest($payload)),
            '付呗 A 单通知投递到 B 单回调 URL 必须失败'
        );
        $this->assertSame(1, count($notifyService->records), 'A 通知投 B URL 失败仍应留一条回调审计记录');
        $this->assertSame(
            NotifyConstant::VERIFY_STATUS_SUCCESS,
            (int) $notifyService->records[0]['verify_status'],
            '插件验签通过后的支付单号不一致应记录为验签成功'
        );
        $this->assertSame(
            NotifyConstant::PROCESS_STATUS_FAILED,
            (int) $notifyService->records[0]['process_status'],
            '插件验签通过后的支付单号不一致应记录为业务处理失败'
        );
    }

    /**
     * 付呗查单状态、关单、退款状态和金额关联。
     */
    private function testFubeiQueryCloseRefund(): void
    {
        $queryStatuses = ['SUCCESS', 'USERPAYING', 'CLOSED'];
        $refundStatuses = ['REFUND_PROCESSING', 'REFUND_SUCCESS', 'REFUND_FAIL'];
        $client = new FubeiUnitClient(function (string $method, array $data) use (&$queryStatuses, &$refundStatuses): array {
            if ($method === 'fbpay.order.query') {
                $status = array_shift($queryStatuses) ?? 'CLOSED';
                return [
                    'merchant_order_sn' => 'P-FUBEI-STATE',
                    'order_sn' => 'FUBEI-STATE-ORDER',
                    'channel_order_sn' => $status === 'SUCCESS' ? 'CHANNEL-STATE-ORDER' : '',
                    'merchant_id' => 'FUBEI-MERCHANT-001',
                    'store_id' => 'FUBEI-STORE-001',
                    'pay_type' => 'wxpay',
                    'total_amount' => '1.00',
                    'order_status' => $status,
                    'finish_time' => $status === 'SUCCESS' ? '20260716123045' : '',
                ];
            }
            if ($method === 'fbpay.order.close') {
                return ['merchant_order_sn' => 'P-FUBEI-STATE', 'order_sn' => 'FUBEI-STATE-ORDER'];
            }
            $status = array_shift($refundStatuses) ?? 'REFUND_FAIL';
            return [
                'merchant_refund_sn' => 'R-FUBEI-STATE',
                'refund_sn' => 'FUBEI-REFUND-STATE',
                'refund_amount' => '0.50',
                'refund_status' => $status,
                'refund_message' => $status === 'REFUND_FAIL' ? '退款被拒绝' : '',
            ];
        });
        $plugin = $this->fubeiPlugin($client, ['enabled_products' => ['wxpay_jsapi']]);
        $order = [
            'pay_no' => 'P-FUBEI-STATE',
            'amount' => 100,
            'chan_order_no' => 'FUBEI-STATE-ORDER',
            'pay_product' => 'wxpay_jsapi',
        ];
        $success = $plugin->query($order);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], '付呗 SUCCESS 查单映射错误');
        $this->assertSame('CHANNEL-STATE-ORDER', (string) $success['chan_trade_no'], '付呗成功查单必须返回渠道交易号');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->query($order)['status'], '付呗 USERPAYING 应保持 pending');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->query($order)['status'], '付呗 CLOSED 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '付呗明确成功关单响应应映射成功');

        $refundOrder = $order + [
            'refund_no' => 'R-FUBEI-STATE',
            'refund_amount' => 50,
        ];
        $pending = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], 'REFUND_PROCESSING 应保持 pending');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refundOrder)['status'], 'REFUND_SUCCESS 才能确认退款成功');
        $this->assertThrows(fn () => $plugin->refund($refundOrder), 'REFUND_FAIL 必须抛业务异常');

        $refundCalls = array_values(array_filter(
            $client->calls,
            static fn (array $call): bool => $call['method'] === 'fbpay.order.refund'
        ));
        $this->assertSame($refundCalls[0]['data'], $refundCalls[1]['data'], '付呗退款重试必须复用同一退款单号和金额');
        $this->assertSame('0.50', (string) $refundCalls[0]['data']['refund_amount'], '付呗退款金额必须从整数分精确转元');
        $this->assertSame('P-FUBEI-STATE', (string) $refundCalls[0]['data']['merchant_order_sn'], '付呗退款必须关联 MPAY 支付单号');

        $badQueryClient = new FubeiUnitClient(fn (): array => [
            'merchant_order_sn' => 'P-FUBEI-STATE',
            'order_sn' => 'FUBEI-STATE-ORDER',
            'channel_order_sn' => 'CHANNEL-STATE-ORDER',
            'merchant_id' => 'FUBEI-MERCHANT-001',
            'store_id' => 'FUBEI-STORE-001',
            'pay_type' => 'wxpay',
            'total_amount' => '1.01',
            'order_status' => 'SUCCESS',
        ]);
        $badQuery = $this->fubeiPlugin($badQueryClient, ['enabled_products' => ['wxpay_jsapi']]);
        $this->assertThrows(fn () => $badQuery->query($order), '付呗查单金额与本地支付单不一致必须拒绝');
    }

    /**
     * 富友 GBK XML、req 双重编码、RSA-MD5 固定向量、响应验签和环境地址。
     */
    private function testFuiouSdkProtocolVectors(): void
    {
        $keys = $this->fuiouFixedKeyPair();
        $config = [
            'institution_code' => '08A999',
            'merchant_no' => '0002900F9999999',
            'merchant_private_key' => $keys['private_key'],
            'platform_public_key' => $keys['public_key'],
            'sandbox' => true,
            'random_str_factory' => static fn (): string => 'ABCDEF1234567890',
        ];
        $client = new FuiouPayClient($config);
        $vector = [
            'version' => '1.0',
            'ins_cd' => '08A999',
            'mchnt_cd' => '0002900F9999999',
            'term_id' => '88888888',
            'random_str' => 'ABCDEF1234567890',
            'goods_des' => '富友测试订单',
            'mchnt_order_no' => 'FUIOU202607160001',
            'order_amt' => '100',
            'reserved_expire_minute' => '5',
        ];
        $this->assertSame(
            '676f6f64735f6465733db8bbd3d1b2e2cad4b6a9b5a526696e735f63643d303841393939266d63686e745f63643d303030323930304639393939393939266d63686e745f6f726465725f6e6f3d4655494f55323032363037313630303031266f726465725f616d743d3130302672616e646f6d5f7374723d41424344454631323334353637383930267465726d5f69643d38383838383838382676657273696f6e3d312e30',
            bin2hex($client->canonicalSignContent($vector)),
            '富友字段 ASCII 排序、reserved 排除或 GBK 待签名字节不正确'
        );
        $expectedSign = 'E8yVZvAPURAlBIV+65qNz7tYaRO84h4aCFL04TZruGGuYIU7BL9wR/qj8QwqGfTRdrv3I9ZTfy726/c+4FO1ye4QFqhg5xSSbrEp8KJmDurlWivLRkUJDodvHhssSmfZEWF+diF9E41U/DdEDDvEfvSdj+Ufhm00fUF3aT7p3vs=';
        $this->assertSame($expectedSign, $client->sign($vector), '富友 RSA-MD5 固定签名向量不正确');
        $this->assertTrue($client->verify($vector + ['sign' => $expectedSign]), '富友固定签名应通过公钥验签');
        $this->assertFalse(
            $client->verify(array_replace($vector, ['order_amt' => '101']) + ['sign' => $expectedSign]),
            '富友响应字段篡改后必须验签失败'
        );
        $this->assertSame(
            '3c3f786d6c2076657273696f6e3d22312e302220656e636f64696e673d2247424b22207374616e64616c6f6e653d22796573223f3e3c786d6c3e3c676f6f64735f6465733eb8bbd3d1b2e2cad426616d703bb6a9b5a53c2f676f6f64735f6465733e3c2f786d6c3e',
            bin2hex($client->encodeXml(['goods_des' => '富友测试&订单'])),
            '富友 GBK XML 中文或 XML 保留字符转义固定向量不正确'
        );

        $responsePayload = [
            'result_code' => '000000',
            'result_msg' => '成功',
            'ins_cd' => '08A999',
            'mchnt_cd' => '0002900F9999999',
            'term_id' => '88888888',
            'random_str' => 'RESP1234567890',
            'mchnt_order_no' => 'FUIOU202607160001',
            'trans_stat' => 'SUCCESS',
        ];
        $responsePayload['sign'] = $client->sign($responsePayload);
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], urlencode($client->encodeXml($responsePayload))),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $stack]));
        $result = $client->submit(FuiouPayClient::PATH_QUERY, [
            'order_type' => 'ALIPAY',
            'mchnt_order_no' => 'FUIOU202607160001',
        ]);
        $this->assertSame('SUCCESS', (string) $result['trans_stat'], '富友已验签响应应正常返回');
        $request = $history[0]['request'];
        $this->assertSame(
            'https://fundwx.payfuiouo2o.com/commonQuery',
            (string) $request->getUri(),
            '富友当前测试网关地址不正确'
        );
        $this->assertSame(
            'application/x-www-form-urlencoded; charset=GBK',
            $request->getHeaderLine('Content-Type'),
            '富友请求 Content-Type 不正确'
        );
        $body = (string) $request->getBody();
        $this->assertTrue(str_starts_with($body, 'req='), '富友请求必须只有 req 表单字段');
        $encodedOnce = urldecode(substr($body, 4));
        $this->assertTrue(str_starts_with($encodedOnce, '%3C%3Fxml'), '富友 req 必须双重 URL 编码');
        $requestPayload = $client->parseXml(urldecode($encodedOnce));
        $this->assertSame('ALIPAY', (string) $requestPayload['order_type'], '富友实际 XML 缺少业务字段');
        $this->assertTrue($client->verify($requestPayload), '富友实际请求签名必须可验证');

        $production = new FuiouPayClient(array_replace($config, ['sandbox' => false]));
        $this->assertSame('https://spay-mc.fuioupay.com', $production->gatewayUrl(), '富友生产网关地址不正确');
        $custom = new FuiouPayClient(array_replace($config, ['api_base_url' => 'https://fuiou.proxy.test/base/']));
        $this->assertSame('https://fuiou.proxy.test/base', $custom->gatewayUrl(), '富友自定义网关尾斜杠应被规范化');
        $this->assertFalse(
            $client->verify(array_diff_key($responsePayload, ['sign' => true])),
            '富友响应或通知缺少 sign 时必须失败'
        );
        $this->assertThrows(fn () => $client->parseXml('<!DOCTYPE xml><xml/>'), '富友 XML 必须拒绝 DOCTYPE');
        $this->assertContains('030010', FuiouPayClient::MICROPAY_PENDING_CODES, '富友用户支付中代码必须进入查单');
        $this->assertContains('010002', FuiouPayClient::MICROPAY_PENDING_CODES, '富友系统未知代码必须进入查单');
    }

    /**
     * 富友产品开关、支付宝/公众号/小程序身份作用域和各下单接口业务字段。
     */
    private function testFuiouProductIdentityAndPayloads(): void
    {
        $client = new FuiouUnitClient(function (string $path, array $data): array {
            return match ($path) {
                FuiouPayClient::PATH_PRECREATE => [
                    'result_code' => '000000',
                    'qr_code' => 'https://fuiou.unit.test/qr/' . strtolower((string) $data['order_type']),
                    'reserved_fy_order_no' => 'FY-SCAN-001',
                ],
                FuiouPayClient::PATH_WX_PRECREATE => (string) ($data['trade_type'] ?? '') === 'FWC'
                    ? ['result_code' => '000000', 'reserved_transaction_id' => 'ALI-FWC-001']
                    : [
                        'result_code' => '000000',
                        'sdk_appid' => (string) ($data['sub_appid'] ?? ''),
                        'sdk_timestamp' => '1784174400',
                        'sdk_noncestr' => 'fuiou-nonce',
                        'sdk_package' => 'prepay_id=fuiou-prepay',
                        'sdk_signtype' => 'MD5',
                        'sdk_paysign' => 'fuiou-pay-sign',
                    ],
                FuiouPayClient::PATH_MICROPAY => [
                    'result_code' => '000000',
                    'reserved_mchnt_order_no' => (string) $data['mchnt_order_no'],
                    'total_amount' => (string) $data['order_amt'],
                    'transaction_id' => 'FY-BARCODE-001',
                ],
                default => [],
            };
        });
        $plugin = $this->fuiouPlugin($client);

        $schema = [];
        foreach ($plugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $productValues = array_map(
            static fn (array $option): string => (string) ($option['value'] ?? ''),
            (array) ($schema['enabled_products']['options'] ?? [])
        );
        $this->assertSame(
            ['alipay_scan', 'alipay_jsapi', 'wxpay_scan', 'wxpay_mp', 'wxpay_mini', 'bank_scan', 'barcode'],
            $productValues,
            '富友常量、配置表单和运行时产品集合不一致'
        );
        $this->assertSame(
            ['alipay_scan', 'wxpay_scan', 'bank_scan'],
            (array) ($schema['enabled_products']['value'] ?? []),
            '富友默认只能启用不依赖额外身份/终端配置的扫码产品'
        );

        $scan = $plugin->pay($this->fuiouOrder('alipay', 'pc'));
        $scanCall = $client->calls[0];
        $this->assertSame('alipay_scan', (string) $scan['pay_product'], '支付宝 PC 应选择 preCreate 扫码');
        $this->assertSame(FuiouPayClient::PATH_PRECREATE, $scanCall['path'], '支付宝扫码路径错误');
        $this->assertSame('100', (string) $scanCall['data']['order_amt'], '富友下单金额必须是整数分');
        $this->assertSame('5', (string) $scanCall['data']['reserved_expire_minute'], 'preCreate 必须发送有效分钟数');
        $this->assertSame('CNY', (string) $scanCall['data']['curr_type'], '富友下单币种必须为 CNY');
        $this->assertFalse(array_key_exists('raw', (array) $scan['presentation']['pay_params']), '富友展示快照不得保存完整响应');

        $bank = $plugin->pay($this->fuiouOrder('bank', 'pc'));
        $bankCall = $client->calls[1];
        $this->assertSame('UNIONPAY', (string) $bankCall['data']['order_type'], '银联扫码 order_type 错误');
        $this->assertTrue(preg_match('/^\d{30}$/', (string) $bank['chan_order_no']) === 1, '银联渠道订单号必须生成纯数字');

        $aliRequirement = $plugin->identityRequirement($this->fuiouOrder('alipay', 'alipay'));
        $this->assertSame('buyer_id', (string) ($aliRequirement['identity_field'] ?? ''), '支付宝 JSAPI 缺身份必须进入 buyer_id 流程');
        $buyerOpenIdOnly = $this->fuiouOrder('alipay', 'alipay', ['buyer_open_id' => 'ali-open-id-only']);
        $this->assertSame('buyer_id', (string) ($plugin->identityRequirement($buyerOpenIdOnly)['identity_field'] ?? ''), '支付宝 open_id 不能替代 FWC user_id');
        $ali = $plugin->pay($this->fuiouOrder('alipay', 'alipay', ['buyer_id' => '2088000000000001']));
        $aliCall = $client->calls[2];
        $this->assertSame('FWC', (string) $aliCall['data']['trade_type'], '支付宝 JSAPI trade_type 必须是 FWC');
        $this->assertSame('2088000000000001', (string) $aliCall['data']['sub_openid'], '支付宝 user_id 必须放在 sub_openid');
        $this->assertSame('', (string) $aliCall['data']['sub_appid'], '支付宝 FWC 不应发送微信 sub_appid');
        $this->assertSame('ALI-FWC-001', (string) $ali['presentation']['pay_params']['tradeNO'], '支付宝 JSAPI 拉起参数错误');

        $mpRequirement = $plugin->identityRequirement($this->fuiouOrder('wxpay', 'wechat'));
        $this->assertSame('openid', (string) ($mpRequirement['identity_field'] ?? ''), '微信公众号缺身份必须进入 openid 流程');
        $mp = $plugin->pay($this->fuiouOrder('wxpay', 'wechat', ['openid' => 'wx-mp-openid']));
        $mpCall = $client->calls[3];
        $this->assertSame('JSAPI', (string) $mpCall['data']['trade_type'], '微信公众号 trade_type 必须是 JSAPI');
        $this->assertSame('wx-fuiou-mp', (string) $mpCall['data']['sub_appid'], '微信公众号 sub_appid 必须来自配置');
        $this->assertSame('wx-mp-openid', (string) $mpCall['data']['sub_openid'], '微信公众号只能使用公众号 openid');
        $this->assertSame('wxpay_mp', (string) $mp['pay_product'], '微信公众号产品编码错误');

        $miniRequirement = $plugin->identityRequirement($this->fuiouOrder('wxpay', 'mobile', ['is_mini' => true]));
        $this->assertSame('mini_openid', (string) ($miniRequirement['identity_field'] ?? ''), '小程序缺身份必须进入 mini_openid 流程');
        $this->assertSame('mini', (string) ($miniRequirement['product'] ?? ''), 'MPAY 身份服务必须识别 mini 产品');
        $mini = $plugin->pay($this->fuiouOrder('wxpay', 'mobile', ['is_mini' => true, 'mini_openid' => 'wx-mini-openid']));
        $miniCall = $client->calls[4];
        $this->assertSame('LETPAY', (string) $miniCall['data']['trade_type'], '微信小程序 trade_type 必须是 LETPAY');
        $this->assertSame('wx-fuiou-mini', (string) $miniCall['data']['sub_appid'], '小程序 sub_appid 必须来自配置');
        $this->assertSame('wx-mini-openid', (string) $miniCall['data']['sub_openid'], '小程序只能使用 mini_openid');
        $this->assertSame('wxpay_mini', (string) $mini['pay_product'], '微信小程序产品编码错误');

        $before = count($client->calls);
        $this->assertThrows(
            fn () => $plugin->pay($this->fuiouOrder('wxpay', 'wechat', ['openid' => 'wx-mp-openid', 'sub_appid' => 'wx-wrong-app'])),
            '微信身份 AppID 作用域不一致必须拒绝'
        );
        $this->assertSame($before, count($client->calls), 'AppID 作用域错误时不得请求富友');

        $barcode = $plugin->pay($this->fuiouOrder('wxpay', 'pc', ['auth_code' => '130000000000000000']));
        $barcodeCall = $client->calls[5];
        $this->assertSame('barcode', (string) $barcode['pay_product'], 'auth_code 存在时只能选择付款码产品');
        $this->assertSame(FuiouPayClient::PATH_MICROPAY, $barcodeCall['path'], '付款码必须调用 micropay');
        $this->assertFalse(array_key_exists('notify_url', $barcodeCall['data']), 'micropay 不能携带 notify_url');
        $terminal = json_decode((string) $barcodeCall['data']['reserved_terminal_info'], true);
        $this->assertSame('FUIOU-SN-001', (string) ($terminal['serial_num'] ?? ''), 'micropay 必须携带终端序列号');

        $before = count($client->calls);
        $this->assertThrows(
            fn () => $plugin->pay($this->fuiouOrder('alipay', 'pc', ['auth_code' => '130000000000000000'])),
            '微信付款码不能按支付宝订单类型发送'
        );
        $this->assertSame($before, count($client->calls), '付款码不匹配不得请求上游或降级二维码');

        $noMini = $this->fuiouPlugin(new FuiouUnitClient(fn (): array => []), [
            'bindwxa' => false,
            'enabled_products' => ['wxpay_scan'],
        ]);
        $this->assertThrows(
            fn () => $noMini->pay($this->fuiouOrder('wxpay', 'mobile', ['mini_openid' => 'forbidden-mini'])),
            '只有 bindwxa=true 才允许 mini_openid'
        );
        $noBarcodeClient = new FuiouUnitClient(fn (): array => []);
        $noBarcode = $this->fuiouPlugin($noBarcodeClient, ['enabled_products' => ['wxpay_scan']]);
        $this->assertThrows(
            fn () => $noBarcode->pay($this->fuiouOrder('wxpay', 'pc', ['auth_code' => '130000000000000000'])),
            '付款码未开通时不得降级到扫码产品'
        );
        $this->assertSame(0, count($noBarcodeClient->calls), '付款码未开通时不得请求上游');
    }

    /**
     * 富友付款码结果未知时按至少 5 秒间隔查单，成功返回或超时自动撤销。
     */
    private function testFuiouBarcodePendingAndRevoke(): void
    {
        $queryCount = 0;
        $cancelCount = 0;
        $sleeps = [];
        $client = new FuiouUnitClient(function (string $path, array $data) use (&$queryCount, &$cancelCount): array {
            if ($path === FuiouPayClient::PATH_MICROPAY) {
                return ['result_code' => '030010', 'result_msg' => '用户支付中'];
            }
            if ($path === FuiouPayClient::PATH_QUERY) {
                $queryCount++;
                return [
                    'result_code' => '000000',
                    'mchnt_order_no' => (string) $data['mchnt_order_no'],
                    'order_amt' => '100',
                    'trans_stat' => 'NOTPAY',
                ];
            }
            $cancelCount++;
            return [
                'result_code' => '000000',
                'mchnt_order_no' => (string) $data['mchnt_order_no'],
                'recall' => $cancelCount === 1 ? 'Y' : 'N',
            ];
        });
        $plugin = $this->fuiouPlugin($client, [
            'enabled_products' => ['barcode'],
            '_sleep_callback' => static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        ]);
        $this->assertThrows(
            fn () => $plugin->pay($this->fuiouOrder('wxpay', 'pc', ['auth_code' => '130000000000000000'])),
            '付款码六次查单仍未支付应撤销并返回超时'
        );
        $this->assertSame(6, $queryCount, '富友付款码结果未知必须轮询六次');
        $this->assertSame(2, $cancelCount, 'cancelorder recall=Y 时必须重试撤销');
        $this->assertSame([5, 5, 5, 5, 5, 5, 1], $sleeps, '富友查单间隔不得短于官方要求的 5 秒');
        $cancelCalls = array_values(array_filter(
            $client->calls,
            static fn (array $call): bool => $call['path'] === FuiouPayClient::PATH_CANCEL
        ));
        $this->assertSame($cancelCalls[0]['data'], $cancelCalls[1]['data'], '付款码撤销重试必须复用同一 cancel_order_no');
        $this->assertSame(
            ['mchnt_order_no', 'order_type', 'cancel_order_no', 'operator_id'],
            array_keys($cancelCalls[0]['data']),
            'cancelorder 实际业务报文字段不正确'
        );

        $successQuery = 0;
        $successSleeps = [];
        $successClient = new FuiouUnitClient(function (string $path, array $data) use (&$successQuery): array {
            if ($path === FuiouPayClient::PATH_MICROPAY) {
                return ['result_code' => '010002', 'result_msg' => '系统未知'];
            }
            $successQuery++;
            return [
                'result_code' => '000000',
                'mchnt_order_no' => (string) $data['mchnt_order_no'],
                'order_amt' => '100',
                'trans_stat' => $successQuery === 1 ? 'USERPAYING' : 'SUCCESS',
                'transaction_id' => $successQuery === 1 ? '' : 'FY-BARCODE-SUCCESS',
            ];
        });
        $successPlugin = $this->fuiouPlugin($successClient, [
            'enabled_products' => ['barcode'],
            '_sleep_callback' => static function (int $seconds) use (&$successSleeps): void {
                $successSleeps[] = $seconds;
            },
        ]);
        $success = $successPlugin->pay($this->fuiouOrder('wxpay', 'pc', ['auth_code' => '130000000000000000']));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], '付款码查单确认成功应返回同步成功状态');
        $this->assertSame('FY-BARCODE-SUCCESS', (string) $success['chan_trade_no'], '付款码查单成功必须返回渠道交易号');
        $this->assertSame([5, 5], $successSleeps, 'USERPAYING 后必须继续按 5 秒间隔查单');
        $successCancels = array_filter(
            $successClient->calls,
            static fn (array $call): bool => $call['path'] === FuiouPayClient::PATH_CANCEL
        );
        $this->assertSame(0, count($successCancels), '付款码已支付成功不得再撤销');
    }

    /**
     * 富友通知严格校验签名、机构/商户、订单、整数分、币种、状态和渠道交易号。
     */
    private function testFuiouNotifyBusinessValidation(): void
    {
        $channelOrderNo = 'FUIOUNOTIFY001';
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'PFUIOUNOTIFY001',
            'channel_id' => 12,
            'channel_order_no' => $channelOrderNo,
            'pay_amount' => 100,
            'ext_json' => ['payment_context' => ['pay_type' => 'wxpay', 'pay_product' => 'wxpay_mp']],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByReceiptChannelOrder(array $channelIds, string $orderNo, array $columns = ['*']): ?PayOrder
            {
                return in_array(12, $channelIds, true) && $orderNo === (string) $this->payOrder->channel_order_no
                    ? $this->payOrder
                    : null;
            }
        };
        $client = new FuiouUnitClient(fn (): array => []);
        $plugin = $this->fuiouPlugin($client, ['enabled_products' => ['wxpay_scan']], $repository);
        $payload = [
            'result_code' => '000000',
            'result_msg' => '支付成功',
            'ins_cd' => 'INS-TEST',
            'mchnt_cd' => 'MCH-TEST',
            'term_id' => '88888888',
            'random_str' => 'notify-random',
            'order_amt' => '100',
            'curr_type' => 'CNY',
            'transaction_id' => 'FY-NOTIFY-TRADE-001',
            'mchnt_order_no' => $channelOrderNo,
            'order_type' => 'WECHAT',
            'txn_fin_ts' => '20260716123045',
            'sign' => 'valid-sign',
        ];

        $result = $plugin->notify($this->fuiouNotifyRequest($client, $payload));
        $replayed = $plugin->notify($this->fuiouNotifyRequest($client, $payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '富友明确成功通知应映射 success');
        $this->assertSame('PFUIOUNOTIFY001', (string) $result['pay_no'], '富友通知必须返回仓库定位的 MPAY pay_no');
        $this->assertSame(100, (int) $result['paid_amount'], '富友通知金额必须保持整数分');
        $this->assertSame('2026-07-16 12:30:45', (string) $result['paid_at'], '富友完成时间格式化错误');
        $this->assertSame($result, $replayed, '重复富友通知必须得到相同归一结果且插件不得推进订单');

        foreach ([
            ['result_code' => '030010'],
            ['ins_cd' => 'INS-WRONG'],
            ['mchnt_cd' => 'MCH-WRONG'],
            ['order_amt' => '101'],
            ['order_amt' => '1.00'],
            ['curr_type' => 'USD'],
            ['transaction_id' => ''],
            ['mchnt_order_no' => 'FUIOUWRONG001'],
            ['order_type' => 'ALIPAY'],
        ] as $override) {
            $this->assertThrows(
                fn () => $plugin->notify($this->fuiouNotifyRequest($client, array_replace($payload, $override))),
                '富友错单、错金额、错商户、错币种或非成功通知必须拒绝'
            );
        }
        $badSignature = $this->fuiouPlugin(
            new FuiouUnitClient(fn (): array => [], false),
            ['enabled_products' => ['wxpay_scan']],
            $repository
        );
        $this->assertThrows(
            fn () => $badSignature->notify($this->fuiouNotifyRequest($client, $payload)),
            '富友通知验签失败必须拒绝'
        );
    }

    /**
     * 富友 commonQuery、普通 closeorder、付款码 cancelorder 和 commonRefund 接口契约。
     */
    private function testFuiouQueryCloseRefund(): void
    {
        $queryStatuses = ['SUCCESS', 'USERPAYING', 'CLOSED', 'REFUND'];
        $client = new FuiouUnitClient(function (string $path, array $data) use (&$queryStatuses): array {
            if ($path === FuiouPayClient::PATH_QUERY) {
                $status = array_shift($queryStatuses) ?? 'NOTPAY';
                return [
                    'result_code' => '000000',
                    'mchnt_cd' => 'MCH-TEST',
                    'mchnt_order_no' => (string) $data['mchnt_order_no'],
                    'order_amt' => '100',
                    'trans_stat' => $status,
                    'transaction_id' => $status === 'SUCCESS' ? 'FY-QUERY-TRADE' : '',
                    'txn_fin_ts' => $status === 'SUCCESS' ? '20260716120000' : '',
                ];
            }
            if ($path === FuiouPayClient::PATH_CLOSE) {
                return ['result_code' => '000000', 'mchnt_order_no' => (string) $data['mchnt_order_no']];
            }
            if ($path === FuiouPayClient::PATH_CANCEL) {
                return ['result_code' => '000000', 'mchnt_order_no' => (string) $data['mchnt_order_no'], 'recall' => 'N'];
            }
            return [
                'result_code' => '000000',
                'mchnt_order_no' => (string) $data['mchnt_order_no'],
                'refund_order_no' => (string) $data['refund_order_no'],
                'reserved_refund_amt' => (string) $data['refund_amt'],
            ];
        });
        $plugin = $this->fuiouPlugin($client, ['enabled_products' => ['wxpay_scan', 'barcode']]);
        $order = [
            'pay_no' => 'PFUIOUSTATE001',
            'amount' => 100,
            'pay_amount' => 100,
            'chan_order_no' => 'FUIOUSTATE001',
            'pay_type_code' => 'wxpay',
            'pay_product' => 'wxpay_scan',
        ];
        $success = $plugin->query($order);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], '富友 SUCCESS 查单映射错误');
        $this->assertSame('FY-QUERY-TRADE', (string) $success['chan_trade_no'], '富友成功查单必须返回渠道交易号');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->query($order)['status'], '富友 USERPAYING 应保持 pending');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->query($order)['status'], '富友 CLOSED 应映射 closed');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->query($order)['status'], '原支付已 REFUND 仍是支付成功终态');
        $queryCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === FuiouPayClient::PATH_QUERY));
        $this->assertSame(['order_type', 'mchnt_order_no'], array_keys($queryCalls[0]['data']), 'commonQuery 业务报文字段不正确');

        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '普通扫码订单应使用 closeorder');
        $closeCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === FuiouPayClient::PATH_CLOSE));
        $this->assertSame(1, count($closeCalls), '普通支付不能误用付款码 cancelorder');
        $barcodeOrder = array_replace($order, ['pay_product' => 'barcode']);
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($barcodeOrder)['status'], '付款码订单应使用 cancelorder 撤销');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($barcodeOrder)['status'], '重复撤销应保持幂等');
        $cancelCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === FuiouPayClient::PATH_CANCEL));
        $this->assertSame($cancelCalls[0]['data'], $cancelCalls[1]['data'], '付款码重复撤销必须使用同一 cancel_order_no');

        $refundOrder = $order + [
            'refund_no' => 'R_FUIOU_STATE',
            'refund_amount' => 50,
            'refund_reason' => '用户申请退款',
        ];
        $firstRefund = $plugin->refund($refundOrder);
        $secondRefund = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $firstRefund['status'], '富友 commonRefund 明确成功应返回成功');
        $this->assertSame(50, (int) $firstRefund['refund_amount'], '富友退款金额必须保持整数分');
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === FuiouPayClient::PATH_REFUND));
        $this->assertSame($refundCalls[0]['data'], $refundCalls[1]['data'], '富友退款重试必须复用相同原单、退款单和金额');
        $this->assertSame('100', (string) $refundCalls[0]['data']['total_amt'], 'commonRefund 原金额必须是整数分');
        $this->assertSame('50', (string) $refundCalls[0]['data']['refund_amt'], 'commonRefund 退款金额必须是整数分');
        $this->assertSame((string) $firstRefund['chan_refund_no'], (string) $secondRefund['chan_refund_no'], '重复退款渠道单号必须稳定');
        $this->assertThrows(
            fn () => $plugin->refund(array_replace($refundOrder, ['refund_amount' => 101])),
            '富友退款金额超过原支付金额必须在请求前拒绝'
        );
    }

    /**
     * 汇付 data 字典序、RSA-SHA256 签名与异步原文验签。
     */
    private function testHuifuSdkSignatureContract(): void
    {
        $merchantPair = RsaKeyPairGenerator::generate(2048);
        $platformPair = RsaKeyPairGenerator::generate(2048);
        $client = new HuifuClient([
            'sys_id' => 'SYS-HUIFU',
            'product_id' => 'P-HUIFU',
            'merchant_private_key' => $merchantPair['private_key'],
            'huifu_public_key' => $platformPair['public_key'],
        ]);
        $data = [
            'wx_data' => '{"sub_openid":"OPEN-ID","sub_appid":"wx-app"}',
            'trans_amt' => '1.23',
            'empty' => '',
            'nullable' => null,
            'req_seq_id' => 'P202607160100',
        ];
        $content = (string) $this->privateMethod(HuifuClient::class, 'signContent')->invoke($client, $data);
        $this->assertSame(
            '{"empty":"","req_seq_id":"P202607160100","trans_amt":"1.23","wx_data":"{\\"sub_openid\\":\\"OPEN-ID\\",\\"sub_appid\\":\\"wx-app\\"}"}',
            $content,
            '汇付签名只移除 null、保留空字符串并只排序 data 第一层'
        );
        $signature = (string) $this->privateMethod(HuifuClient::class, 'sign')->invoke($client, $data);
        $this->assertSame(
            1,
            openssl_verify($content, base64_decode($signature, true), $merchantPair['public_key'], OPENSSL_ALGO_SHA256),
            '汇付请求必须使用 RSA-SHA256 对排序后的 data JSON 加签'
        );

        $notifyBody = '{"req_seq_id":"P202607160100","trans_stat":"S"}';
        $this->assertTrue(
            openssl_sign($notifyBody, $notifySignature, $platformPair['private_key'], OPENSSL_ALGO_SHA256),
            '生成汇付异步验签向量失败'
        );
        $this->assertTrue($client->verifyNotify($notifyBody, base64_encode($notifySignature)), '汇付异步通知必须按 resp_data 原文验签');
        $this->assertFalse($client->verifyNotify($notifyBody . ' ', base64_encode($notifySignature)), '汇付异步通知原文变化必须验签失败');
    }

    /**
     * 汇付单次支付产品选择、身份作用域、托管与线上页面，以及 auth_code 产品隔离。
     */
    private function testHuifuProductIdentityAndRouting(): void
    {
        $client = new HuifuUnitClient(static function (string $path, array $data): array {
            $base = [
                'resp_code' => $path === '/v3/trade/payment/jspay' ? '00000100' : '00000000',
                'resp_desc' => 'accepted',
                'req_date' => (string) ($data['req_date'] ?? date('Ymd')),
                'req_seq_id' => (string) ($data['req_seq_id'] ?? ''),
                'huifu_id' => (string) ($data['huifu_id'] ?? ''),
                'trans_amt' => (string) ($data['trans_amt'] ?? '1.23'),
                'trans_stat' => $path === '/v3/trade/payment/micropay' ? 'P' : 'I',
                'hf_seq_id' => 'HF-' . count($data),
            ];
            if ($path === '/v3/trade/payment/jspay') {
                return $base + [
                    'qr_code' => 'https://qr.huifu.test/' . (string) ($data['trade_type'] ?? ''),
                    'pay_info' => '{"appId":"unit-app","timeStamp":"1","nonceStr":"n","package":"prepay_id=x","signType":"RSA","paySign":"s"}',
                ];
            }
            if ($path === '/v2/trade/hosting/payment/preorder') {
                return $base + ['jump_url' => 'https://hosting.huifu.test/pay', 'pre_order_id' => 'HOST-PRE-1'];
            }
            if (in_array($path, ['/v2/trade/onlinepayment/quickpay/frontpay', '/v2/trade/onlinepayment/banking/frontpay'], true)) {
                $online = $base + ['form_url' => 'https://online.huifu.test/pay'];
                // 官方页面版同步响应将 huifu_id 标为可选，示例也可不返回。
                unset($online['huifu_id']);
                if ($path === '/v2/trade/onlinepayment/quickpay/frontpay') {
                    $online['resp_code'] = '00000100';
                }

                return $online;
            }

            return $base;
        });
        $plugin = $this->huifuPlugin($client);

        $alipayIdentity = $plugin->identityRequirement($this->huifuOrder('alipay', 'alipay'));
        $this->assertSame('buyer_id', (string) ($alipayIdentity['identity_field'] ?? ''), '汇付支付宝 JSAPI 必须声明 buyer_id 身份');
        $wxIdentity = $plugin->identityRequirement($this->huifuOrder('wxpay', 'wechat'));
        $this->assertSame('sub_openid', (string) ($wxIdentity['identity_field'] ?? ''), '汇付公众号必须声明 sub_openid 身份');
        $miniIdentity = $plugin->identityRequirement($this->huifuOrder('wxpay', 'wechat', ['is_mini' => '1']));
        $this->assertSame('mini_openid', (string) ($miniIdentity['identity_field'] ?? ''), '汇付小程序必须声明 mini_openid 身份');
        $this->assertSame('wx-huifu-mini', (string) ($miniIdentity['app_id'] ?? ''), '汇付小程序身份必须绑定当前小程序 AppID');
        $this->assertSame(null, $plugin->identityRequirement($this->huifuOrder('wxpay', 'wechat', ['method' => 'qrcode'])), '显式扫码不得误触发 JSAPI 身份流程');

        $alipayScan = $plugin->pay($this->huifuOrder('alipay', 'pc', ['method' => 'qrcode']));
        $alipayJs = $plugin->pay($this->huifuOrder('alipay', 'alipay', ['buyer_id' => '2088000000000001', 'alipay_app_id' => '2026000000000001']));
        $wxMp = $plugin->pay($this->huifuOrder('wxpay', 'wechat', ['sub_openid' => 'wx-mp-openid', 'sub_appid' => 'wx-huifu-mp']));
        $wxMini = $plugin->pay($this->huifuOrder('wxpay', 'wechat', ['mini_openid' => 'wx-mini-openid', 'mini_app_id' => 'wx-huifu-mini']));
        $hosted = $plugin->pay($this->huifuOrder('wxpay', 'mobile', ['method' => 'h5']));
        $quick = $plugin->pay($this->huifuOrder('bank', 'mobile', ['method' => 'h5']));
        $bankWeb = $plugin->pay($this->huifuOrder('bank', 'pc', ['method' => 'web']));
        $ecny = $plugin->pay($this->huifuOrder('ecny', 'pc', ['method' => 'qrcode']));
        $barcode = $plugin->pay($this->huifuOrder('alipay', 'pc', ['auth_code' => '281234567890123456']));

        $this->assertSame('alipay_scan', (string) $alipayScan['pay_product'], '支付宝扫码产品选择错误');
        $this->assertSame('alipay_jsapi', (string) $alipayJs['pay_product'], '支付宝 JSAPI 产品选择错误');
        $this->assertSame('wxpay_jsapi', (string) $wxMp['pay_product'], '微信公众号产品选择错误');
        $this->assertSame('wxpay_mini', (string) $wxMini['pay_product'], '微信小程序必须使用独立 pay_product');
        $this->assertSame('wxpay_hosted', (string) $hosted['pay_product'], '微信托管 H5 产品选择错误');
        $this->assertSame('quickpay_page', (string) $quick['pay_product'], '移动端快捷页面产品选择错误');
        $this->assertSame('bank_web', (string) $bankWeb['pay_product'], 'PC 网银页面产品选择错误');
        $this->assertSame('ecny_scan', (string) $ecny['pay_product'], '数字人民币扫码产品选择错误');
        $this->assertSame('barcode', (string) $barcode['pay_product'], 'auth_code 必须固定选择付款码产品');
        $this->assertSame('page', (string) $barcode['presentation']['pay_page'], '付款码处理中不得返回假成功页');
        $this->assertSame('paymentPending', (string) $barcode['presentation']['pay_params']['_page'], '付款码 pending 必须使用已注册承接组件');

        $wxCall = array_values(array_filter($client->calls, static fn (array $call): bool => ($call['data']['trade_type'] ?? '') === 'T_JSAPI'))[0];
        $wxData = json_decode((string) $wxCall['data']['wx_data'], true);
        $this->assertSame('wx-huifu-mp', (string) ($wxData['sub_appid'] ?? ''), '汇付公众号请求必须带当前作用域 sub_appid');
        $this->assertSame('wx-mp-openid', (string) ($wxData['sub_openid'] ?? ''), '汇付公众号请求必须带同作用域 sub_openid');
        $this->assertFalse(array_key_exists('openid', $wxData), '汇付不得把 sub_openid 同时冒充 openid');
        $this->assertFalse(array_key_exists('pay_info', (array) $alipayJs['presentation']['pay_params']['raw']), '汇付 raw 只能保留脱敏摘要');
        $this->assertFalse(
            (string) ($alipayScan['presentation']['pay_params']['raw']['huifu_id'] ?? '') === 'M-HUIFU',
            '汇付 raw 不得暴露完整交易商户号'
        );

        $this->assertThrows(
            fn () => $plugin->pay($this->huifuOrder('wxpay', 'wechat', ['sub_openid' => 'wrong-scope', 'sub_appid' => 'wx-other-app'])),
            '汇付必须拒绝错误微信公众号 AppID 作用域'
        );

        $noFallbackClient = new HuifuUnitClient(static fn (string $path): array|Throwable => new HuifuSdkException('micropay unavailable'));
        $noFallback = $this->huifuPlugin($noFallbackClient);
        $this->assertThrows(
            fn () => $noFallback->pay($this->huifuOrder('alipay', 'pc', ['auth_code' => '281234567890123456'])),
            '汇付付款码失败不得降级生成二维码'
        );
        $this->assertSame(1, count($noFallbackClient->calls), '汇付 auth_code 失败后不得尝试其他产品');
        $this->assertSame('/v3/trade/payment/micropay', (string) $noFallbackClient->calls[0]['path'], '汇付 auth_code 必须只调用 micropay');
    }

    /**
     * 汇付回调验签后必须提供订单、整数分金额、币种与交易商户关联。
     */
    private function testHuifuNotifyOrderAmountMerchant(): void
    {
        $plugin = $this->huifuPlugin(new HuifuUnitClient(static fn (): array => []));
        $payload = [
            'req_seq_id' => 'P202607160200',
            'huifu_id' => 'M-HUIFU',
            'hf_seq_id' => 'HF-NOTIFY-200',
            'trans_amt' => '1.23',
            'currency' => 'CNY',
            'trans_stat' => 'S',
            'end_time' => '20260716123045',
        ];
        $result = $plugin->notify($this->huifuNotifyRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '汇付 S 回调状态映射错误');
        $this->assertSame('P202607160200', (string) $result['pay_no'], '汇付回调必须返回可校验 pay_no');
        $this->assertSame(123, (int) $result['paid_amount'], '汇付回调金额必须精确转换为整数分');
        $this->assertSame('2026-07-16 12:30:45', (string) $result['paid_at'], '汇付回调应使用官方 end_time');
        $this->assertSame('RECV_ORD_ID_P202607160200', $plugin->notifySuccess(), '汇付成功应答必须关联已验签订单号');

        $callbackService = (new ReflectionClass(PayOrderCallbackService::class))->newInstanceWithoutConstructor();
        $payOrder = new PayOrder();
        $payOrder->pay_no = 'P202607160200';
        $payOrder->pay_amount = 123;
        $assertPayNo = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyPayNoMatches');
        $assertAmount = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyAmountMatches');
        $assertPayNo->invoke($callbackService, $payOrder, $result);
        $assertAmount->invoke($callbackService, $payOrder, $result);
        $this->assertThrows(
            fn () => $assertPayNo->invoke($callbackService, $payOrder, array_replace($result, ['pay_no' => 'P-WRONG'])),
            '汇付 A 单回调投递到 B 单 URL 必须拒绝'
        );
        $this->assertThrows(
            fn () => $assertAmount->invoke($callbackService, $payOrder, array_replace($result, ['paid_amount' => 124])),
            '汇付错金额回调必须拒绝'
        );
        $this->assertThrows(
            fn () => $plugin->notify($this->huifuNotifyRequest(array_replace($payload, ['huifu_id' => 'M-OTHER']))),
            '汇付错交易商户回调必须拒绝'
        );
        $this->assertThrows(
            fn () => $plugin->notify($this->huifuNotifyRequest(array_replace($payload, ['currency' => 'USD']))),
            '汇付非人民币回调必须拒绝'
        );
        $badSignPlugin = $this->huifuPlugin(new HuifuUnitClient(static fn (): array => [], false));
        $this->assertThrows(fn () => $badSignPlugin->notify($this->huifuNotifyRequest($payload)), '汇付错签名回调必须拒绝');
    }

    /**
     * 汇付扫码、托管、线上支付分别查单/关单/退款并保留处理中状态。
     */
    private function testHuifuQueryCloseRefundAndPending(): void
    {
        $scanQueryStates = ['S'];
        $scanCloseStates = ['P', 'S'];
        $client = new HuifuUnitClient(function (string $path, array $data) use (&$scanQueryStates, &$scanCloseStates): array {
            $base = [
                'resp_code' => '00000000',
                'resp_desc' => 'ok',
                'req_date' => (string) ($data['req_date'] ?? date('Ymd')),
                'req_seq_id' => (string) ($data['req_seq_id'] ?? ''),
                'org_req_date' => (string) ($data['org_req_date'] ?? '20260716'),
                'org_req_seq_id' => (string) ($data['org_req_seq_id'] ?? ''),
                'huifu_id' => (string) ($data['huifu_id'] ?? ''),
                'trans_amt' => '1.23',
                'hf_seq_id' => 'HF-STATE',
                'org_hf_seq_id' => 'HF-ORIGINAL',
            ];
            return match ($path) {
                '/v3/trade/payment/scanpay/query' => $base + ['trans_stat' => array_shift($scanQueryStates) ?? 'F'],
                '/v2/trade/hosting/payment/queryorderinfo' => $base + ['trans_stat' => 'P'],
                '/v2/trade/onlinepayment/query' => $base + ['trans_stat' => 'F'],
                '/v2/trade/payment/scanpay/close' => $base + ['trans_stat' => array_shift($scanCloseStates) ?? 'S'],
                '/v2/trade/hosting/payment/close' => $base + ['trans_stat' => 'S'],
                '/v3/trade/payment/scanpay/refund' => $base + ['trans_stat' => 'P'],
                '/v2/trade/hosting/payment/htRefund' => $base + ['trans_stat' => 'S'],
                '/v2/trade/onlinepayment/refund' => $base + ['trans_stat' => 'S'],
                default => $base + ['trans_stat' => 'P'],
            };
        });
        $plugin = $this->huifuPlugin($client);
        $scan = $this->huifuStateOrder('alipay_scan');
        $hosted = $this->huifuStateOrder('alipay_hosted');
        $online = $this->huifuStateOrder('bank_web');

        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->query($scan)['status'], '汇付扫码查单 S 映射错误');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->query($hosted)['status'], '汇付托管查单 P 映射错误');
        $this->assertSame(PaymentPluginStatusConstant::FAILED, (string) $plugin->query($online)['status'], '汇付线上查单 F 映射错误');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->close($scan)['status'], '汇付关单 P 不得提前本地关单');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($scan)['status'], '汇付关单 S 应确认成功');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($hosted)['status'], '汇付托管关单必须走 hosting close');
        $this->assertThrows(fn () => $plugin->close($online), '汇付快捷/网银无公开关单接口时必须明确拒绝');

        $scanRefund = $plugin->refund($scan + ['refund_no' => 'R202607160301', 'refund_amount' => 23, 'refund_reason' => '测试']);
        $hostedRefund = $plugin->refund($hosted + ['refund_no' => 'R202607160302', 'refund_amount' => 23]);
        $onlineRefund = $plugin->refund($online + ['refund_no' => 'R202607160303', 'refund_amount' => 23]);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $scanRefund['status'], '汇付扫码退款 P 必须保留 pending');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $hostedRefund['status'], '汇付托管退款 S 映射错误');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $onlineRefund['status'], '汇付线上退款 S 映射错误');
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => str_ends_with($call['path'], 'refund') || str_ends_with($call['path'], 'htRefund')));
        $this->assertSame('0.23', (string) $refundCalls[0]['data']['ord_amt'], '汇付退款金额必须从整数分精确转元');
        $this->assertSame('R202607160301', (string) $refundCalls[0]['data']['req_seq_id'], '汇付退款重试必须复用退款单号');

        $badAmountClient = new HuifuUnitClient(static fn (string $path, array $data): array => [
            'resp_code' => '00000000',
            'req_seq_id' => (string) ($data['req_seq_id'] ?? ''),
            'org_req_seq_id' => (string) ($data['org_req_seq_id'] ?? ''),
            'huifu_id' => (string) ($data['huifu_id'] ?? ''),
            'trans_amt' => '1.24',
            'trans_stat' => 'S',
        ]);
        $badAmount = $this->huifuPlugin($badAmountClient);
        $this->assertThrows(fn () => $badAmount->query($scan), '汇付查单金额与支付单不一致必须拒绝');

        $terminalClient = new HuifuUnitClient(static function (string $path, array $data): array {
            if ($path === '/v2/trade/payment/scanpay/close') {
                return [
                    'resp_code' => '23000000',
                    'resp_desc' => '原订单已为终态',
                    'req_seq_id' => (string) $data['req_seq_id'],
                    'org_req_seq_id' => (string) $data['org_req_seq_id'],
                    'huifu_id' => (string) $data['huifu_id'],
                ];
            }
            return [
                'resp_code' => '00000000',
                'org_req_seq_id' => (string) $data['org_req_seq_id'],
                'huifu_id' => (string) $data['huifu_id'],
                'trans_amt' => '1.23',
                'trans_stat' => 'F',
            ];
        });
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $this->huifuPlugin($terminalClient)->close($scan)['status'], '汇付重复关单必须查原单后幂等确认不可支付终态');
    }

    /**
     * 天阙统一报文、RSA-SHA1 签名、reqId 和响应关联。
     */
    private function testTianqueSdkEnvelopeAndSignature(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $privateBody = preg_replace('/-----[^-]+-----|\s+/', '', $pair['private_key']);
        $publicBody = preg_replace('/-----[^-]+-----|\s+/', '', $pair['public_key']);
        $this->assertTrue(is_string($privateBody) && $privateBody !== '', '天阙测试私钥正文生成失败');
        $this->assertTrue(is_string($publicBody) && $publicBody !== '', '天阙测试公钥正文生成失败');

        $handler = function ($request, array $options) use ($pair) {
            $payload = json_decode((string) $request->getBody(), true);
            $this->assertTrue(is_array($payload), '天阙请求必须是 JSON 对象');
            $this->assertSame('ORG-001', (string) ($payload['orgId'] ?? ''), '天阙统一报文 orgId 不正确');
            $this->assertSame(32, strlen((string) ($payload['reqId'] ?? '')), '天阙 reqId 必须为 32 位');
            $this->assertSame('1.0', (string) ($payload['version'] ?? ''), '天阙统一报文版本不正确');
            $this->assertSame('RSA', (string) ($payload['signType'] ?? ''), '天阙 signType 不正确');
            $this->assertTrue(preg_match('/^\d{14}$/', (string) ($payload['timestamp'] ?? '')) === 1, '天阙 timestamp 格式不正确');
            $this->assertSame('MNO-001', (string) ($payload['reqData']['mno'] ?? ''), '天阙 reqData 未按原结构签名和发送');
            $verified = openssl_verify(
                $this->tianqueSignContent($payload),
                base64_decode((string) $payload['sign'], true) ?: '',
                $pair['public_key'],
                OPENSSL_ALGO_SHA1
            );
            $this->assertSame(1, $verified, '天阙请求 RSA-SHA1 签名不正确');

            $response = [
                'code' => '0000',
                'msg' => '成功',
                'orgId' => 'ORG-001',
                'reqId' => (string) $payload['reqId'],
                'respData' => ['bizCode' => '0000', 'ordNo' => 'P-SDK-001'],
                'timestamp' => '20260716120000',
                'version' => '1.0',
                'signType' => 'RSA',
            ];
            openssl_sign($this->tianqueSignContent($response), $signature, $pair['private_key'], OPENSSL_ALGO_SHA1);
            $response['sign'] = base64_encode($signature);

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        };
        $client = new TianqueTechClient([
            'org_id' => 'ORG-001',
            'merchant_no' => 'MNO-001',
            'merchant_private_key' => $privateBody,
            'platform_public_key' => $publicBody,
            'api_base_url' => 'https://tianque.unit.test',
        ]);
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $handler]));
        $data = $client->submit(TianqueTechClient::PATH_TRADE_QUERY, ['mno' => 'MNO-001', 'ordNo' => 'P-SDK-001']);
        $this->assertSame('P-SDK-001', (string) $data['ordNo'], '天阙成功响应应在验签和机构/请求号关联后返回 respData');
    }

    /**
     * 天阙产品开关、环境候选顺序和公众号/小程序身份作用域。
     */
    private function testTianqueProductIdentityAndRouting(): void
    {
        $client = new TianqueTechUnitClient(function (string $path, array $data, int $index): array {
            if ($path === TianqueTechClient::PATH_APPLET_SCAN_PRE && (string) ($data['appletSource'] ?? '') === '00') {
                return ['bizCode' => '0000', 'key' => 'APPLET-KEY-' . $index, 'amt' => (string) ($data['amt'] ?? ''), 'uuid' => 'UUID-' . $index];
            }
            if ($path === TianqueTechClient::PATH_APPLET_SCAN_PRE) {
                return ['bizCode' => '0000', 'appId' => 'wx-tianque-cashier', 'path' => 'pages/home/pay/pay?key=unit', 'uuid' => 'UUID-' . $index];
            }
            if ($path === TianqueTechClient::PATH_JSAPI_SCAN && (string) ($data['payType'] ?? '') === 'ALIPAY') {
                return ['bizCode' => '0000', 'source' => 'ALIPAY-TRADE-' . $index, 'uuid' => 'UUID-' . $index];
            }
            if ($path === TianqueTechClient::PATH_JSAPI_SCAN) {
                return [
                    'bizCode' => '0000',
                    'payAppId' => (string) ($data['subAppid'] ?? ''),
                    'payTimeStamp' => '1721102400',
                    'paynonceStr' => 'nonce-' . $index,
                    'payPackage' => 'prepay_id=prepay-' . $index,
                    'paySignType' => 'RSA',
                    'paySign' => 'pay-sign-' . $index,
                    'uuid' => 'UUID-' . $index,
                ];
            }

            return ['bizCode' => '0000', 'payUrl' => 'https://pay.unit.test/' . $index, 'uuid' => 'UUID-' . $index];
        });
        $plugin = $this->tianquePlugin($client);

        $pcAlipay = $plugin->pay($this->tianqueOrder('alipay', 'pc'));
        $this->assertSame('qrcode', (string) $pcAlipay['presentation']['pay_page'], '支付宝 PC 环境只有扫码/JSAPI 时应选扫码');
        $this->assertSame(TianqueTechClient::PATH_ACTIVE_SCAN_CURRENT, $client->calls[0]['path'], '当前协议档位主扫路径不正确');
        $this->assertSame('ALIPAY', (string) $client->calls[0]['data']['payType'], '支付宝扫码 payType 不正确');
        $this->assertFalse(array_key_exists('raw', (array) $pcAlipay['presentation']['pay_params']), '天阙支付结果不能持久化敏感原始报文');

        $alipayJsapi = $plugin->pay($this->tianqueOrder('alipay', 'alipay', ['buyer_id' => '2088000000000001']));
        $alipayCall = $client->calls[1];
        $this->assertSame('alipay_jsapi', (string) $alipayJsapi['pay_product'], '支付宝内应优先 JSAPI');
        $this->assertSame('02', (string) $alipayCall['data']['payWay'], '支付宝 JSAPI payWay 必须为 02');
        $this->assertSame('2088000000000001', (string) $alipayCall['data']['userId'], '支付宝 JSAPI 必须使用 buyer_id/userId');
        $this->assertSame('', (string) $alipayCall['data']['subAppid'], '支付宝 JSAPI 不应混入微信 subAppid');

        $mp = $plugin->pay($this->tianqueOrder('wxpay', 'wechat', ['sub_openid' => 'openid-mp']));
        $mpCall = $client->calls[2];
        $this->assertSame('wxpay_mp', (string) $mp['pay_product'], '微信公众号支付产品应独立于小程序');
        $this->assertSame('02', (string) $mpCall['data']['payWay'], '微信公众号 payWay 必须为 02');
        $this->assertSame('openid-mp', (string) $mpCall['data']['userId'], '微信公众号必须使用公众号作用域身份');
        $this->assertSame('wx-mp-app', (string) $mpCall['data']['subAppid'], '微信公众号 subAppid 不正确');

        $mini = $plugin->pay($this->tianqueOrder('wxpay', 'wechat', [
            'is_mini' => true,
            'mini_openid' => 'openid-mini',
        ]));
        $miniCall = $client->calls[3];
        $this->assertSame('wxpay_mini', (string) $mini['pay_product'], '微信小程序支付产品应独立于公众号');
        $this->assertSame('03', (string) $miniCall['data']['payWay'], '微信小程序 payWay 必须为 03');
        $this->assertSame('openid-mini', (string) $miniCall['data']['userId'], '微信小程序必须只使用 mini_openid');
        $this->assertSame('wx-mini-app', (string) $miniCall['data']['subAppid'], '微信小程序 subAppid 不正确');

        $bank = $plugin->pay($this->tianqueOrder('bank', 'pc'));
        $this->assertSame('bank_scan', (string) $bank['pay_product'], '银联支付应选择独立扫码产品');
        $this->assertSame('UNIONPAY', (string) $client->calls[4]['data']['payType'], '银联扫码 payType 不正确');

        $plugin->pay($this->tianqueOrder('alipay', 'mobile'));
        $this->assertSame(TianqueTechClient::PATH_ACTIVE_SCAN_CURRENT, $client->calls[5]['path'], '移动浏览器无 H5 产品时应回落到扫码');
        $plugin->pay($this->tianqueOrder('alipay', 'alipay', ['buyer_id' => '2088', 'method' => 'qrcode']));
        $this->assertSame(TianqueTechClient::PATH_ACTIVE_SCAN_CURRENT, $client->calls[6]['path'], '显式 qrcode 应在支付宝环境优先于 JSAPI');

        $mobileMiniRequirement = $plugin->identityRequirement($this->tianqueOrder('wxpay', 'mobile', ['is_mini' => true]));
        $this->assertSame('mini_openid', (string) ($mobileMiniRequirement['identity_field'] ?? ''), '移动 H5 明确选择小程序时应进入 mini_openid 身份流程');
        $this->assertSame('url_scheme', (string) ($mobileMiniRequirement['mini_launch_type'] ?? ''), '移动 H5 的 wxminipay 替代流程应使用小程序 URL Scheme');
        $plugin->pay($this->tianqueOrder('wxpay', 'mobile', ['is_mini' => true, 'mini_openid' => 'mobile-mini-openid']));
        $this->assertSame(TianqueTechClient::PATH_JSAPI_SCAN, $client->calls[7]['path'], '移动 H5 续跑后应固定原通道并请求小程序 JSAPI');
        $this->assertSame('03', (string) $client->calls[7]['data']['payWay'], '移动 H5 小程序续跑 payWay 必须为 03');

        $appletPlugin = $plugin->pay($this->tianqueOrder('wxpay', 'mobile', ['method' => 'applet']));
        $this->assertSame('wxpay_applet_plugin', (string) $appletPlugin['pay_product'], '彩虹 method=applet 默认应映射天阙小程序支付插件');
        $this->assertSame('00', (string) $client->calls[8]['data']['appletSource'], 'wxplugin 必须使用 appletSource=00');
        $this->assertSame('APPLET-KEY-9', (string) $appletPlugin['presentation']['pay_params']['params']['key'], '小程序支付插件必须返回 key');
        $this->assertSame('wechat_mini_program', (string) $appletPlugin['presentation']['pay_params']['execution_context'], '托管小程序参数必须声明消费容器');
        $this->assertSame('wechat_mini_program_plugin', (string) $appletPlugin['presentation']['pay_params']['launch_type'], '小程序插件承接类型错误');
        $appletCashier = $plugin->pay($this->tianqueOrder('wxpay', 'mobile', ['method' => 'page', 'applet_source' => '01']));
        $this->assertSame('wxpay_applet_cashier', (string) $appletCashier['pay_product'], 'wxapplet 应映射半屏小程序收银台');
        $this->assertSame('01', (string) $client->calls[9]['data']['appletSource'], 'wxapplet 必须使用 appletSource=01');
        $this->assertSame('wx-tianque-cashier', (string) $appletCashier['presentation']['pay_params']['params']['app_id'], '半屏收银台必须返回 appId/path');
        $this->assertSame('wechat_mini_program_half_screen', (string) $appletCashier['presentation']['pay_params']['launch_type'], '半屏小程序承接类型错误');

        $mpRequirement = $plugin->identityRequirement($this->tianqueOrder('wxpay', 'wechat'));
        $this->assertSame('sub_openid', (string) ($mpRequirement['identity_field'] ?? ''), '公众号身份流程必须回填公众号作用域字段');
        $miniRequirement = $plugin->identityRequirement($this->tianqueOrder('wxpay', 'wechat', ['is_mini' => true]));
        $this->assertSame('mini_openid', (string) ($miniRequirement['identity_field'] ?? ''), '小程序身份流程必须回填 mini_openid');
        $alipayRequirement = $plugin->identityRequirement($this->tianqueOrder('alipay', 'alipay', ['buyer_open_id' => 'ignored-openid']));
        $this->assertSame('buyer_id', (string) ($alipayRequirement['identity_field'] ?? ''), '天阙支付宝不应把 buyer_open_id 当作 userId');

        $scanOnlyClient = new TianqueTechUnitClient(fn (): array => ['bizCode' => '0000', 'payUrl' => 'https://scan-only.test', 'uuid' => 'SCAN-ONLY']);
        $scanOnly = $this->tianquePlugin($scanOnlyClient, ['enabled_products' => ['alipay_scan']]);
        $this->assertSame(null, $scanOnly->identityRequirement($this->tianqueOrder('alipay', 'alipay')), '禁用 JSAPI 后不应进入身份流程');
        $scanOnly->pay($this->tianqueOrder('alipay', 'alipay'));
        $this->assertSame(1, count($scanOnlyClient->calls), '产品开关应在请求上游前完成过滤');
        $this->assertSame(TianqueTechClient::PATH_ACTIVE_SCAN_CURRENT, $scanOnlyClient->calls[0]['path'], '禁用 JSAPI 后应只请求扫码接口');
    }

    /**
     * 天阙通知验签后的订单、金额、币种、机构、商户、产品和重放校验。
     */
    private function testTianqueNotifyBusinessValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-TIANQUE-NOTIFY',
            'pay_amount' => 100,
            'channel_id' => 12,
            'channel_trade_no' => 'UUID-NOTIFY',
            'ext_json' => ['payment_context' => ['pay_product' => 'wxpay_mini']],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $client = new TianqueTechUnitClient(fn (): array => []);
        $plugin = $this->tianquePlugin($client, [], $repository);
        $payload = [
            'bizCode' => '0000',
            'bizMsg' => '支付成功',
            'ordNo' => 'P-TIANQUE-NOTIFY',
            'mno' => 'MNO-TEST',
            'orgId' => 'ORG-TEST',
            'amt' => '1.00',
            'currency' => 'CNY',
            'payType' => 'WECHAT',
            'payWay' => '03',
            'uuid' => 'UUID-NOTIFY',
            'payTime' => '20260716123045',
            'sign' => 'valid-sign',
        ];

        $result = $plugin->notify($this->rawJsonRequest($payload));
        $replayed = $plugin->notify($this->rawJsonRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '天阙成功通知应归一为 success');
        $this->assertSame('P-TIANQUE-NOTIFY', (string) $result['pay_no'], '天阙通知必须返回 pay_no 供回调 URL 关联校验');
        $this->assertSame($result, $replayed, '同一已验签通知重放应得到相同归一结果且不在插件内写状态');

        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['ordNo' => 'P-WRONG']))), '天阙错单通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['amt' => '1.01']))), '天阙错金额通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['currency' => 'USD']))), '天阙非 CNY 通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['mno' => 'MNO-WRONG']))), '天阙错商户通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['orgId' => 'ORG-WRONG']))), '天阙错机构通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['payWay' => '02']))), '天阙小程序通知不能按公众号 payWay 入账');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['bizCode' => 'PAYING']))), '天阙非成功通知不能推进支付成功');

        $badSignature = $this->tianquePlugin(new TianqueTechUnitClient(fn (): array => [], false), [], $repository);
        $this->assertThrows(fn () => $badSignature->notify($this->rawJsonRequest($payload)), '天阙签名错误通知必须拒绝');
    }

    /**
     * 天阙查单状态、当前与兼容协议关单路径，以及退款幂等状态。
     */
    private function testTianqueQueryCloseRefundIdempotency(): void
    {
        $queryResponses = [
            ['bizCode' => '0000', 'ordNo' => 'P-TIANQUE-STATE', 'oriTranAmt' => '1.00', 'tranSts' => 'SUCCESS', 'uuid' => 'UUID-STATE'],
            ['bizCode' => '0000', 'ordNo' => 'P-TIANQUE-STATE', 'tranSts' => 'PAYING'],
            ['bizCode' => '0000', 'ordNo' => 'P-TIANQUE-STATE', 'tranSts' => 'FAIL'],
            ['bizCode' => '0000', 'ordNo' => 'P-TIANQUE-STATE', 'tranSts' => 'CLOSED'],
        ];
        $refundResponses = [
            ['bizCode' => '0000', 'ordNo' => 'R-TIANQUE-STATE', 'tranSts' => 'REFUNDING'],
            ['bizCode' => '0000', 'ordNo' => 'R-TIANQUE-STATE', 'tranSts' => 'REFUNDSUC'],
            ['bizCode' => '0000', 'ordNo' => 'R-TIANQUE-STATE', 'tranSts' => 'REFUNDSUC'],
        ];
        $client = new TianqueTechUnitClient(function (string $path) use (&$queryResponses, &$refundResponses): array {
            return match ($path) {
                TianqueTechClient::PATH_TRADE_QUERY => array_shift($queryResponses) ?? ['bizCode' => '0000', 'tranSts' => 'CLOSED'],
                TianqueTechClient::PATH_CLOSE_CURRENT => ['bizCode' => '0000', 'origOrderNo' => 'P-TIANQUE-STATE', 'tranSts' => 'CLOSED'],
                TianqueTechClient::PATH_REFUND => array_shift($refundResponses) ?? ['bizCode' => '0000', 'ordNo' => 'R-TIANQUE-STATE', 'tranSts' => 'REFUNDSUC'],
                default => ['bizCode' => '0000'],
            };
        });
        $plugin = $this->tianquePlugin($client);
        $order = ['pay_no' => 'P-TIANQUE-STATE', 'amount' => 100, 'chan_trade_no' => 'UUID-STATE'];
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->query($order)['status'], '天阙 SUCCESS 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->query($order)['status'], '天阙 PAYING 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::FAILED, (string) $plugin->query($order)['status'], '天阙 FAIL 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->query($order)['status'], '天阙 CLOSED 查单映射错误');

        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '天阙当前版关单应成功');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '重复关单应保持幂等成功');
        $closeCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === TianqueTechClient::PATH_CLOSE_CURRENT));
        $this->assertSame(2, count($closeCalls), '天阙重复关单应复用同一当前版接口');
        $this->assertSame($closeCalls[0]['data'], $closeCalls[1]['data'], '天阙重复关单必须使用同一原订单号');

        $refundOrder = [
            'pay_no' => 'P-TIANQUE-STATE',
            'refund_no' => 'R-TIANQUE-STATE',
            'refund_amount' => 50,
        ];
        $pending = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], '天阙 REFUNDING 应映射 pending');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refundOrder)['status'], '天阙 REFUNDSUC 应确认退款成功');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refundOrder)['status'], '相同退款单号重复退款应保持幂等成功');
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === TianqueTechClient::PATH_REFUND));
        $this->assertSame($refundCalls[0]['data'], $refundCalls[1]['data'], '天阙退款重试必须保持同一 ordNo/origOrderNo/amt');
        $this->assertSame($refundCalls[1]['data'], $refundCalls[2]['data'], '天阙退款重放不能生成新上游退款号');

        $legacyClient = new TianqueTechUnitClient(function (string $path): array {
            if ($path === TianqueTechClient::PATH_CANCEL_LEGACY) {
                return ['bizCode' => '0000', 'origOrderNo' => 'P-TIANQUE-STATE', 'tranSts' => 'CANCELED'];
            }
            return ['bizCode' => '0000', 'payUrl' => 'https://legacy.test/pay', 'uuid' => 'UUID-LEGACY'];
        });
        $legacy = $this->tianquePlugin($legacyClient, ['api_profile' => 'rainbow_legacy']);
        $legacy->pay($this->tianqueOrder('bank', 'pc'));
        $this->assertSame(TianqueTechClient::PATH_ACTIVE_SCAN_LEGACY, $legacyClient->calls[0]['path'], '彩虹旧档位主扫路径必须显式保持 activeScan');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $legacy->close($order)['status'], '彩虹兼容档位撤销应映射关单成功');
        $this->assertSame(TianqueTechClient::PATH_CANCEL_LEGACY, $legacyClient->calls[1]['path'], '彩虹旧档位必须显式使用 cancel，不能与当前版混用');
    }

    /**
     * 随行付独立 SDK 的统一报文、RSA-SHA1 签名和响应关联。
     */
    private function testSuixingpaySdkEnvelopeAndSignature(): void
    {
        $pair = RsaKeyPairGenerator::generate(2048);
        $privateBody = preg_replace('/-----[^-]+-----|\s+/', '', $pair['private_key']);
        $publicBody = preg_replace('/-----[^-]+-----|\s+/', '', $pair['public_key']);
        $this->assertTrue(is_string($privateBody) && $privateBody !== '', '随行付测试私钥正文生成失败');
        $this->assertTrue(is_string($publicBody) && $publicBody !== '', '随行付测试公钥正文生成失败');

        $handler = function ($request, array $options) use ($pair) {
            $payload = json_decode((string) $request->getBody(), true);
            $this->assertTrue(is_array($payload), '随行付请求必须是 JSON 对象');
            $this->assertSame('SXP-ORG-001', (string) ($payload['orgId'] ?? ''), '随行付统一报文 orgId 不正确');
            $this->assertSame(32, strlen((string) ($payload['reqId'] ?? '')), '随行付 reqId 必须为 32 位唯一值');
            $this->assertSame('1.0', (string) ($payload['version'] ?? ''), '随行付统一报文版本不正确');
            $this->assertSame('RSA', (string) ($payload['signType'] ?? ''), '随行付 signType 不正确');
            $this->assertTrue(preg_match('/^\d{14}$/', (string) ($payload['timestamp'] ?? '')) === 1, '随行付 timestamp 格式不正确');
            $this->assertSame('SXP-MNO-001', (string) ($payload['reqData']['mno'] ?? ''), '随行付 reqData 未按原结构发送');
            $verified = openssl_verify(
                $this->suixingpaySignContent($payload),
                base64_decode((string) ($payload['sign'] ?? ''), true) ?: '',
                $pair['public_key'],
                OPENSSL_ALGO_SHA1
            );
            $this->assertSame(1, $verified, '随行付请求 RSA-SHA1 签名不正确');

            $response = [
                'code' => '0000',
                'msg' => '成功',
                'orgId' => 'SXP-ORG-001',
                'reqId' => (string) $payload['reqId'],
                'respData' => ['bizCode' => '0000', 'ordNo' => 'P-SXP-SDK-001'],
                'timestamp' => '20260716120000',
                'version' => '1.0',
                'signType' => 'RSA',
            ];
            openssl_sign($this->suixingpaySignContent($response), $signature, $pair['private_key'], OPENSSL_ALGO_SHA1);
            $response['sign'] = base64_encode($signature);

            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        };
        $client = new SuixingpayClient([
            'suixingpay_org_id' => 'SXP-ORG-001',
            'suixingpay_merchant_no' => 'SXP-MNO-001',
            'suixingpay_merchant_private_key' => $privateBody,
            'suixingpay_platform_public_key' => $publicBody,
            'suixingpay_api_base_url' => 'https://suixingpay.unit.test',
        ]);
        $signContent = $this->privateMethod(SuixingpayClient::class, 'signContent')->invoke($client, [
            'sign' => 'ignored',
            'optional' => '',
            'reqData' => ['mno' => 'SXP-MNO-001', 'note' => '中文/测试'],
            'orgId' => 'SXP-ORG-001',
        ]);
        $this->assertSame(
            'orgId=SXP-ORG-001&reqData={"mno":"SXP-MNO-001","note":"中文/测试"}',
            (string) $signContent,
            '随行付签名原文必须排除 sign/空顶层字段并使用无转义紧凑 JSON'
        );
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $handler]));
        $data = $client->submit(SuixingpayClient::PATH_TRADE_QUERY, [
            'mno' => 'SXP-MNO-001',
            'ordNo' => 'P-SXP-SDK-001',
            'uuid' => '',
        ]);
        $this->assertSame('P-SXP-SDK-001', (string) $data['ordNo'], '随行付成功响应应在验签及 orgId/reqId 关联后返回 respData');
    }

    /**
     * 随行付产品开关、环境候选和支付宝/微信身份作用域。
     */
    private function testSuixingpayProductIdentityAndRouting(): void
    {
        $client = new SuixingpayUnitClient(function (string $path, array $data, int $index): array {
            if ($path === SuixingpayClient::PATH_APPLET_SCAN_PRE && (string) ($data['appletSource'] ?? '') === '00') {
                return ['bizCode' => '0000', 'ordNo' => (string) $data['ordNo'], 'key' => 'SXP-APPLET-KEY-' . $index, 'amt' => (string) ($data['amt'] ?? ''), 'uuid' => 'SXP-UUID-' . $index];
            }
            if ($path === SuixingpayClient::PATH_APPLET_SCAN_PRE) {
                return ['bizCode' => '0000', 'ordNo' => (string) $data['ordNo'], 'appId' => 'wx-sxp-cashier', 'path' => 'pages/home/pay/pay?key=sxp-unit', 'uuid' => 'SXP-UUID-' . $index];
            }
            if ($path === SuixingpayClient::PATH_JSAPI_SCAN && (string) ($data['payType'] ?? '') === 'ALIPAY') {
                return ['bizCode' => '0000', 'ordNo' => (string) $data['ordNo'], 'source' => 'SXP-ALIPAY-TRADE-' . $index, 'uuid' => 'SXP-UUID-' . $index];
            }
            if ($path === SuixingpayClient::PATH_JSAPI_SCAN) {
                return [
                    'bizCode' => '0000',
                    'ordNo' => (string) $data['ordNo'],
                    'payAppId' => (string) ($data['subAppid'] ?? ''),
                    'payTimeStamp' => '1721102400',
                    'paynonceStr' => 'sxp-nonce-' . $index,
                    'payPackage' => 'prepay_id=sxp-prepay-' . $index,
                    'paySignType' => 'RSA',
                    'paySign' => 'sxp-pay-sign-' . $index,
                    'uuid' => 'SXP-UUID-' . $index,
                ];
            }

            return ['bizCode' => '0000', 'ordNo' => (string) $data['ordNo'], 'payUrl' => 'https://suixingpay.unit.test/pay/' . $index, 'uuid' => 'SXP-UUID-' . $index];
        });
        $plugin = $this->suixingpayPlugin($client);
        $this->assertTrue($plugin instanceof PaymentIdentityRequirementInterface, '随行付必须实现身份需求接口');
        $this->assertSame('suixingpay_api', $plugin->getCode(), '随行付插件 code 必须与天阙独立');
        $schemaFields = array_map(static fn (array $field): string => (string) ($field['field'] ?? ''), $plugin->getConfigSchema());
        $this->assertTrue(in_array('suixingpay_org_id', $schemaFields, true), '随行付配置 schema 必须使用独立机构配置键');
        $this->assertFalse(in_array('org_id', $schemaFields, true), '随行付新 schema 不得复用机构配置键');

        $pcAlipay = $plugin->pay($this->suixingpayOrder('alipay', 'pc'));
        $this->assertSame('qrcode', (string) $pcAlipay['presentation']['pay_page'], '随行付支付宝 PC 应选择扫码');
        $this->assertSame(SuixingpayClient::PATH_ACTIVE_SCAN_CURRENT, $client->calls[0]['path'], '随行付当前版主扫路径不正确');
        $this->assertSame('ALIPAY', (string) $client->calls[0]['data']['payType'], '随行付支付宝扫码 payType 不正确');
        $this->assertSame('1.00', (string) $client->calls[0]['data']['amt'], '随行付支付金额必须由整数分精确转元');
        $this->assertFalse(array_key_exists('raw', (array) $pcAlipay['presentation']['pay_params']), '随行付 presentation 不得持久化完整原始响应');

        $alipay = $plugin->pay($this->suixingpayOrder('alipay', 'alipay', ['buyer_id' => '2088000000000001']));
        $this->assertSame('alipay_jsapi', (string) $alipay['pay_product'], '支付宝环境应优先随行付 JSAPI');
        $this->assertSame('02', (string) $client->calls[1]['data']['payWay'], '支付宝 JSAPI payWay 必须为 02');
        $this->assertSame('2088000000000001', (string) $client->calls[1]['data']['userId'], '支付宝 JSAPI 必须使用 buyer_id/userId');
        $this->assertSame('', (string) $client->calls[1]['data']['subAppid'], '支付宝 JSAPI 不应混入微信 AppID');

        $mp = $plugin->pay($this->suixingpayOrder('wxpay', 'wechat', ['sub_openid' => 'sxp-openid-mp']));
        $this->assertSame('wxpay_mp', (string) $mp['pay_product'], '微信公众号产品必须与小程序独立');
        $this->assertSame('02', (string) $client->calls[2]['data']['payWay'], '微信公众号 payWay 必须为 02');
        $this->assertSame('sxp-openid-mp', (string) $client->calls[2]['data']['userId'], '微信公众号必须使用 sub_openid');
        $this->assertSame('wx-sxp-mp-app', (string) $client->calls[2]['data']['subAppid'], '微信公众号 subAppid 不正确');

        $mini = $plugin->pay($this->suixingpayOrder('wxpay', 'wechat', ['is_mini' => true, 'mini_openid' => 'sxp-openid-mini']));
        $this->assertSame('wxpay_mini', (string) $mini['pay_product'], '微信小程序产品必须与公众号独立');
        $this->assertSame('03', (string) $client->calls[3]['data']['payWay'], '微信小程序 payWay 必须为 03');
        $this->assertSame('sxp-openid-mini', (string) $client->calls[3]['data']['userId'], '微信小程序必须只使用 mini_openid');
        $this->assertSame('wx-sxp-mini-app', (string) $client->calls[3]['data']['subAppid'], '微信小程序 subAppid 不正确');

        $bank = $plugin->pay($this->suixingpayOrder('bank', 'pc'));
        $this->assertSame('bank_scan', (string) $bank['pay_product'], '银联支付应选择随行付扫码产品');
        $this->assertSame('UNIONPAY', (string) $client->calls[4]['data']['payType'], '银联扫码 payType 不正确');
        $plugin->pay($this->suixingpayOrder('alipay', 'mobile'));
        $this->assertSame(SuixingpayClient::PATH_ACTIVE_SCAN_CURRENT, $client->calls[5]['path'], '普通手机无 H5 产品时应回落扫码');
        $plugin->pay($this->suixingpayOrder('alipay', 'alipay', ['buyer_id' => '2088', 'method' => 'qrcode']));
        $this->assertSame(SuixingpayClient::PATH_ACTIVE_SCAN_CURRENT, $client->calls[6]['path'], '显式 qrcode 必须优先于环境 JSAPI');

        $miniRequirement = $plugin->identityRequirement($this->suixingpayOrder('wxpay', 'mobile', ['is_mini' => true]));
        $this->assertSame('mini_openid', (string) ($miniRequirement['identity_field'] ?? ''), 'wxminipay 续跑必须要求 mini_openid');
        $this->assertSame([], (array) ($miniRequirement['identity_aliases'] ?? []), '小程序身份字段不得声明跨作用域别名');
        $plugin->pay($this->suixingpayOrder('wxpay', 'mobile', ['is_mini' => true, 'mini_openid' => 'sxp-mobile-mini']));
        $this->assertSame(SuixingpayClient::PATH_JSAPI_SCAN, $client->calls[7]['path'], 'wxminipay 应请求 JSAPI 接口');
        $this->assertSame('03', (string) $client->calls[7]['data']['payWay'], 'wxminipay payWay 必须为 03');

        $wxPlugin = $plugin->pay($this->suixingpayOrder('wxpay', 'mobile', ['method' => 'applet']));
        $this->assertSame('wxpay_applet_plugin', (string) $wxPlugin['pay_product'], '彩虹 wxplugin 应映射小程序支付插件产品');
        $this->assertSame('00', (string) $client->calls[8]['data']['appletSource'], 'wxplugin appletSource 必须为 00');
        $this->assertSame('SXP-APPLET-KEY-9', (string) $wxPlugin['presentation']['pay_params']['params']['key'], 'wxplugin 必须返回 key');
        $this->assertSame('wechat_mini_program', (string) $wxPlugin['presentation']['pay_params']['execution_context'], '托管小程序参数必须声明消费容器');
        $this->assertSame('wechat_mini_program_plugin', (string) $wxPlugin['presentation']['pay_params']['launch_type'], '随行付小程序插件承接类型错误');
        $wxApplet = $plugin->pay($this->suixingpayOrder('wxpay', 'mobile', ['method' => 'page', 'applet_source' => '01']));
        $this->assertSame('wxpay_applet_cashier', (string) $wxApplet['pay_product'], '彩虹 wxapplet 应映射半屏小程序收银台');
        $this->assertSame('01', (string) $client->calls[9]['data']['appletSource'], 'wxapplet appletSource 必须为 01');
        $this->assertSame('wechat_mini_program_half_screen', (string) $wxApplet['presentation']['pay_params']['launch_type'], '随行付半屏小程序承接类型错误');
        foreach ([$pcAlipay, $alipay, $mp, $mini, $bank, $wxPlugin, $wxApplet] as $presentation) {
            PaymentPluginPayResultValidator::make($presentation)->withScene('pay_result')->validate();
        }

        $mpRequirement = $plugin->identityRequirement($this->suixingpayOrder('wxpay', 'wechat'));
        $this->assertSame('sub_openid', (string) ($mpRequirement['identity_field'] ?? ''), '公众号身份流程必须要求 sub_openid');
        $alipayRequirement = $plugin->identityRequirement($this->suixingpayOrder('alipay', 'alipay', ['buyer_open_id' => 'must-not-substitute']));
        $this->assertSame('buyer_id', (string) ($alipayRequirement['identity_field'] ?? ''), '支付宝必须要求 userId 对应的 buyer_id');
        $this->assertSame('alipay_oauth', (string) ($alipayRequirement['auth_type'] ?? ''), '支付宝生活号 JSAPI 应使用 OAuth 身份续跑');
        $this->assertSame([], (array) ($alipayRequirement['identity_aliases'] ?? []), '支付宝 buyer_id 不得声明 buyer_open_id 别名');
        $beforeWrongScope = count($client->calls);
        $this->assertThrows(
            fn () => $plugin->pay($this->suixingpayOrder('wxpay', 'wechat', [
                'sub_openid' => 'wrong-scope-openid',
                'sub_appid' => 'wx-wrong-app',
            ])),
            '随行付微信身份 AppID 作用域不一致必须拒绝'
        );
        $this->assertSame($beforeWrongScope, count($client->calls), '随行付 AppID 作用域错误不得请求上游');

        $scanOnlyClient = new SuixingpayUnitClient(fn (string $path, array $data): array => ['bizCode' => '0000', 'ordNo' => (string) $data['ordNo'], 'payUrl' => 'https://suixingpay.unit.test/scan-only', 'uuid' => 'SXP-SCAN-ONLY']);
        $scanOnly = $this->suixingpayPlugin($scanOnlyClient, ['enabled_products' => ['alipay_scan']]);
        $this->assertSame(null, $scanOnly->identityRequirement($this->suixingpayOrder('alipay', 'alipay')), '禁用 JSAPI 后不应进入身份流程');
        $scanOnly->pay($this->suixingpayOrder('alipay', 'alipay'));
        $this->assertSame(1, count($scanOnlyClient->calls), '未开通产品不得请求随行付上游');
        $this->assertSame(SuixingpayClient::PATH_ACTIVE_SCAN_CURRENT, $scanOnlyClient->calls[0]['path'], '禁用 JSAPI 后只能请求扫码接口');

        $legacyConfigClient = new SuixingpayUnitClient(fn (string $path, array $data): array => [
            'bizCode' => '0000',
            'ordNo' => (string) $data['ordNo'],
            'payAppId' => (string) $data['subAppid'],
            'payTimeStamp' => '1721102400',
            'paynonceStr' => 'legacy-config-nonce',
            'payPackage' => 'prepay_id=legacy-config',
            'paySignType' => 'RSA',
            'paySign' => 'legacy-config-sign',
            'uuid' => 'SXP-LEGACY-CONFIG-UUID',
        ]);
        $legacyConfig = new SuixingpayApiPayment(new PayOrderRepository());
        $legacyConfig->init([
            'org_id' => 'SXP-ORG-TEST',
            'merchant_no' => 'SXP-MNO-TEST',
            'platform_public_key' => 'legacy-public-key',
            'merchant_private_key' => 'legacy-private-key',
            'enabled_products' => ['wxpay_jsapi'],
            'wechat_mp_app_id' => 'wx-sxp-mp-app',
            'wechat_mp_app_secret' => 'wx-sxp-mp-secret',
            'channel_id' => 29,
        ]);
        $this->setObjectProperty($legacyConfig, 'client', $legacyConfigClient);
        $legacyConfigResult = $legacyConfig->pay($this->suixingpayOrder('wxpay', 'wechat', ['sub_openid' => 'legacy-config-openid']));
        $this->assertSame('wxpay_mp', (string) $legacyConfigResult['pay_product'], '旧 wxpay_jsapi 配置必须平滑迁移为公众号/小程序拆分产品');
        $this->assertSame(SuixingpayClient::PATH_JSAPI_SCAN, $legacyConfigClient->calls[0]['path'], '旧配置迁移后仍必须走随行付独立 SDK');
    }

    /**
     * 随行付验签后的通道、机构、商户、订单、金额、币种、产品和重放校验。
     */
    private function testSuixingpayNotifyBusinessValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-SXP-NOTIFY',
            'pay_amount' => 100,
            'channel_id' => 29,
            'channel_trade_no' => 'SXP-UUID-NOTIFY',
            'ext_json' => ['payment_context' => ['pay_product' => 'wxpay_mini']],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $plugin = $this->suixingpayPlugin(new SuixingpayUnitClient(fn (): array => []), [], $repository);
        $payload = [
            'bizCode' => '0000',
            'bizMsg' => '支付成功',
            'ordNo' => 'P-SXP-NOTIFY',
            'mno' => 'SXP-MNO-TEST',
            'orgId' => 'SXP-ORG-TEST',
            'amt' => '1.00',
            'currency' => 'CNY',
            'payType' => 'WECHAT',
            'payWay' => '03',
            'uuid' => 'SXP-UUID-NOTIFY',
            'payTime' => '20260716123045',
            'sign' => 'valid-sign',
        ];

        $result = $plugin->notify($this->rawJsonRequest($payload));
        $replayed = $plugin->notify($this->rawJsonRequest($payload));
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '随行付成功通知应归一为 success');
        $this->assertSame('P-SXP-NOTIFY', (string) $result['pay_no'], '随行付通知必须返回 pay_no 供回调 URL 关联');
        $this->assertSame(100, (int) $result['paid_amount'], '随行付通知必须返回整数分金额');
        $this->assertSame($result, $replayed, '相同通知重放应稳定返回同一结果且插件不得推进订单');
        $this->assertSame('{"code":"success","msg":"成功"}', $plugin->notifySuccess(), '随行付通知成功应答必须符合官方固定 JSON');

        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['ordNo' => 'P-SXP-WRONG']))), '随行付错单通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['amt' => '1.01']))), '随行付错金额通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['currency' => 'USD']))), '随行付非 CNY 通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['mno' => 'SXP-MNO-WRONG']))), '随行付错商户通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['orgId' => 'SXP-ORG-WRONG']))), '随行付返回机构号不匹配必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['payWay' => '02']))), '随行付小程序通知不能按公众号产品入账');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['uuid' => 'SXP-UUID-WRONG']))), '随行付渠道单号不匹配必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest(array_replace($payload, ['bizCode' => 'PAYING']))), '随行付非成功通知不得推进成功');

        $badSignature = $this->suixingpayPlugin(new SuixingpayUnitClient(fn (): array => [], false), [], $repository);
        $this->assertThrows(fn () => $badSignature->notify($this->rawJsonRequest($payload)), '随行付签名错误通知必须拒绝');
    }

    /**
     * 随行付查询状态、当前与兼容协议关单路径，以及退款幂等状态。
     */
    private function testSuixingpayQueryCloseRefundIdempotency(): void
    {
        $queryResponses = [
            ['bizCode' => '0000', 'ordNo' => 'P-SXP-STATE', 'oriTranAmt' => '1.00', 'tranSts' => 'SUCCESS', 'uuid' => 'SXP-UUID-STATE'],
            ['bizCode' => '0000', 'ordNo' => 'P-SXP-STATE', 'tranSts' => 'PAYING'],
            ['bizCode' => '0000', 'ordNo' => 'P-SXP-STATE', 'tranSts' => 'FAIL'],
            ['bizCode' => '0000', 'ordNo' => 'P-SXP-STATE', 'tranSts' => 'CLOSED'],
        ];
        $refundResponses = [
            ['bizCode' => '0000', 'ordNo' => 'R-SXP-STATE', 'origOrderNo' => 'P-SXP-STATE', 'amt' => '0.50', 'tranSts' => 'REFUNDING'],
            ['bizCode' => '0000', 'ordNo' => 'R-SXP-STATE', 'origOrderNo' => 'P-SXP-STATE', 'amt' => '0.50', 'tranSts' => 'REFUNDSUC'],
            ['bizCode' => '0000', 'ordNo' => 'R-SXP-STATE', 'origOrderNo' => 'P-SXP-STATE', 'amt' => '0.50', 'tranSts' => 'REFUNDSUC'],
        ];
        $client = new SuixingpayUnitClient(function (string $path) use (&$queryResponses, &$refundResponses): array {
            return match ($path) {
                SuixingpayClient::PATH_TRADE_QUERY => array_shift($queryResponses) ?? ['bizCode' => '0000', 'ordNo' => 'P-SXP-STATE', 'tranSts' => 'CLOSED'],
                SuixingpayClient::PATH_CLOSE_CURRENT => ['bizCode' => '0000', 'ordNo' => 'P-SXP-STATE', 'tranSts' => 'CLOSED'],
                SuixingpayClient::PATH_REFUND => array_shift($refundResponses) ?? ['bizCode' => '0000', 'ordNo' => 'R-SXP-STATE', 'origOrderNo' => 'P-SXP-STATE', 'amt' => '0.50', 'tranSts' => 'REFUNDSUC'],
                SuixingpayClient::PATH_REFUND_QUERY => ['bizCode' => '0000', 'ordNo' => 'R-SXP-STATE', 'origOrderNo' => 'P-SXP-STATE', 'refundAmount' => '0.50', 'tranSts' => 'REFUNDING'],
                default => ['bizCode' => '0000'],
            };
        });
        $plugin = $this->suixingpayPlugin($client);
        $order = ['pay_no' => 'P-SXP-STATE', 'amount' => 100, 'chan_trade_no' => 'SXP-UUID-STATE'];
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->query($order)['status'], '随行付 SUCCESS 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->query($order)['status'], '随行付 PAYING 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::FAILED, (string) $plugin->query($order)['status'], '随行付 FAIL 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->query($order)['status'], '随行付 CLOSED 查单映射错误');

        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '随行付当前版关单应成功');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '随行付重复关单应保持幂等成功');
        $closeCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === SuixingpayClient::PATH_CLOSE_CURRENT));
        $this->assertSame(2, count($closeCalls), '随行付当前版必须固定使用 /query/close');
        $this->assertSame($closeCalls[0]['data'], $closeCalls[1]['data'], '随行付重复关单必须使用同一原订单号');

        $refundOrder = ['pay_no' => 'P-SXP-STATE', 'refund_no' => 'R-SXP-STATE', 'refund_amount' => 50];
        $pending = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], '随行付 REFUNDING 应映射 pending');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refundOrder)['status'], '随行付 REFUNDSUC 应确认退款成功');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refundOrder)['status'], '同一退款单号重放应保持幂等成功');
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === SuixingpayClient::PATH_REFUND));
        $this->assertSame($refundCalls[0]['data'], $refundCalls[1]['data'], '随行付退款重试必须复用 ordNo/origOrderNo/amt');
        $this->assertSame($refundCalls[1]['data'], $refundCalls[2]['data'], '随行付退款重放不得生成新上游退款号');
        $this->assertSame('0.50', (string) $refundCalls[0]['data']['amt'], '随行付退款金额必须由整数分精确转元');
        $badAmountClient = new SuixingpayUnitClient(fn (): array => [
            'bizCode' => '0000',
            'ordNo' => 'P-SXP-BAD-AMOUNT',
            'oriTranAmt' => '1.01',
            'tranSts' => 'SUCCESS',
            'uuid' => 'SXP-UUID-BAD-AMOUNT',
        ]);
        $badAmount = $this->suixingpayPlugin($badAmountClient);
        $this->assertThrows(fn () => $badAmount->query(['pay_no' => 'P-SXP-BAD-AMOUNT', 'amount' => 100]), '随行付查单金额不一致必须拒绝');
        $sdkError = $this->suixingpayPlugin(new SuixingpayUnitClient(
            fn (): Throwable => new \app\common\sdk\suixingpay\SuixingpaySdkException('unit gateway error')
        ));
        $this->assertThrows(
            fn () => $sdkError->query(['pay_no' => 'P-SXP-SDK-ERROR', 'amount' => 100]),
            '随行付 SDK 错误必须转换为 PaymentException 而不是返回伪业务状态'
        );

        $legacyClient = new SuixingpayUnitClient(function (string $path): array {
            if ($path === SuixingpayClient::PATH_CANCEL_LEGACY) {
                return ['bizCode' => '0000', 'ordNo' => 'P-SXP-STATE', 'tranSts' => 'CANCELED'];
            }
            return ['bizCode' => '0000', 'ordNo' => 'P-SXP-BANK-PC', 'payUrl' => 'https://suixingpay.unit.test/legacy', 'uuid' => 'SXP-UUID-LEGACY'];
        });
        $legacy = $this->suixingpayPlugin($legacyClient, ['suixingpay_api_profile' => 'rainbow_legacy']);
        $legacy->pay($this->suixingpayOrder('bank', 'pc'));
        $this->assertSame(SuixingpayClient::PATH_ACTIVE_SCAN_LEGACY, $legacyClient->calls[0]['path'], '彩虹 suixingpay 旧档位必须固定 /order/activeScan');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $legacy->close($order)['status'], '彩虹 suixingpay 兼容档位撤销应映射关单成功');
        $this->assertSame(SuixingpayClient::PATH_CANCEL_LEGACY, $legacyClient->calls[1]['path'], '彩虹 suixingpay 旧档位必须固定 /query/cancel');
    }

    /**
     * 杉德 AES/RSA 固定向量、响应验签解密、严格 Base64 与证书解析。
     */
    private function testSandpaySdkCryptoAndCertificates(): void
    {
        $fixture = $this->sandpayCertificateFixture();
        $wrongFixture = $this->sandpayCertificateFixture();
        try {
            $client = new SandpayClient([
                'merchant_no' => 'SAND-MERCHANT-001',
                'merchant_cert_no' => 'CERT-001',
                'private_cert_password' => 'unit-pass',
                'public_cert_path' => $fixture['platform_cert_path'],
                'private_cert_path' => $fixture['merchant_pfx_path'],
                'sandbox' => true,
            ]);
            $aesEncrypt = $this->privateMethod(SandpayClient::class, 'aesEncrypt');
            $aesDecrypt = $this->privateMethod(SandpayClient::class, 'aesDecrypt');
            $plain = '{"amount":"1.00","mid":"M100"}';
            $cipher = $aesEncrypt->invoke($client, $plain, '0123456789ABCDEF');
            $this->assertSame(
                '6/jE8SWEheEJjL0JTcUqHoissQLLcwxFABjcPfmwa6o=',
                $cipher,
                '杉德 AES-128-ECB/PKCS#7/Base64 固定向量变化'
            );
            $this->assertSame($plain, $aesDecrypt->invoke($client, $cipher, '0123456789ABCDEF'), '杉德 AES 固定向量不能解回 UTF-8 明文');
            $this->assertThrows(
                fn () => $aesEncrypt->invoke($client, $plain, 'short-key'),
                '杉德 AES 密钥必须严格限制为 16 位 ASCII 字母数字'
            );

            $payload = $client->buildEncryptedPayload(
                ['z' => 2, 'a' => '中文'],
                '0123456789ABCDEF',
                '2026-07-16 12:00:00'
            );
            $this->assertSame('SAND-MERCHANT-001', (string) $payload['accessMid'], '杉德外层 accessMid 错误');
            $this->assertSame('4.0.0', (string) $payload['version'], '杉德 V4 版本字段错误');
            $this->assertSame('RSA', (string) $payload['signType'], '杉德签名算法字段错误');
            $this->assertSame('AES', (string) $payload['encryptType'], '杉德加密算法字段错误');
            $this->assertSame('CERT-001', (string) $payload['certNo'], '配置证书序列号时必须发送 certNo');
            $encryptedKey = base64_decode((string) $payload['encryptKey'], true);
            $unwrapped = '';
            $this->assertTrue(
                is_string($encryptedKey)
                && openssl_private_decrypt($encryptedKey, $unwrapped, $fixture['platform_private_key'], OPENSSL_PKCS1_PADDING),
                '杉德 encryptKey 必须使用平台 RSA/PKCS1Padding 加密'
            );
            $this->assertSame('0123456789ABCDEF', $unwrapped, '杉德 RSA 解封 AES 密钥结果错误');
            $this->assertSame(
                '{"a":"中文","z":2}',
                $aesDecrypt->invoke($client, (string) $payload['bizData'], '0123456789ABCDEF'),
                '杉德业务 JSON 顶层字段必须排序并保持 UTF-8/不转义中文'
            );
            $signature = base64_decode((string) $payload['sign'], true);
            $this->assertSame(
                1,
                openssl_verify(
                    (string) $payload['bizData'],
                    is_string($signature) ? $signature : '',
                    $fixture['merchant_public_key'],
                    OPENSSL_ALGO_SHA256
                ),
                '杉德请求必须对 Base64 bizData 字符串执行 RSA-SHA256 签名'
            );

            $responseKey = 'FEDCBA9876543210';
            $responseBizData = $aesEncrypt->invoke(
                $client,
                '{"amount":"1.00","mid":"SAND-MERCHANT-001","resultStatus":"success"}',
                $responseKey
            );
            $responseEncryptedKey = '';
            $this->assertTrue(
                openssl_public_encrypt($responseKey, $responseEncryptedKey, $fixture['merchant_public_key'], OPENSSL_PKCS1_PADDING),
                '测试响应 AES 密钥封装失败'
            );
            $responseSignature = '';
            $this->assertTrue(
                openssl_sign($responseBizData, $responseSignature, $fixture['platform_private_key'], OPENSSL_ALGO_SHA256),
                '测试响应签名失败'
            );
            $envelope = [
                'respCode' => 'success',
                'accessMid' => 'SAND-MERCHANT-001',
                'version' => '4.0.0',
                'signType' => 'RSA',
                'encryptType' => 'AES',
                'bizData' => $responseBizData,
                'encryptKey' => base64_encode($responseEncryptedKey),
                'sign' => base64_encode($responseSignature),
            ];
            $decryptResponse = $this->privateMethod(SandpayClient::class, 'decryptResponse');
            $decoded = $decryptResponse->invoke($client, $envelope);
            $this->assertSame('1.00', (string) $decoded['amount'], '杉德响应必须先验签、RSA 解封再 AES 解密');
            $this->assertThrows(
                fn () => $decryptResponse->invoke($client, array_replace($envelope, ['sign' => '%%%'])),
                '杉德响应畸形 Base64 签名必须拒绝'
            );
            $badCipher = base64_encode(str_repeat("\0", 16));
            $badCipherSignature = '';
            openssl_sign($badCipher, $badCipherSignature, $fixture['platform_private_key'], OPENSSL_ALGO_SHA256);
            $this->assertThrows(
                fn () => $decryptResponse->invoke($client, array_replace($envelope, [
                    'bizData' => $badCipher,
                    'sign' => base64_encode($badCipherSignature),
                ])),
                '杉德响应错误 AES padding/密文必须拒绝'
            );

            $wrongCertClient = new SandpayClient([
                'merchant_no' => 'SAND-MERCHANT-001',
                'private_cert_password' => 'unit-pass',
                'public_cert_path' => $wrongFixture['platform_cert_path'],
                'private_cert_path' => $fixture['merchant_pfx_path'],
                'sandbox' => true,
            ]);
            $this->assertFalse(
                $wrongCertClient->verify($responseBizData, base64_encode($responseSignature)),
                '用错误杉德平台证书必须验签失败'
            );
            $this->assertThrows(
                fn () => new SandpayClient([
                    'merchant_no' => 'SAND-MERCHANT-001',
                    'private_cert_password' => 'wrong-pass',
                    'public_cert_path' => $fixture['platform_cert_path'],
                    'private_cert_path' => $fixture['merchant_pfx_path'],
                ]),
                '错误 PFX 密码必须在 SDK 初始化时失败'
            );
            $this->assertThrows(
                fn () => new SandpayClient([
                    'merchant_no' => 'SAND-MERCHANT-001',
                    'private_cert_password' => 'unit-pass',
                    'public_cert_path' => $fixture['malformed_cert_path'],
                    'private_cert_path' => $fixture['merchant_pfx_path'],
                ]),
                '畸形杉德公钥证书必须在 SDK 初始化时失败'
            );
        } finally {
            $this->cleanupSandpayCertificateFixture($fixture);
            $this->cleanupSandpayCertificateFixture($wrongFixture);
        }
    }

    /**
     * 杉德产品常量、私有证书 object_key、身份作用域与 presentation。
     */
    private function testSandpayProductIdentityAndPrivateAssets(): void
    {
        $client = new SandpayUnitClient(static function (string $path, array $data, int $index): array {
            if ($path !== SandpayClient::PATH_ORDER_CREATE) {
                return [];
            }
            $credential = match (true) {
                (string) $data['payMode'] === 'QR' => ['qrCode' => 'https://sandpay.unit.test/qrcode/' . $index],
                (string) $data['payType'] === 'ALIPAY' => ['tradeNo' => 'ALI-TRADE-' . $index],
                default => [
                    'appId' => (string) ($data['payerInfo']['subAppId'] ?? ''),
                    'timeStamp' => '1700000000',
                    'nonceStr' => 'nonce-' . $index,
                    'package' => 'prepay_id=SANDPAY-' . $index,
                    'signType' => 'RSA',
                    'paySign' => 'pay-sign-' . $index,
                ],
            };

            return [
                'resultStatus' => 'success',
                'mid' => 'SAND-MERCHANT-001',
                'outOrderNo' => (string) $data['outOrderNo'],
                'amount' => (string) $data['amount'],
                'sandSerialNo' => 'SAND-SERIAL-' . $index,
                'credential' => $credential,
            ];
        });
        $plugin = $this->sandpayPlugin($client);
        $this->assertTrue($plugin instanceof RefundQueryInterface, '杉德必须声明退款查询能力');
        $this->assertTrue($plugin instanceof RefundNotifyInterface, '杉德必须声明退款通知能力');
        $this->assertTrue((new JeepayApiPayment()) instanceof RefundNotifyInterface, 'Jeepay 已有官方独立退款通知证据，必须声明并验签退款通知能力');

        $schema = [];
        foreach ($plugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $products = array_map(
            static fn (array $option): string => (string) ($option['value'] ?? ''),
            (array) ($schema['enabled_products']['options'] ?? [])
        );
        $this->assertSame(
            ['alipay_scan', 'alipay_jsapi', 'wxpay_scan', 'wxpay_mp', 'wxpay_mini', 'bank_scan'],
            $products,
            '杉德 PRODUCT 常量、配置开关与运行时映射必须一致'
        );
        foreach (['public_cert_path', 'private_cert_path'] as $field) {
            $upload = (array) ($schema[$field]['props']['fileUpload'] ?? []);
            $this->assertSame(2, (int) ($upload['scene'] ?? 0), '杉德证书上传必须使用 certificate 场景');
            $this->assertSame(2, (int) ($upload['visibility'] ?? 0), '杉德证书上传必须是 private');
            $this->assertSame(1, (int) ($upload['storageEngine'] ?? 0), '杉德证书上传必须固定本地私有存储');
            $this->assertSame('object_key', (string) ($upload['getKey'] ?? ''), '杉德证书上传必须回填 object_key');
        }
        $this->assertFalse(isset($schema['api_base_url']), '杉德插件不得暴露自定义网关造成 SSRF/协议漂移');

        $alipayRequirement = $plugin->identityRequirement($this->sandpayOrder('alipay', 'alipay', ['buyer_open_id' => 'not-user-id']));
        $this->assertSame('buyer_id', (string) ($alipayRequirement['identity_field'] ?? ''), '杉德支付宝 JSAPI 必须严格取得 buyer_id');
        $this->assertSame([], (array) ($alipayRequirement['identity_aliases'] ?? []), '杉德支付宝身份不得声明 buyer_open_id 别名');
        $mpRequirement = $plugin->identityRequirement($this->sandpayOrder('wxpay', 'wechat'));
        $this->assertSame('openid', (string) ($mpRequirement['identity_field'] ?? ''), '杉德公众号身份字段错误');
        $miniRequirement = $plugin->identityRequirement($this->sandpayOrder('wxpay', 'mobile', ['is_mini' => true]));
        $this->assertSame('mini_openid', (string) ($miniRequirement['identity_field'] ?? ''), '杉德小程序身份字段错误');
        $this->assertSame('wx-sandpay-mini', (string) ($miniRequirement['app_id'] ?? ''), '杉德小程序身份必须绑定小程序 AppID');
        $this->assertThrows(
            fn () => $plugin->identityRequirement($this->sandpayOrder('wxpay', 'wechat', ['sub_appid' => 'wx-wrong'])),
            '杉德公众号 OpenID 的 AppID 作用域不匹配必须拒绝'
        );

        foreach ([
            ['alipay', 'pc', [], 'alipay_scan', 'ALIPAY', 'QR'],
            ['wxpay', 'pc', [], 'wxpay_scan', 'WXPAY', 'QR'],
            ['bank', 'pc', [], 'bank_scan', 'CUPPAY', 'QR'],
            ['alipay', 'alipay', ['buyer_id' => '20880001', 'app_id' => 'ali-sandpay-app'], 'alipay_jsapi', 'ALIPAY', 'JSAPI'],
            ['wxpay', 'wechat', ['openid' => 'wx-mp-openid', 'sub_appid' => 'wx-sandpay-mp'], 'wxpay_mp', 'WXPAY', 'JSAPI'],
            ['wxpay', 'mobile', ['is_mini' => true, 'mini_openid' => 'wx-mini-openid', 'sub_appid' => 'wx-sandpay-mini'], 'wxpay_mini', 'WXPAY', 'MINI'],
        ] as [$payType, $env, $payment, $product, $gatewayType, $gatewayMode]) {
            $result = $plugin->pay($this->sandpayOrder($payType, $env, $payment));
            $call = $client->calls[array_key_last($client->calls)];
            $this->assertSame($product, (string) $result['pay_product'], '杉德支付产品派发错误：' . $product);
            $this->assertSame($gatewayType, (string) $call['data']['payType'], '杉德 payType 映射错误：' . $product);
            $this->assertSame($gatewayMode, (string) $call['data']['payMode'], '杉德 payMode 映射错误：' . $product);
            $this->assertSame('1.00', (string) $call['data']['amount'], '杉德上送金额必须由整数分格式化为两位元字符串');
            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertFalse(is_string($encoded) && str_contains($encoded, '"raw"'), '杉德 presentation 不得携带 raw 原始响应');
        }

        $fixture = $this->sandpayCertificateFixture();
        try {
            $resolver = $this->privateMethod(SandpayApiPayment::class, 'privateCertificatePath');
            $this->assertThrows(fn () => $resolver->invoke($plugin, 'C:/secret/merchant.pfx', ['pfx']), '杉德证书配置不得接受绝对路径');
            $this->assertThrows(fn () => $resolver->invoke($plugin, 'https://example.test/cert.cer', ['cer']), '杉德证书配置不得接受公开 URL');
            $this->assertThrows(fn () => $resolver->invoke($plugin, 'storage/private/certificate/../secret.pfx', ['pfx']), '杉德证书配置不得接受路径穿越');
            $resolved = $resolver->invoke($plugin, $fixture['merchant_pfx_object_key'], ['pfx', 'p12']);
            $this->assertSame(realpath($fixture['merchant_pfx_path']), $resolved, '合法私有证书 object_key 必须解析到受控本机路径');
            $this->assertFalse(str_contains(json_encode($plugin->getConfigSchema()) ?: '', $resolved), '插件配置展示不得暴露证书绝对路径');
        } finally {
            $this->cleanupSandpayCertificateFixture($fixture);
        }
    }

    /**
     * 杉德支付通知的验签、订单关联、金额、币种、产品、交易号与重放校验。
     */
    private function testSandpayNotifyBusinessValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-SANDPAY-NOTIFY',
            'pay_amount' => 100,
            'channel_id' => 66,
            'channel_trade_no' => 'SAND-TXN-NOTIFY',
            'ext_json' => ['payment_context' => ['pay_product' => 'wxpay_mini']],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return hash_equals((string) $this->payOrder->pay_no, $payNo) ? $this->payOrder : null;
            }
        };
        $plugin = $this->sandpayPlugin(new SandpayUnitClient(fn (): array => []), [], $repository);
        $payload = [
            'eventType' => 'recv',
            'mid' => 'SAND-MERCHANT-001',
            'marketProduct' => 'QZF',
            'outOrderNo' => 'P-SANDPAY-NOTIFY',
            'orderStatus' => 'success',
            'amount' => '1.00',
            'currency' => 'CNY',
            'payType' => 'WXPAY',
            'payMode' => 'MINI',
            'sandSerialNo' => 'SAND-TXN-NOTIFY',
            'finishedTime' => '20260716123045',
        ];
        $request = $this->sandpayNotifyRequest($payload);
        $result = $plugin->notify($request);
        $replayed = $plugin->notify($this->sandpayNotifyRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '杉德成功通知应归一为 success');
        $this->assertSame('P-SANDPAY-NOTIFY', (string) $result['pay_no'], '杉德通知必须返回 pay_no');
        $this->assertSame(100, (int) $result['paid_amount'], '杉德通知必须返回 MPAY 分金额');
        $this->assertSame($result, $replayed, '同一杉德通知重放应得到相同结果且插件不得改订单/资金');

        foreach ([
            ['mid', 'SAND-WRONG', '错商户'],
            ['outOrderNo', 'P-SANDPAY-WRONG', '错单号'],
            ['amount', '1.01', '错金额'],
            ['currency', 'USD', '错币种'],
            ['orderStatus', 'process', '非成功状态'],
            ['sandSerialNo', 'SAND-TXN-WRONG', '错渠道交易号'],
            ['payMode', 'JSAPI', '公众号/小程序产品混用'],
            ['eventType', 'refund', '退款事件进入支付通知'],
        ] as [$field, $value, $scene]) {
            $this->assertThrows(
                fn () => $plugin->notify($this->sandpayNotifyRequest(array_replace($payload, [$field => $value]))),
                '杉德' . $scene . '通知必须拒绝'
            );
        }
        $malformed = $this->rawFormRequest(['bizData' => '{not-json', 'sign' => 'valid-sign']);
        $this->assertThrows(fn () => $plugin->notify($malformed), '杉德已验签但畸形 JSON 通知必须拒绝');
        $badSignature = $this->sandpayPlugin(new SandpayUnitClient(fn (): array => [], false), [], $repository);
        $this->assertThrows(fn () => $badSignature->notify($request), '杉德错误平台证书/签名通知必须拒绝');
    }

    /**
     * 杉德查单、明确不支持关单、退款受理/终态、退款查询与独立退款通知。
     */
    private function testSandpayQueryRefundAndRefundNotify(): void
    {
        $refundResponses = ['accept', 'success'];
        $client = new SandpayUnitClient(static function (string $path, array $data) use (&$refundResponses): array {
            if ($path === SandpayClient::PATH_ORDER_REFUND) {
                $status = array_shift($refundResponses) ?? 'success';
                return [
                    'resultStatus' => $status,
                    'mid' => 'SAND-MERCHANT-001',
                    'outOrderNo' => (string) $data['outOrderNo'],
                    'oriOutOrderNo' => (string) $data['oriOutOrderNo'],
                    'amount' => (string) $data['amount'],
                    'sandSerialNo' => 'SAND-REFUND-001',
                ];
            }
            if ($path === SandpayClient::PATH_ORDER_QUERY && (string) $data['outOrderNo'] === 'R-SANDPAY-STATE') {
                return [
                    'resultStatus' => 'success',
                    'orderStatus' => 'process',
                    'mid' => 'SAND-MERCHANT-001',
                    'outOrderNo' => 'R-SANDPAY-STATE',
                    'oriOutOrderNo' => 'P-SANDPAY-STATE',
                    'amount' => '0.50',
                    'sandSerialNo' => 'SAND-REFUND-001',
                ];
            }

            return [
                'resultStatus' => 'success',
                'orderStatus' => 'success',
                'mid' => 'SAND-MERCHANT-001',
                'outOrderNo' => 'P-SANDPAY-STATE',
                'amount' => '1.00',
                'sandSerialNo' => 'SAND-PAY-STATE',
                'finishedTime' => '20260716123045',
            ];
        });
        $plugin = $this->sandpayPlugin($client);
        $query = $plugin->query([
            'pay_no' => 'P-SANDPAY-STATE',
            'amount' => 100,
            'chan_trade_no' => 'SAND-PAY-STATE',
        ]);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $query['status'], '杉德成功查单状态映射错误');
        $this->assertFalse(array_key_exists('raw_data', $query), '杉德查单不得返回解密后的 raw_data');
        $this->assertThrows(
            fn () => $plugin->close(['pay_no' => 'P-SANDPAY-STATE']),
            '杉德无官方关单接口时必须明确抛出不支持异常'
        );

        $refundOrder = [
            'pay_no' => 'P-SANDPAY-STATE',
            'pay_amount' => 100,
            'refund_no' => 'R-SANDPAY-STATE',
            'refund_amount' => 50,
            'refund_callback_url' => 'https://mpay.unit.test/api/pay/refund/R-SANDPAY-STATE/callback',
        ];
        $pending = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], '杉德 accept 必须映射 pending');
        $success = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], '杉德 success 退款应确认终态成功');
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === SandpayClient::PATH_ORDER_REFUND));
        $this->assertSame($refundCalls[0]['data'], $refundCalls[1]['data'], '杉德相同退款号重试必须保持业务报文幂等');
        $this->assertSame(
            'https://mpay.unit.test/api/pay/refund/R-SANDPAY-STATE/callback',
            (string) $refundCalls[0]['data']['notifyUrl'],
            '杉德退款必须使用独立退款生命周期回调地址'
        );
        $refundQuery = $plugin->queryRefund($refundOrder + ['chan_refund_no' => 'SAND-REFUND-001']);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $refundQuery['status'], '杉德退款查询 process 必须保持 pending');
        $this->assertFalse(array_key_exists('raw_data', $refundQuery), '杉德退款查询不得暴露解密原文');

        $dispatch = (new ReflectionClass(RefundDispatchService::class))->newInstanceWithoutConstructor();
        $payModel = new PayOrder();
        $payModel->forceFill([
            'pay_no' => 'P-SANDPAY-STATE',
            'biz_no' => 'B-SANDPAY-STATE',
            'channel_id' => 66,
            'channel_trade_no' => 'SAND-PAY-STATE',
            'pay_amount' => 100,
            'ext_json' => [
                'payment_context' => [
                    'pay_type' => 'wxpay',
                    'pay_product' => 'wxpay_mini',
                    'pay_action' => 'orderCreate',
                    'channel_context' => [],
                ],
            ],
        ]);
        $refundModel = new RefundOrder();
        $refundModel->forceFill([
            'refund_no' => 'R-SANDPAY-STATE',
            'merchant_refund_no' => 'M-R-SANDPAY-STATE',
            'refund_amount' => 50,
            'chan_refund_no' => 'SAND-REFUND-001',
        ]);
        $buildPayload = $this->privateMethod(RefundDispatchService::class, 'buildPluginRefundPayload');
        $queryPayload = $buildPayload->invoke($dispatch, $payModel, $refundModel, false);
        $this->assertFalse(array_key_exists('refund_callback_url', $queryPayload), '无退款通知能力或主动查单时不得注入回调地址');
        $validateQuery = $this->privateMethod(RefundDispatchService::class, 'validateRefundResult');
        $validateQuery->invoke($dispatch, $refundModel, $payModel, $refundQuery, 'refund_status_result');
        $this->assertThrows(
            fn () => $validateQuery->invoke(
                $dispatch,
                $refundModel,
                $payModel,
                array_replace($refundQuery, ['refund_amount' => 51]),
                'refund_status_result'
            ),
            '退款主动查询必须校验退款金额'
        );

        $refundPayload = [
            'eventType' => 'refund',
            'mid' => 'SAND-MERCHANT-001',
            'marketProduct' => 'QZF',
            'outOrderNo' => 'R-SANDPAY-STATE',
            'oriOutOrderNo' => 'P-SANDPAY-STATE',
            'orderStatus' => 'success',
            'amount' => '0.50',
            'currency' => 'CNY',
            'sandSerialNo' => 'SAND-REFUND-001',
        ];
        $notify = $plugin->refundNotify($this->sandpayNotifyRequest($refundPayload), $refundOrder + [
            'chan_refund_no' => 'SAND-REFUND-001',
        ]);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $notify['status'], '杉德退款通知 success 状态映射错误');
        $this->assertSame('R-SANDPAY-STATE', (string) $notify['refund_no'], '杉德退款通知必须关联退款单而非推进支付单');
        $this->assertThrows(
            fn () => $plugin->refundNotify(
                $this->sandpayNotifyRequest(array_replace($refundPayload, ['oriOutOrderNo' => 'P-WRONG'])),
                $refundOrder + ['chan_refund_no' => 'SAND-REFUND-001']
            ),
            '杉德退款通知原支付单号不匹配必须拒绝'
        );
        $this->assertThrows(
            fn () => $plugin->refundNotify(
                $this->sandpayNotifyRequest(array_replace($refundPayload, ['amount' => '0.51'])),
                $refundOrder + ['chan_refund_no' => 'SAND-REFUND-001']
            ),
            '杉德退款通知错金额必须拒绝'
        );

        $wrongQuery = $this->sandpayPlugin(new SandpayUnitClient(static fn (): array => [
            'resultStatus' => 'success',
            'orderStatus' => 'success',
            'mid' => 'SAND-MERCHANT-001',
            'outOrderNo' => 'P-WRONG',
            'amount' => '1.01',
            'sandSerialNo' => 'SAND-PAY-STATE',
        ]));
        $this->assertThrows(
            fn () => $wrongQuery->query(['pay_no' => 'P-SANDPAY-STATE', 'amount' => 100]),
            '杉德查单错单号/错金额必须拒绝'
        );
        $failed = $this->sandpayPlugin(new SandpayUnitClient(static fn (): SandpaySdkException => new SandpaySdkException('unit failure')));
        $this->assertThrows(
            fn () => $failed->query(['pay_no' => 'P-SANDPAY-STATE', 'amount' => 100]),
            '杉德业务/SDK 失败必须抛 PaymentException 而不是返回伪成功'
        );
    }

    /**
     * 杉德退款回调只推进退款生命周期，并对重复通知保持幂等入口语义。
     */
    private function testSandpayRefundCallbackLifecycle(): void
    {
        $refundOrder = new RefundOrder();
        $refundOrder->forceFill([
            'refund_no' => 'R-SANDPAY-CALLBACK',
            'pay_no' => 'P-SANDPAY-CALLBACK',
            'refund_amount' => 50,
            'channel_id' => 66,
            'channel_refund_no' => 'SAND-REFUND-CALLBACK',
        ]);
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-SANDPAY-CALLBACK',
            'pay_amount' => 100,
            'channel_id' => 66,
            'channel_trade_no' => 'SAND-PAY-CALLBACK',
            'status' => 2,
        ]);
        $refundRepository = new class($refundOrder) extends RefundOrderRepository {
            public function __construct(private RefundOrder $refundOrder) {}

            public function findByRefundNo(string $refundNo, array $columns = ['*'])
            {
                return hash_equals((string) $this->refundOrder->refund_no, $refundNo) ? $this->refundOrder : null;
            }
        };
        $payRepository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return hash_equals((string) $this->payOrder->pay_no, $payNo) ? $this->payOrder : null;
            }
        };
        $plugin = $this->sandpayPlugin(new SandpayUnitClient(fn (): array => []));
        $manager = new class($plugin) extends PaymentPluginManager {
            public function __construct(private SandpayApiPayment $plugin) {}

            public function createByPayOrder(PayOrder $payOrder, bool $allowDisabled = true): \app\common\interface\PaymentInterface&\app\common\interface\PayPluginInterface
            {
                return $this->plugin;
            }
        };
        $lifecycle = new class($refundOrder) extends RefundLifecycleService {
            public int $successCalls = 0;
            public int $failedCalls = 0;

            public function __construct(private RefundOrder $refundOrder) {}

            public function recordRefundProgress(string $refundNo, string $channelRefundNo = ''): RefundOrder
            {
                return $this->refundOrder;
            }

            public function markRefundSuccess(string $refundNo, array $input = []): RefundOrder
            {
                $this->successCalls++;
                return $this->refundOrder;
            }

            public function markRefundFailed(string $refundNo, array $input = []): RefundOrder
            {
                $this->failedCalls++;
                return $this->refundOrder;
            }
        };
        $notifyService = new class extends NotifyService {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function __construct()
            {
            }

            public function recordPayCallback(array $input): ?\app\model\admin\PayCallbackLog
            {
                $this->records[] = $input;

                return null;
            }
        };
        $service = new RefundOrderCallbackService(
            $refundRepository,
            $payRepository,
            $manager,
            $lifecycle,
            $notifyService
        );
        $payload = [
            'eventType' => 'refund',
            'mid' => 'SAND-MERCHANT-001',
            'marketProduct' => 'QZF',
            'outOrderNo' => 'R-SANDPAY-CALLBACK',
            'oriOutOrderNo' => 'P-SANDPAY-CALLBACK',
            'orderStatus' => 'success',
            'amount' => '0.50',
            'currency' => 'CNY',
            'sandSerialNo' => 'SAND-REFUND-CALLBACK',
        ];
        $beforePayStatus = (int) $payOrder->status;
        $this->assertSame(
            'respCode=000000',
            $service->handlePluginCallback('R-SANDPAY-CALLBACK', $this->sandpayNotifyRequest($payload)),
            '杉德合法退款通知必须返回协议成功 ACK'
        );
        $this->assertSame(
            'respCode=000000',
            $service->handlePluginCallback('R-SANDPAY-CALLBACK', $this->sandpayNotifyRequest($payload)),
            '杉德退款通知重放必须保持成功 ACK，由退款生命周期幂等收口'
        );
        $this->assertSame(2, $lifecycle->successCalls, '杉德退款通知必须只调用退款成功生命周期入口');
        $this->assertSame(0, $lifecycle->failedCalls, '杉德成功退款通知不得推进退款失败');
        $this->assertSame($beforePayStatus, (int) $payOrder->status, '杉德退款通知服务不得直接推进支付单');

        $failureAck = $service->handlePluginCallback(
            'R-SANDPAY-CALLBACK',
            $this->sandpayNotifyRequest(array_replace($payload, ['amount' => '0.51']))
        );
        $this->assertSame('respCode=020002', $failureAck, '杉德错金额退款通知必须返回失败 ACK');
        $this->assertSame(2, $lifecycle->successCalls, '杉德错金额退款通知不得触发退款成功生命周期');
        $this->assertSame(3, count($notifyService->records), '每次退款回调都必须留下一条数据库审计记录');
        $this->assertSame('R-SANDPAY-CALLBACK', $notifyService->records[0]['refund_no'], '退款回调日志必须关联退款单号');
        $this->assertSame(
            NotifyConstant::VERIFY_STATUS_SUCCESS,
            (int) $notifyService->records[0]['verify_status'],
            '合法退款通知应记录为验证成功'
        );
        $this->assertSame(
            NotifyConstant::PROCESS_STATUS_SUCCESS,
            (int) $notifyService->records[0]['process_status'],
            '合法退款通知应记录为处理成功'
        );
        $this->assertSame(
            NotifyConstant::VERIFY_STATUS_FAILED,
            (int) $notifyService->records[2]['verify_status'],
            '插件在返回标准结果前拒绝错误金额时应记录为验证失败'
        );
        $this->assertSame(
            NotifyConstant::PROCESS_STATUS_FAILED,
            (int) $notifyService->records[2]['process_status'],
            '错误金额退款通知不得记录为处理成功'
        );
    }

    /**
     * 通联请求公共字段、SHA1WithRSA 签名、成功响应验签和商户身份绑定。
     */
    private function testAllinpaySdkRsaAndResponseSignature(): void
    {
        $merchantPair = RsaKeyPairGenerator::generate(2048);
        $platformPair = RsaKeyPairGenerator::generate(2048);
        $client = new AllinpayClient([
            'merchant_no' => 'CUS-ALLINPAY',
            'app_id' => 'APP-ALLINPAY',
            'merchant_private_key' => $merchantPair['private_key'],
            'platform_public_key' => $platformPair['public_key'],
        ]);

        $request = $client->buildSignedPayload(['reqsn' => 'P-ALLINPAY-SIGN', 'trxamt' => '100']);
        $this->assertSame('CUS-ALLINPAY', (string) $request['cusid'], '通联请求必须携带商户号');
        $this->assertSame('APP-ALLINPAY', (string) $request['appid'], '通联请求必须携带应用 ID');
        $this->assertSame('11', (string) $request['version'], '通联统一下单默认版本必须为 11');
        $this->assertSame('RSA', (string) $request['signtype'], '通联签名类型必须为 RSA');
        $this->assertTrue(trim((string) $request['randomstr']) !== '', '通联请求必须生成随机串');
        $protected = $client->buildSignedPayload([
            'cusid' => 'CUS-OVERRIDE',
            'appid' => 'APP-OVERRIDE',
            'version' => '99',
            'signtype' => 'MD5',
        ]);
        $this->assertSame('CUS-ALLINPAY', (string) $protected['cusid'], '业务参数不得覆盖通联商户号');
        $this->assertSame('APP-ALLINPAY', (string) $protected['appid'], '业务参数不得覆盖通联应用 ID');
        $this->assertSame('11', (string) $protected['version'], '业务参数不得覆盖通联接口版本');
        $this->assertSame('RSA', (string) $protected['signtype'], '业务参数不得降级通联签名算法');
        $this->assertSame(
            1,
            openssl_verify(
                $client->signingContent($request),
                base64_decode((string) $request['sign'], true) ?: '',
                $merchantPair['public_key'],
                OPENSSL_ALGO_SHA1
            ),
            '通联请求必须按 ASCII 字段排序使用 SHA1WithRSA 签名'
        );

        $success = [
            'retcode' => 'SUCCESS',
            'retmsg' => 'ok',
            'cusid' => 'CUS-ALLINPAY',
            'appid' => 'APP-ALLINPAY',
            'reqsn' => 'P-ALLINPAY-SIGN',
            'trxid' => 'T-ALLINPAY-SIGN',
            'trxstatus' => '0000',
            'randomstr' => 'response-random',
        ];
        $success['sign'] = $this->allinpayTestSign($client->signingContent($success), $platformPair['private_key']);
        $tampered = array_replace($success, ['trxstatus' => '3000']);
        $wrongIdentity = array_replace($success, ['cusid' => 'CUS-WRONG']);
        $wrongIdentity['sign'] = $this->allinpayTestSign($client->signingContent($wrongIdentity), $platformPair['private_key']);
        $handler = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($success, JSON_UNESCAPED_UNICODE)),
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($tampered, JSON_UNESCAPED_UNICODE)),
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($wrongIdentity, JSON_UNESCAPED_UNICODE)),
        ]);
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $handler]));

        $response = $client->submit('https://allinpay.unit.test/pay', ['reqsn' => 'P-ALLINPAY-SIGN', 'trxamt' => '100']);
        $this->assertSame('T-ALLINPAY-SIGN', (string) $response['trxid'], '通联已验签成功响应应返回业务数据');
        $this->assertThrows(
            fn () => $client->submit('https://allinpay.unit.test/pay', ['reqsn' => 'P-ALLINPAY-SIGN', 'trxamt' => '100']),
            '通联成功响应正文被篡改后必须拒绝'
        );
        $this->assertThrows(
            fn () => $client->submit('https://allinpay.unit.test/pay', ['reqsn' => 'P-ALLINPAY-SIGN', 'trxamt' => '100']),
            '通联成功响应即使签名正确也必须绑定本通道商户号和应用 ID'
        );
    }

    /**
     * 通联八产品、身份作用域、未开通过滤、付款码隔离和标准展示承接。
     */
    private function testAllinpayProductIdentityAndPresentation(): void
    {
        $client = new AllinpayUnitClient(static function (string $url, array $data): array {
            if (str_ends_with($url, '/unitorder/authcodetouserid')) {
                return ['acct' => 'UNION-USER-ID'];
            }
            if (!str_ends_with($url, '/unitorder/pay')) {
                return [];
            }

            $payType = (string) ($data['paytype'] ?? '');
            $payInfo = match ($payType) {
                'W02', 'W06' => json_encode([
                    'appId' => (string) ($data['sub_appid'] ?? ''),
                    'timeStamp' => '1700000000',
                    'nonceStr' => 'nonce',
                    'package' => 'prepay_id=ALLINPAY',
                    'signType' => 'RSA',
                    'paySign' => 'pay-sign',
                ], JSON_UNESCAPED_SLASHES),
                'U02' => 'https://unionpay.unit.test/jsapi',
                'A02' => 'ALI-TRADE-NO',
                default => 'https://qrcode.unit.test/' . strtolower($payType),
            };

            return [
                'reqsn' => (string) ($data['reqsn'] ?? ''),
                'trxid' => 'T-' . $payType,
                'trxstatus' => '0000',
                'payinfo' => $payInfo,
            ];
        });
        $plugin = $this->allinpayPlugin($client);

        $schema = [];
        foreach ($plugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $products = array_map(
            static fn (array $option): string => (string) ($option['value'] ?? ''),
            (array) ($schema['enabled_products']['options'] ?? [])
        );
        $this->assertSame([
            'alipay_scan', 'alipay_jsapi', 'wxpay_scan', 'wxpay_jsapi',
            'qqpay_scan', 'bank_scan', 'bank_jsapi', 'cashier',
        ], $products, '通联 PRODUCT、配置选项与运行时产品集合必须完全一致');
        $this->assertSame(['alipay', 'wxpay', 'qqpay', 'bank'], $plugin->getEnabledPayTypes(), '通联支付方式声明不完整');

        foreach ([
            ['alipay', 'alipay_scan', 'A01'],
            ['wxpay', 'wxpay_scan', 'W01'],
            ['qqpay', 'qqpay_scan', 'Q01'],
            ['bank', 'bank_scan', 'U01'],
        ] as [$payType, $product, $upstreamType]) {
            $result = $plugin->pay($this->allinpayOrder($payType, 'pc'));
            $this->assertSame($product, (string) $result['pay_product'], '通联扫码产品派发错误：' . $payType);
            $this->assertSame('qrcode', (string) $result['presentation']['pay_page'], '通联扫码必须返回标准二维码展示');
            $this->assertFalse(array_key_exists('raw', (array) $result['presentation']['pay_params']), '通联展示参数不得保留原始响应');
            $calls = array_values(array_filter(
                $client->calls,
                static fn (array $call): bool => (string) ($call['data']['paytype'] ?? '') === $upstreamType
            ));
            $this->assertSame(1, count($calls), '通联产品未映射到预期 paytype：' . $upstreamType);
            $this->assertSame('100', (string) $calls[0]['data']['trxamt'], '通联支付金额必须使用整数分');
        }

        $alipayRequirement = $plugin->identityRequirement($this->allinpayOrder('alipay', 'alipay', ['buyer_open_id' => 'open-id-not-user-id']));
        $this->assertSame('buyer_id', (string) ($alipayRequirement['identity_field'] ?? ''), '通联支付宝 A02 必须严格要求 user_id/buyer_id');
        $alipay = $plugin->pay($this->allinpayOrder('alipay', 'alipay', ['buyer_id' => '20880001']));
        $this->assertSame('alipay_jsapi', (string) $alipay['pay_product'], '通联支付宝身份续跑后必须固定 A02');
        $this->assertSame('jsapi', (string) $alipay['presentation']['pay_page'], '通联支付宝 A02 必须返回标准 JSAPI 参数');

        $wxRequirement = $plugin->identityRequirement($this->allinpayOrder('wxpay', 'wechat'));
        $this->assertSame('openid', (string) ($wxRequirement['identity_field'] ?? ''), '通联微信公众号 W02 必须要求公众号 openid');
        $wx = $plugin->pay($this->allinpayOrder('wxpay', 'wechat', ['openid' => 'WX-MP-OPENID']));
        $this->assertSame('wxpay_jsapi', (string) $wx['pay_product'], '通联微信公众号必须使用 wxpay_jsapi 产品');
        $wxCall = array_values(array_filter($client->calls, static fn (array $call): bool => ($call['data']['paytype'] ?? '') === 'W02'))[0];
        $this->assertSame('wx-mp-app', (string) $wxCall['data']['sub_appid'], 'W02 必须使用公众号 AppID');
        $this->assertSame('WX-MP-OPENID', (string) $wxCall['data']['acct'], 'W02 必须使用公众号 openid');

        $miniRequirement = $plugin->identityRequirement($this->allinpayOrder('wxpay', 'wechat', ['is_mini' => true]));
        $this->assertSame('mini_openid', (string) ($miniRequirement['identity_field'] ?? ''), '通联微信小程序 W06 必须使用独立身份字段');
        $mini = $plugin->pay($this->allinpayOrder('wxpay', 'wechat', ['is_mini' => true, 'mini_openid' => 'WX-MINI-OPENID']));
        $this->assertSame('page', (string) $mini['presentation']['pay_page'], '通联微信小程序必须交给标准承接页');
        $this->assertSame('wechatMini', (string) $mini['presentation']['pay_params']['_page'], '通联微信小程序承接页类型错误');
        $miniCall = array_values(array_filter($client->calls, static fn (array $call): bool => ($call['data']['paytype'] ?? '') === 'W06'))[0];
        $this->assertSame('wx-mini-app', (string) $miniCall['data']['sub_appid'], 'W06 必须使用小程序 AppID');
        $this->assertSame('WX-MINI-OPENID', (string) $miniCall['data']['acct'], 'W06 必须使用小程序 openid');

        $bankOrder = $this->allinpayOrder('bank', 'mobile', ['method' => 'jsapi']);
        $bankRequirement = $plugin->identityRequirement($bankOrder);
        $this->assertSame('unionpay_auth_code', (string) ($bankRequirement['identity_field'] ?? ''), '通联 U02 必须进入云闪付 userAuth');
        $identityService = (new ReflectionClass(\app\service\payment\identity\PaymentIdentityService::class))->newInstanceWithoutConstructor();
        $authUrl = (string) $this->privateMethod(\app\service\payment\identity\PaymentIdentityService::class, 'buildAuthUrl')
            ->invoke($identityService, 'allinpay-resume-token', $bankRequirement);
        $this->assertTrue(str_starts_with($authUrl, 'https://qr.95516.com/qrcGtwWeb-web/api/userAuth?'), '通联 U02 必须使用银联 userAuth');
        $this->assertTrue(str_contains($authUrl, 'identity%2Funionpay-callback'), '银联 userAuth 必须回到 MPAY 身份续跑回调');
        $bank = $plugin->pay($this->allinpayOrder('bank', 'mobile', [
            'method' => 'jsapi',
            'unionpay_auth_code' => 'UNION-AUTH-CODE',
        ]));
        $this->assertSame('bank_jsapi', (string) $bank['pay_product'], '通联云闪付身份续跑后必须固定 U02');
        $this->assertSame('jump', (string) $bank['presentation']['pay_page'], '通联 U02 必须返回标准跳转展示');
        $authCall = array_values(array_filter($client->calls, static fn (array $call): bool => str_ends_with($call['url'], '/authcodetouserid')))[0];
        $this->assertSame('UNION-AUTH-CODE', (string) $authCall['data']['authcode'], '云闪付授权码必须先换取 acct');
        $bankCall = array_values(array_filter($client->calls, static fn (array $call): bool => ($call['data']['paytype'] ?? '') === 'U02'))[0];
        $this->assertSame('UNION-USER-ID', (string) $bankCall['data']['acct'], 'U02 必须使用 authcodetouserid 返回的 acct');

        $this->assertThrows(
            fn () => $identityService->restoreInput([
                'input' => ['ext_json' => []],
                'requirement' => $alipayRequirement,
            ], ['buyer_open_id' => 'cannot-fallback']),
            '通联 A02 身份流程不得把 buyer_open_id 冒充 buyer_id'
        );

        $cashier = $plugin->pay($this->allinpayOrder('alipay', 'mobile'));
        $this->assertSame('cashier', (string) $cashier['pay_product'], '移动浏览器必须选择已开通的通联 H5 收银台');
        $this->assertSame('jump', (string) $cashier['presentation']['pay_page'], '通联 H5 收银台必须交给标准跳转承接');
        $this->assertSame('post', (string) $cashier['presentation']['pay_params']['method'], '通联 H5 收银台必须声明 POST 表单');
        $this->assertSame('12', (string) $cashier['presentation']['pay_params']['payload']['version'], '通联 H5 收银台版本必须为 12');
        $this->assertFalse(array_key_exists('html', (array) $cashier['presentation']['pay_params']), '插件不得直接输出 H5 收银台页面');

        $disabledClient = new AllinpayUnitClient(fn (): array => []);
        $disabled = $this->allinpayPlugin($disabledClient, ['enabled_products' => ['bank_scan']]);
        $this->assertThrows(fn () => $disabled->pay($this->allinpayOrder('alipay', 'pc')), '未开通通联产品必须在本地拒绝');
        $this->assertSame(0, count($disabledClient->calls), '未开通产品不得请求通联');

        $before = count($client->calls);
        $this->assertThrows(
            fn () => $plugin->pay($this->allinpayOrder('alipay', 'pc', ['auth_code' => '280000000000000000'])),
            '通联未实现付款码时不得把 auth_code 误派到主扫'
        );
        $this->assertSame($before, count($client->calls), '通联 auth_code 场景不得请求主扫接口');
    }

    /**
     * 通联通知验签后的商户、应用、订单、分金额、币种、成功态和核心关联校验。
     */
    private function testAllinpayNotifyBusinessValidation(): void
    {
        $plugin = $this->allinpayPlugin(new AllinpayUnitClient(fn (): array => []));
        $payload = [
            'appid' => 'APP-ALLINPAY',
            'cusid' => 'CUS-ALLINPAY',
            'cusorderid' => 'P-ALLINPAY-NOTIFY-A',
            'trxid' => 'T-ALLINPAY-NOTIFY',
            'initamt' => '100',
            'trxamt' => '100',
            'trxstatus' => '0000',
            'paytime' => '20260716123045',
            'signtype' => 'RSA',
            'sign' => 'valid-sign',
        ];
        $result = $plugin->notify($this->rawFormRequest($payload));
        $repeat = $plugin->notify($this->rawFormRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '通联 0000 通知必须归一为成功');
        $this->assertSame('P-ALLINPAY-NOTIFY-A', (string) $result['pay_no'], '通联通知必须返回真实支付单号');
        $this->assertSame(100, (int) $result['paid_amount'], '通联通知金额必须保持整数分');
        $this->assertSame('T-ALLINPAY-NOTIFY', (string) $result['chan_trade_no'], '通联通知必须返回渠道交易号');
        $this->assertSame($result, $repeat, '相同通联通知重放必须得到稳定结果并交由生命周期幂等处理');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        foreach ([
            ['appid', 'APP-WRONG', '错应用'],
            ['cusid', 'CUS-WRONG', '错商户'],
            ['initamt', '101', '原金额不一致'],
            ['trxamt', '1.00', '非整数分金额'],
            ['trxstatus', '2000', '非成功状态'],
            ['currency', 'USD', '非人民币'],
            ['trxid', '', '缺渠道交易号'],
            ['cusorderid', '', '缺订单号'],
        ] as [$field, $value, $label]) {
            $this->assertThrows(
                fn () => $plugin->notify($this->rawFormRequest(array_replace($payload, [$field => $value]))),
                '通联合法验签后的' . $label . '通知必须拒绝'
            );
        }

        $badSignature = $this->allinpayPlugin(new AllinpayUnitClient(fn (): array => [], false));
        $this->assertThrows(fn () => $badSignature->notify($this->rawFormRequest($payload)), '通联错签名通知必须拒绝');

        $service = (new ReflectionClass(PayOrderCallbackService::class))->newInstanceWithoutConstructor();
        $urlOrder = new PayOrder();
        $urlOrder->forceFill(['pay_no' => 'P-ALLINPAY-NOTIFY-B', 'pay_amount' => 100]);
        $payNoGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyPayNoMatches');
        $amountGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyAmountMatches');
        $this->assertThrows(
            fn () => $payNoGuard->invoke($service, $urlOrder, $result),
            '通联 A 单回调投递到 B 单 URL 必须失败'
        );
        $this->assertThrows(
            fn () => $amountGuard->invoke($service, $urlOrder, array_replace($result, ['paid_amount' => 101])),
            '通联回调整数分与本地支付单不一致必须失败'
        );
    }

    /**
     * 通联查单、关单、退款状态语义及原单关联。
     */
    private function testAllinpayQueryCloseRefund(): void
    {
        $queryStatuses = ['0000', '', '3050'];
        $refundStatuses = ['2000', '0000', '3000'];
        $client = new AllinpayUnitClient(static function (string $url, array $data) use (&$queryStatuses, &$refundStatuses): array {
            if (str_ends_with($url, '/tranx/query')) {
                $status = array_shift($queryStatuses) ?? '3050';
                return [
                    'reqsn' => 'P-ALLINPAY-STATE',
                    'trxid' => 'T-ALLINPAY-STATE',
                    'trxamt' => '100',
                    'trxstatus' => $status,
                    'fintime' => $status === '0000' ? '20260716123045' : '',
                ];
            }
            if (str_ends_with($url, '/tranx/close')) {
                return ['trxstatus' => '0000'];
            }
            if (str_ends_with($url, '/tranx/refund')) {
                $status = array_shift($refundStatuses) ?? '3000';
                return [
                    'reqsn' => (string) ($data['reqsn'] ?? ''),
                    'trxid' => 'T-ALLINPAY-REFUND',
                    'trxstatus' => $status,
                    'fee' => '999999',
                    'errmsg' => $status === '3000' ? '退款失败' : '',
                ];
            }
            return [];
        });
        $plugin = $this->allinpayPlugin($client);
        $order = [
            'pay_no' => 'P-ALLINPAY-STATE',
            'amount' => 100,
            'pay_amount' => 100,
            'chan_order_no' => 'P-ALLINPAY-STATE',
            'chan_trade_no' => 'T-ALLINPAY-STATE',
        ];

        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->query($order)['status'], '通联 0000 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->query($order)['status'], '通联空 trxstatus 应保持处理中');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->query($order)['status'], '通联 3050 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($order)['status'], '通联关单只有明确 0000/3050 才能成功');

        $refundOrder = $order + [
            'refund_no' => 'R-ALLINPAY-STATE',
            'refund_amount' => 50,
            'refund_reason' => '用户退款',
        ];
        $pending = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], '通联退款 2000 必须保持 pending');
        $success = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], '通联退款只有 0000 才能确认成功');
        $this->assertSame(50, (int) $success['refund_amount'], '通联退款结果必须使用本地整数分，不能误用响应 fee');
        $this->assertThrows(fn () => $plugin->refund($refundOrder), '通联退款失败状态必须抛业务异常');

        $queryCalls = array_values(array_filter($client->calls, static fn (array $call): bool => str_ends_with($call['url'], '/tranx/query')));
        $closeCalls = array_values(array_filter($client->calls, static fn (array $call): bool => str_ends_with($call['url'], '/tranx/close')));
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => str_ends_with($call['url'], '/tranx/refund')));
        $this->assertSame('T-ALLINPAY-STATE', (string) $queryCalls[0]['data']['trxid'], '通联查单应优先使用平台交易流水');
        $this->assertSame('11', (string) $queryCalls[0]['version'], '通联查单版本必须为 11');
        $this->assertSame('T-ALLINPAY-STATE', (string) $closeCalls[0]['data']['oldtrxid'], '通联关单必须关联原平台交易流水');
        $this->assertSame('12', (string) $closeCalls[0]['version'], '通联关单版本必须为 12');
        $this->assertSame('50', (string) $refundCalls[0]['data']['trxamt'], '通联退款金额必须是整数分');
        $this->assertSame('R-ALLINPAY-STATE', (string) $refundCalls[0]['data']['reqsn'], '通联退款重试必须复用退款单号');
        $this->assertSame('T-ALLINPAY-STATE', (string) $refundCalls[0]['data']['oldtrxid'], '通联退款必须关联原交易流水');
        $this->assertSame($refundCalls[0]['data'], $refundCalls[1]['data'], '通联退款重试必须保持相同幂等参数');

        $badQueryClient = new AllinpayUnitClient(fn (): array => [
            'reqsn' => 'P-ALLINPAY-STATE',
            'trxid' => 'T-ALLINPAY-STATE',
            'trxamt' => '101',
            'trxstatus' => '0000',
        ]);
        $badQuery = $this->allinpayPlugin($badQueryClient);
        $this->assertThrows(fn () => $badQuery->query($order), '通联查单金额与本地支付单不一致必须拒绝');
    }

    /**
     * AdaPay RSA-SHA1 固定向量、精确请求字节、双网关 TLS 和同步响应验签。
     */
    private function testAdapaySdkProtocolAndResponseSignature(): void
    {
        $keys = $this->adapayFixedKeyPair();
        $client = new AdapayClient([
            'app_id' => 'APP_ADAPAY_UNIT',
            'api_key' => 'adapay-unit-api-key',
            'merchant_private_key' => $keys['private_key'],
            'platform_public_key' => $keys['public_key'],
        ]);
        $vector = 'https://api.adapay.tech/v1/payments'
            . '{"order_no":"PAY_ADAPAY_VECTOR","pay_amt":"0.01","goods_title":"\\u5411\\u91cf\\u5546\\u54c1","currency":"cny"}';
        $signature = (string) $this->privateMethod(AdapayClient::class, 'signature')->invoke($client, $vector);
        $this->assertSame(
            'rNAN4WKHbQeqdXV79mU968J2Krd9fRvyixKpbYngqvmW9iOZeXfrEEb2LEVn9rjXNxcxXCeaAXiCWXaer4uDEknxu3nV+y0/YWLzpDvQzSrMQMMvnWCro2RiV+oTmtRaIx11H4bob/RATJUWnDp+JmNkcc/KqR8oSGlOFznEpFM=',
            $signature,
            'AdaPay RSA-SHA1 固定向量不匹配'
        );

        $history = [];
        $handler = new \GuzzleHttp\Handler\MockHandler([
            $this->adapayHttpResponse([
                'id' => 'PAYMENT_SDK_CREATE',
                'order_no' => 'PAY_ADAPAY_SDK',
                'app_id' => 'APP_ADAPAY_UNIT',
                'pay_channel' => 'alipay_qr',
                'pay_amt' => '0.01',
                'status' => 'pending',
            ], $keys['private_key']),
            $this->adapayHttpResponse([
                'id' => 'PAYMENT_SDK_QUERY',
                'order_no' => 'PAY_ADAPAY_SDK',
                'pay_channel' => 'alipay_qr',
                'pay_amt' => '0.01',
                'status' => 'pending',
            ], $keys['private_key']),
            $this->adapayHttpResponse([
                'status' => 'succeeded',
                'refunds' => [[
                    'payment_id' => 'PAYMENT_SDK_QUERY',
                    'refund_id' => 'REFUND_SDK_QUERY',
                    'refund_order_no' => 'REFUND_ADAPAY_SDK',
                    'trans_status' => 'P',
                    'refund_amt' => '0.01',
                ]],
            ], $keys['private_key']),
            $this->adapayHttpResponse(['status' => 'succeeded'], $keys['private_key']),
            $this->adapayHttpResponse(['status' => 'pending'], $keys['private_key'], false),
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($handler);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $httpClient = new \GuzzleHttp\Client([
            'handler' => $stack,
            'verify' => true,
            'http_errors' => false,
        ]);
        $this->setObjectProperty($client, 'httpClient', $httpClient);

        $requestPayload = [
            'order_no' => 'PAY_ADAPAY_SDK',
            'pay_amt' => '0.01',
            'goods_title' => 'AdaPay SDK 单测',
            'goods_desc' => 'AdaPay SDK 单测',
            'device_info' => ['device_ip' => '203.0.113.10'],
            'currency' => 'cny',
            'notify_url' => 'https://mpay.unit.test/api/pay/PAY_ADAPAY_SDK/callback',
            'pay_channel' => 'alipay_qr',
        ];
        $client->createPayment($requestPayload);
        $postRequest = $history[0]['request'];
        $postBody = (string) $postRequest->getBody();
        $expectedBody = json_encode(array_replace($requestPayload, [
            'app_id' => 'APP_ADAPAY_UNIT',
            'sign_type' => 'RSA2',
        ]));
        $this->assertSame($expectedBody, $postBody, 'AdaPay POST 签名 JSON 与实际发送字节必须完全相同');
        $this->assertSame('adapay-unit-api-key', $postRequest->getHeaderLine('Authorization'), 'AdaPay Authorization 请求头错误');
        $this->assertTrue($postRequest->getHeaderLine('Signature') !== '', 'AdaPay Signature 请求头不能为空');
        $this->assertSame(
            1,
            openssl_verify(
                'https://api.adapay.tech/v1/payments' . $postBody,
                base64_decode($postRequest->getHeaderLine('Signature'), true) ?: '',
                $keys['public_key'],
                OPENSSL_ALGO_SHA1
            ),
            'AdaPay POST 必须按 URL 拼接实际 JSON 字节签名'
        );

        $client->queryPayment('PAYMENT_SDK_QUERY');
        $getRequest = $history[1]['request'];
        $this->assertSame(
            'https://api.adapay.tech/v1/payments/PAYMENT_SDK_QUERY?payment_id=PAYMENT_SDK_QUERY',
            (string) $getRequest->getUri(),
            'AdaPay 查单必须同时使用路径 Payment ID 与排序后的唯一查询参数'
        );
        $this->assertSame(
            1,
            openssl_verify(
                'https://api.adapay.tech/v1/payments/PAYMENT_SDK_QUERYpayment_id=PAYMENT_SDK_QUERY',
                base64_decode($getRequest->getHeaderLine('Signature'), true) ?: '',
                $keys['public_key'],
                OPENSSL_ALGO_SHA1
            ),
            'AdaPay GET 签名原文不得擅自加入问号或改变查询编码'
        );

        $client->queryRefund('REFUND_SDK_QUERY');
        $refundQueryRequest = $history[2]['request'];
        $this->assertSame(
            'https://api.adapay.tech/v1/payments/refunds?refund_id=REFUND_SDK_QUERY',
            (string) $refundQueryRequest->getUri(),
            'AdaPay 退款查询必须只发送唯一退款对象ID'
        );
        $this->assertSame(
            1,
            openssl_verify(
                'https://api.adapay.tech/v1/payments/refundsrefund_id=REFUND_SDK_QUERY',
                base64_decode($refundQueryRequest->getHeaderLine('Signature'), true) ?: '',
                $keys['public_key'],
                OPENSSL_ALGO_SHA1
            ),
            'AdaPay 退款查询必须按官网 GET 拼接规则签名'
        );

        $client->pageRequest('qrPrePay.qrPreOrder', ['order_no' => 'PAY_ADAPAY_PAGE']);
        $this->assertTrue(
            str_starts_with((string) $history[3]['request']->getUri(), AdapayClient::PAGE_GATEWAY . '/'),
            'AdaPay 页面预下单网关必须固定使用 HTTPS'
        );
        $this->assertTrue(str_starts_with(AdapayClient::API_GATEWAY, 'https://'), 'AdaPay 交易网关必须使用 HTTPS');
        $this->assertSame(true, $httpClient->getConfig('verify'), 'AdaPay HTTP 客户端必须开启 TLS 证书校验');

        try {
            $client->queryPayment('PAYMENT_BAD_SIGNATURE');
            throw new \RuntimeException('AdaPay 同步响应错签名必须抛异常');
        } catch (AdapaySdkException $e) {
            $this->assertTrue($e->isUncertain(), 'AdaPay 无法验证同步响应时必须保持结果不确定');
        }

        $notifyData = json_encode(['id' => 'PAYMENT_NOTIFY', 'status' => 'succeeded']);
        $notifySign = $this->adapayTestSign((string) $notifyData, $keys['private_key']);
        $this->assertTrue($client->verifyNotify($notifySign, (string) $notifyData), 'AdaPay 通知 RSA-SHA1 固定密钥验签应通过');
        $this->assertFalse($client->verifyNotify(str_repeat('A', strlen($notifySign)), (string) $notifyData), 'AdaPay 错签名通知必须拒绝');
    }

    /**
     * AdaPay 五产品、环境选择、身份作用域与标准展示承接。
     */
    private function testAdapayProductsIdentityAndPresentation(): void
    {
        $client = new AdapayUnitClient(static function (string $action, array $data, int $count): array {
            if ($action !== 'create') {
                return [];
            }
            $product = (string) ($data['pay_channel'] ?? '');
            $expend = match ($product) {
                'alipay_qr', 'union_qr' => ['qrcode_url' => 'https://qrcode.adapay.unit.test/' . $product],
                'alipay_pub' => ['pay_info' => json_encode(['tradeNO' => 'ALI_TRADE_ADAPAY'])],
                'wx_pub', 'wx_lite' => ['pay_info' => json_encode([
                    'appId' => (string) ($data['expend']['wx_app_id'] ?? ''),
                    'timeStamp' => '1721111111',
                    'nonceStr' => 'adapay-nonce',
                    'package' => 'prepay_id=ADAPAY',
                    'signType' => 'RSA',
                    'paySign' => 'adapay-pay-sign',
                ])],
                default => [],
            };

            return [
                'id' => 'PAYMENT_' . strtoupper($product) . '_' . $count,
                'order_no' => (string) ($data['order_no'] ?? ''),
                'app_id' => 'APP_ADAPAY_UNIT',
                'pay_channel' => $product,
                'pay_amt' => (string) ($data['pay_amt'] ?? ''),
                'currency' => 'cny',
                'status' => 'pending',
                'out_trans_id' => 'OUT_' . strtoupper($product) . '_' . $count,
                'expend' => $expend,
            ];
        });
        $plugin = $this->adapayPlugin($client);
        $this->assertTrue($plugin instanceof PaymentIdentityRequirementInterface, 'AdaPay 身份产品必须声明统一身份需求接口');

        $schema = [];
        foreach ($plugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $products = array_map(
            static fn (array $option): string => (string) ($option['value'] ?? ''),
            (array) ($schema['enabled_products']['options'] ?? [])
        );
        $this->assertSame(
            ['alipay_pub', 'wx_pub', 'alipay_qr', 'wx_lite', 'union_qr'],
            $products,
            'AdaPay 五个产品配置必须与真实上游语义完全一致'
        );

        $alipayQr = $plugin->pay($this->adapayOrder('alipay', 'pc', [], 'PAY_ADAPAY_ALI_QR'));
        $unionQr = $plugin->pay($this->adapayOrder('bank', 'pc', [], 'PAY_ADAPAY_UNION_QR'));
        $alipayPub = $plugin->pay($this->adapayOrder('alipay', 'alipay', ['buyer_id' => '20880001'], 'PAY_ADAPAY_ALI_PUB'));
        $wxPub = $plugin->pay($this->adapayOrder('wxpay', 'wechat', ['sub_openid' => 'WX_MP_OPENID'], 'PAY_ADAPAY_WX_PUB'));
        $wxLite = $plugin->pay($this->adapayOrder('wxpay', 'wechat', [
            'is_mini' => true,
            'mini_openid' => 'WX_MINI_OPENID',
        ], 'PAY_ADAPAY_WX_LITE'));
        foreach ([$alipayQr, $unionQr, $alipayPub, $wxPub, $wxLite] as $result) {
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();
            $this->assertFalse(array_key_exists('currency', $result), 'AdaPay 标准结果不得增加 currency');
            $this->assertFalse(array_key_exists('raw_data', $result), 'AdaPay 标准结果不得增加 raw_data');
            $this->assertFalse(array_key_exists('raw', (array) ($result['presentation']['pay_params'] ?? [])), 'AdaPay 展示参数不得保存完整响应');
        }

        $this->assertSame('alipay_qr', (string) $alipayQr['pay_product'], 'PC 支付宝必须选择 alipay_qr');
        $this->assertSame('qrcode', (string) $alipayQr['presentation']['pay_page'], 'alipay_qr 必须返回二维码承接');
        $this->assertSame('union_qr', (string) $unionQr['pay_product'], '银行扫码必须选择 union_qr');
        $this->assertSame('alipay_pub', (string) $alipayPub['pay_product'], '支付宝内必须选择 alipay_pub');
        $this->assertSame('ALI_TRADE_ADAPAY', (string) $alipayPub['presentation']['pay_params']['tradeNO'], 'alipay_pub 必须严格承接 tradeNO');
        $this->assertSame('wx_pub', (string) $wxPub['pay_product'], '微信内公众号支付必须选择 wx_pub');
        $this->assertSame('jsapi', (string) $wxPub['presentation']['pay_page'], 'wx_pub 必须返回 JSAPI 承接');
        $this->assertSame('wx_lite', (string) $wxLite['pay_product'], '小程序意图必须选择 wx_lite');
        $this->assertSame('page', (string) $wxLite['presentation']['pay_page'], 'wx_lite 不得伪装成二维码支付');
        $this->assertSame('wechatMini', (string) $wxLite['presentation']['pay_params']['_page'], 'wx_lite 必须进入小程序承接页');
        $this->assertSame('ADAPAY', substr((string) $wxLite['presentation']['pay_params']['request_payment']['package'], 10), 'wx_lite 必须承接 pay_info');

        $this->assertSame('PAYMENT_ALIPAY_QR_1', (string) $alipayQr['chan_order_no'], 'AdaPay Payment id 必须映射为渠道订单号');
        $this->assertSame('OUT_ALIPAY_QR_1', (string) $alipayQr['chan_trade_no'], 'AdaPay out_trans_id 必须映射为渠道交易号');
        $this->assertSame('PAYMENT_ALIPAY_QR_1', (string) $alipayQr['channel_context']['payment_id'], 'AdaPay Payment id 必须保存到后续操作上下文');

        $this->assertSame('203.0.113.10', (string) $client->calls[0]['data']['device_info']['device_ip'], 'AdaPay 下单必须携带官网必填 device_info.device_ip');
        $this->assertSame('cny', (string) $client->calls[0]['data']['currency'], 'AdaPay 协议请求币种必须为 cny');
        $this->assertSame('20880001', (string) $client->calls[2]['data']['expend']['buyer_id'], 'alipay_pub 只能发送 buyer_id');
        $this->assertFalse(array_key_exists('open_id', $client->calls[2]['data']['expend']), 'alipay_pub 不得混入微信身份字段');
        $this->assertSame('WX_MP_OPENID', (string) $client->calls[3]['data']['expend']['open_id'], 'wx_pub 必须发送公众号 open_id');
        $this->assertSame('wx-mp-adapay', (string) $client->calls[3]['data']['expend']['wx_app_id'], 'wx_pub 必须绑定公众号 AppID');
        $this->assertSame('WX_MINI_OPENID', (string) $client->calls[4]['data']['expend']['open_id'], 'wx_lite 必须发送 mini_openid 对应的 open_id');
        $this->assertSame('wx-mini-adapay', (string) $client->calls[4]['data']['expend']['wx_app_id'], 'wx_lite 必须绑定小程序 AppID');

        $aliRequirement = $plugin->identityRequirement($this->adapayOrder('alipay', 'alipay', ['sub_openid' => 'ALI_WRONG_SCOPE']));
        $wxRequirement = $plugin->identityRequirement($this->adapayOrder('wxpay', 'wechat', ['buyer_id' => 'ALI_BUYER']));
        $miniRequirement = $plugin->identityRequirement($this->adapayOrder('wxpay', 'wechat', [
            'is_mini' => true,
            'sub_openid' => 'WX_MP_WRONG_SCOPE',
        ]));
        $this->assertSame('buyer_id', (string) ($aliRequirement['identity_field'] ?? ''), 'alipay_pub 不得把 sub_openid 当 buyer_id');
        $this->assertSame([], (array) ($aliRequirement['identity_aliases'] ?? []), 'alipay_pub 身份不得声明跨字段别名');
        $this->assertSame('openid', (string) ($wxRequirement['identity_field'] ?? ''), 'wx_pub 不得把 buyer_id 当公众号 openid');
        $this->assertSame(['sub_openid'], (array) ($wxRequirement['identity_aliases'] ?? []), 'wx_pub 只能接受同公众号作用域的 sub_openid');
        $this->assertSame('mini_openid', (string) ($miniRequirement['identity_field'] ?? ''), 'wx_lite 不得把公众号 openid 当 mini_openid');
        $this->assertSame([], (array) ($miniRequirement['identity_aliases'] ?? []), 'wx_lite 身份不得声明任何字段别名');

        $beforeStrictFailures = count($client->calls);
        $this->assertThrows(
            fn () => $plugin->pay($this->adapayOrder('alipay', 'alipay', ['sub_openid' => 'ALI_WRONG_SCOPE'], 'PAY_ADAPAY_ALI_STRICT')),
            'alipay_pub 缺少 buyer_id 时不得使用其它身份字段兜底'
        );
        $this->assertThrows(
            fn () => $plugin->pay($this->adapayOrder('wxpay', 'wechat', ['buyer_id' => 'ALI_BUYER'], 'PAY_ADAPAY_WX_STRICT')),
            'wx_pub 缺少公众号 openid 时不得使用 buyer_id 兜底'
        );
        $this->assertThrows(
            fn () => $plugin->pay($this->adapayOrder('wxpay', 'wechat', ['is_mini' => true, 'sub_openid' => 'WX_MP'], 'PAY_ADAPAY_MINI_STRICT')),
            'wx_lite 缺少 mini_openid 时不得使用公众号 openid 兜底'
        );
        $this->assertThrows(
            fn () => $plugin->pay($this->adapayOrder('alipay', 'alipay', [
                'buyer_id' => '20880001',
                'op_app_id' => '2026000000000099',
            ], 'PAY_ADAPAY_ALI_SCOPE')),
            'alipay_pub 的 buyer_id 不得跨支付宝应用作用域使用'
        );
        $this->assertSame($beforeStrictFailures, count($client->calls), '身份字段不匹配不得请求 AdaPay');

        foreach ([
            [['union_qr'], $this->adapayOrder('alipay', 'alipay', ['buyer_id' => '20880001'], 'PAY_ADAPAY_DISABLED_ALI_PUB')],
            [['alipay_qr'], $this->adapayOrder('wxpay', 'wechat', ['openid' => 'WX_MP'], 'PAY_ADAPAY_DISABLED_WX_PUB')],
            [['union_qr'], $this->adapayOrder('alipay', 'pc', [], 'PAY_ADAPAY_DISABLED_ALI_QR')],
            [['wx_pub'], $this->adapayOrder('wxpay', 'wechat', ['is_mini' => true, 'mini_openid' => 'WX_MINI'], 'PAY_ADAPAY_DISABLED_WX_LITE')],
            [['alipay_qr'], $this->adapayOrder('bank', 'pc', [], 'PAY_ADAPAY_DISABLED_UNION_QR')],
        ] as [$enabled, $order]) {
            $disabledClient = new AdapayUnitClient(fn (): array => []);
            $disabled = $this->adapayPlugin($disabledClient, ['enabled_products' => $enabled]);
            $this->assertThrows(fn () => $disabled->pay($order), 'AdaPay 未开通产品必须在请求前拒绝');
            $this->assertSame(0, count($disabledClient->calls), 'AdaPay 未开通产品不得访问上游');
        }

        $pcWxClient = new AdapayUnitClient(fn (): array => []);
        $pcWx = $this->adapayPlugin($pcWxClient, ['enabled_products' => ['wx_lite']]);
        $this->assertThrows(
            fn () => $pcWx->pay($this->adapayOrder('wxpay', 'pc', [], 'PAY_ADAPAY_WX_PC')),
            'wx_lite 名称不得被推断成 PC 微信二维码产品'
        );
        $this->assertSame(0, count($pcWxClient->calls), 'PC 微信无真实正扫产品时不得调用页面聚合码接口');

        $missingIdClient = new AdapayUnitClient(fn (string $action, array $data): array => [
            'order_no' => (string) ($data['order_no'] ?? ''),
            'app_id' => 'APP_ADAPAY_UNIT',
            'pay_channel' => 'alipay_qr',
            'pay_amt' => '1.00',
            'status' => 'pending',
            'expend' => ['qrcode_url' => 'https://qrcode.adapay.unit.test/missing-id'],
        ]);
        try {
            $this->adapayPlugin($missingIdClient)->pay($this->adapayOrder('alipay', 'pc', [], 'PAY_ADAPAY_MISSING_ID'));
            throw new \RuntimeException('AdaPay 下单缺少 Payment id 必须保持结果不确定');
        } catch (PaymentUncertainException) {
        }
        $this->assertSame(1, count($missingIdClient->calls), 'AdaPay 下单结果不确定时不得换产品重试');
    }

    /**
     * AdaPay 通知的 Event、应用、订单、金额、产品和渠道引用核对。
     */
    private function testAdapayNotifyBusinessValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'PAY_ADAPAY_NOTIFY',
            'pay_amount' => 100,
            'channel_id' => 72,
            'channel_order_no' => 'PAYMENT_ADAPAY_NOTIFY',
            'channel_trade_no' => 'OUT_ADAPAY_NOTIFY',
            'ext_json' => [
                'payment_context' => [
                    'pay_type' => 'alipay',
                    'pay_product' => 'alipay_qr',
                    'pay_action' => 'payments.create',
                    'channel_context' => [
                        'payment_id' => 'PAYMENT_ADAPAY_NOTIFY',
                        'pay_channel' => 'alipay_qr',
                    ],
                ],
            ],
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $order) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->order->pay_no ? $this->order : null;
            }
        };
        $plugin = $this->adapayPlugin(new AdapayUnitClient(fn (): array => []), [], $repository);
        $payload = [
            'id' => 'PAYMENT_ADAPAY_NOTIFY',
            'order_no' => 'PAY_ADAPAY_NOTIFY',
            'app_id' => 'APP_ADAPAY_UNIT',
            'pay_channel' => 'alipay_qr',
            'pay_amt' => '1.00',
            'currency' => 'cny',
            'status' => 'succeeded',
            'out_trans_id' => 'OUT_ADAPAY_NOTIFY',
            'party_order_id' => 'PARTY_ORDER_MUST_NOT_BE_USED',
            'end_time' => '20260717123045',
        ];
        $result = $plugin->notify($this->adapayNotifyRequest($payload));
        $repeat = $plugin->notify($this->adapayNotifyRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], 'AdaPay 明确成功通知必须归一为 success');
        $this->assertSame('PAY_ADAPAY_NOTIFY', (string) $result['pay_no'], 'AdaPay 通知必须返回精确 order_no');
        $this->assertSame(100, (int) $result['paid_amount'], 'AdaPay 通知金额必须精确转换为整数分');
        $this->assertSame('PAYMENT_ADAPAY_NOTIFY', (string) $result['chan_order_no'], 'AdaPay 通知 Payment id 必须映射为渠道订单号');
        $this->assertSame('OUT_ADAPAY_NOTIFY', (string) $result['chan_trade_no'], 'AdaPay 通知 out_trans_id 必须映射为渠道交易号');
        $this->assertSame('2026-07-17 12:30:45', (string) $result['paid_at'], 'AdaPay 通知 end_time 解析错误');
        $this->assertSame($result, $repeat, 'AdaPay 重复通知必须得到稳定归一结果并交由公共生命周期幂等处理');
        $this->assertSame('Ok', $plugin->notifySuccess(), 'AdaPay 成功 ACK 必须兼容既有 Ok 正文');
        $this->assertSame('No', $plugin->notifyFail(), 'AdaPay 失败 ACK 必须兼容既有 No 正文');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        $this->assertThrows(
            fn () => $plugin->notify($this->adapayNotifyRequest($payload, ['sign' => 'wrong-sign'])),
            'AdaPay 错签名通知必须拒绝'
        );
        foreach ([
            [array_replace($payload, ['order_no' => 'PAY_ADAPAY_OTHER']), [], '错订单'],
            [array_replace($payload, ['pay_amt' => '1.01']), [], '错金额'],
            [array_replace($payload, ['id' => 'PAYMENT_ADAPAY_OTHER']), [], '错支付对象ID'],
            [array_replace($payload, ['pay_channel' => 'union_qr']), [], '错支付产品'],
            [array_replace($payload, ['out_trans_id' => 'OUT_ADAPAY_OTHER']), [], '错渠道交易号'],
            [array_replace($payload, ['out_trans_id' => '']), [], '缺少渠道交易号'],
            [array_replace($payload, ['app_id' => 'APP_ADAPAY_OTHER']), [], '内层错应用'],
            [array_replace($payload, ['status' => 'pending']), [], '非成功状态'],
            [$payload, ['app_id' => 'APP_ADAPAY_OTHER'], '外层错应用'],
            [$payload, ['type' => 'payment.failed'], '错事件类型'],
            [$payload, ['object' => 'refund'], '错事件对象'],
        ] as [$badPayload, $event, $label]) {
            $this->assertThrows(
                fn () => $plugin->notify($this->adapayNotifyRequest($badPayload, $event)),
                'AdaPay合法验签后的' . $label . '通知必须拒绝'
            );
        }
    }

    /**
     * AdaPay 唯一 Payment ID 查单、退款终态语义与关单边界。
     */
    private function testAdapayQueryRefundAndUnsupportedClose(): void
    {
        $queryStatuses = ['succeeded', 'pending', 'future_status'];
        $refundStates = [
            ['status' => 'succeeded', 'trans_state' => 'S'],
            ['status' => 'pending', 'trans_state' => 'P'],
            ['status' => 'future_status', 'trans_state' => 'X'],
        ];
        $refundQueryStates = ['S', 'P', 'X', 'F'];
        $client = new AdapayUnitClient(static function (string $action, array $data, int $count) use (&$queryStatuses, &$refundStates, &$refundQueryStates): array {
            if ($action === 'query') {
                return [
                    'id' => (string) ($data['payment_id'] ?? ''),
                    'order_no' => 'PAY_ADAPAY_STATE',
                    'app_id' => 'APP_ADAPAY_UNIT',
                    'pay_channel' => 'alipay_qr',
                    'pay_amt' => '1.00',
                    'currency' => 'cny',
                    'status' => array_shift($queryStatuses) ?? 'future_status',
                    'out_trans_id' => 'OUT_ADAPAY_STATE',
                    'end_time' => '20260717123540',
                ];
            }
            if ($action === 'refund') {
                $state = array_shift($refundStates) ?? ['status' => 'future_status', 'trans_state' => 'X'];
                return $state + [
                    'id' => 'REFUND_ADAPAY_' . $count,
                    'payment_id' => (string) ($data['payment_id'] ?? ''),
                    'refund_order_no' => (string) ($data['refund_order_no'] ?? ''),
                    'refund_amt' => (string) ($data['refund_amt'] ?? ''),
                ];
            }
            if ($action === 'query_refund') {
                return [
                    'status' => 'succeeded',
                    'refunds' => [[
                        'payment_id' => 'PAYMENT_ADAPAY_STATE',
                        'refund_id' => (string) ($data['refund_id'] ?? ''),
                        'refund_order_no' => 'REFUND_ADAPAY_QUERY',
                        'refund_amt' => '0.50',
                        'trans_status' => array_shift($refundQueryStates) ?? 'X',
                    ]],
                ];
            }

            return [];
        });
        $plugin = $this->adapayPlugin($client);
        $this->assertTrue($plugin instanceof RefundQueryInterface, 'AdaPay 必须用主动查询闭环已受理退款');
        $order = $this->adapayStateOrder();
        foreach ([
            PaymentPluginStatusConstant::SUCCESS,
            PaymentPluginStatusConstant::PENDING,
            PaymentPluginStatusConstant::UNKNOWN,
        ] as $expected) {
            $query = $plugin->query($order);
            $this->assertSame($expected, (string) $query['status'], 'AdaPay 查单状态映射错误');
            PaymentPluginQueryResultValidator::make($query)->withScene('query_result')->validate();
        }
        $queryCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['action'] === 'query'));
        $this->assertSame('PAYMENT_ADAPAY_STATE', (string) $queryCalls[0]['data']['payment_id'], 'AdaPay 查单必须使用唯一 Payment id');
        $this->assertFalse(array_key_exists('order_no', $queryCalls[0]['data']), 'AdaPay 查单不得改用商户订单号候选');

        try {
            $plugin->close($order);
            throw new \RuntimeException('AdaPay 关单必须明确不支持');
        } catch (UnsupportedPaymentOperationException) {
        }
        $this->assertFalse($plugin instanceof RefundNotifyInterface, 'AdaPay 退款通知字段闭环不足时不得实现 RefundNotifyInterface');

        $refundOrder = $order + [
            'refund_no' => 'REFUND_ADAPAY_STATE',
            'refund_amount' => 50,
            'refund_reason' => '用户申请退款',
        ];
        $success = $plugin->refund($refundOrder);
        $pending = $plugin->refund($refundOrder);
        $unknown = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], 'AdaPay 只有明确 S 终态才能返回退款成功');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], 'AdaPay 退款受理必须返回 pending');
        $this->assertSame(PaymentPluginStatusConstant::UNKNOWN, (string) $unknown['status'], 'AdaPay 未识别退款状态必须返回 unknown');
        foreach ([$success, $pending, $unknown] as $refund) {
            PaymentPluginRefundResultValidator::make($refund)->withScene('refund_result')->validate();
            $this->assertSame(50, (int) $refund['refund_amount'], 'AdaPay 退款结果必须保持本地整数分');
            $this->assertTrue((string) $refund['chan_refund_no'] !== '', 'AdaPay 退款结果必须使用真实退款对象ID');
        }
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['action'] === 'refund'));
        $this->assertSame('PAYMENT_ADAPAY_STATE', (string) $refundCalls[0]['data']['payment_id'], 'AdaPay 退款必须使用原 Payment id');
        $this->assertSame('REFUND_ADAPAY_STATE', (string) $refundCalls[0]['data']['refund_order_no'], 'AdaPay 退款必须发送稳定退款请求号');
        $this->assertSame('0.50', (string) $refundCalls[0]['data']['refund_amt'], 'AdaPay 退款金额必须使用两位元字符串');

        $refundQueryOrder = $order + [
            'refund_no' => 'REFUND_ADAPAY_QUERY',
            'refund_amount' => 50,
            'chan_refund_no' => 'REFUND_ADAPAY_QUERY_ID',
        ];
        foreach ([
            PaymentPluginStatusConstant::SUCCESS,
            PaymentPluginStatusConstant::PENDING,
            PaymentPluginStatusConstant::UNKNOWN,
            PaymentPluginStatusConstant::FAILED,
        ] as $expected) {
            $refundQuery = $plugin->queryRefund($refundQueryOrder);
            $this->assertSame($expected, (string) $refundQuery['status'], 'AdaPay 退款查询 trans_status 映射错误');
            PaymentPluginRefundResultValidator::make($refundQuery)->withScene('refund_status_result')->validate();
        }
        $refundQueryCalls = array_values(array_filter(
            $client->calls,
            static fn (array $call): bool => $call['action'] === 'query_refund'
        ));
        $this->assertSame(
            ['refund_id' => 'REFUND_ADAPAY_QUERY_ID'],
            $refundQueryCalls[0]['data'],
            'AdaPay 退款查询只能发送唯一 refund_id，不能混入其它候选标识'
        );

        foreach ([
            ['payment_id' => 'PAYMENT_ADAPAY_OTHER'],
            ['refund_order_no' => 'REFUND_ADAPAY_OTHER'],
            ['refund_amt' => '0.51'],
            ['refund_id' => 'REFUND_ADAPAY_OTHER_ID'],
        ] as $replacement) {
            $badRefundQueryClient = new AdapayUnitClient(static fn (string $action, array $data): array => [
                'status' => 'succeeded',
                'refunds' => [[
                    'payment_id' => 'PAYMENT_ADAPAY_STATE',
                    'refund_id' => (string) ($data['refund_id'] ?? ''),
                    'refund_order_no' => 'REFUND_ADAPAY_QUERY',
                    'refund_amt' => '0.50',
                    'trans_status' => 'S',
                    ...$replacement,
                ]],
            ]);
            $this->assertThrows(
                fn () => $this->adapayPlugin($badRefundQueryClient)->queryRefund($refundQueryOrder),
                'AdaPay 退款查询业务字段不一致必须拒绝'
            );
        }

        $missingRefundIdClient = new AdapayUnitClient(fn (): array => []);
        $this->assertThrows(
            fn () => $this->adapayPlugin($missingRefundIdClient)->queryRefund(array_replace(
                $refundQueryOrder,
                ['chan_refund_no' => '']
            )),
            'AdaPay 退款查询缺少 refund_id 时不得猜测其它标识'
        );
        $this->assertSame(0, count($missingRefundIdClient->calls), '缺少 refund_id 的退款查询不得请求 AdaPay');

        $badOrderClient = new AdapayUnitClient(fn (string $action, array $data): array => [
            'id' => (string) ($data['payment_id'] ?? ''),
            'order_no' => 'PAY_ADAPAY_OTHER',
            'pay_channel' => 'alipay_qr',
            'pay_amt' => '1.00',
            'status' => 'succeeded',
        ]);
        $this->assertThrows(
            fn () => $this->adapayPlugin($badOrderClient)->query($order),
            'AdaPay 查单错订单必须拒绝'
        );
        $badAmountClient = new AdapayUnitClient(fn (string $action, array $data): array => [
            'id' => (string) ($data['payment_id'] ?? ''),
            'order_no' => 'PAY_ADAPAY_STATE',
            'pay_channel' => 'alipay_qr',
            'pay_amt' => '1.01',
            'status' => 'succeeded',
        ]);
        $this->assertThrows(
            fn () => $this->adapayPlugin($badAmountClient)->query($order),
            'AdaPay 查单错金额必须拒绝'
        );

        $failedRefund = $this->adapayPlugin(new AdapayUnitClient(fn (string $action, array $data): array => [
            'id' => 'REFUND_ADAPAY_FAILED',
            'payment_id' => (string) ($data['payment_id'] ?? ''),
            'refund_order_no' => (string) ($data['refund_order_no'] ?? ''),
            'refund_amt' => (string) ($data['refund_amt'] ?? ''),
            'status' => 'failed',
            'trans_state' => 'F',
            'error_code' => 'refund_rejected',
            'error_msg' => '退款被拒绝',
        ]));
        try {
            $failedRefund->refund($refundOrder);
            throw new \RuntimeException('AdaPay 明确退款失败必须抛确定性异常');
        } catch (PaymentDefinitiveException) {
        }

        $uncertainRefund = $this->adapayPlugin(new AdapayUnitClient(
            fn (): AdapaySdkException => new AdapaySdkException('unit timeout', true)
        ));
        try {
            $uncertainRefund->refund($refundOrder);
            throw new \RuntimeException('AdaPay 退款通信异常必须保持结果不确定');
        } catch (PaymentUncertainException) {
        }
        $definitiveRefund = $this->adapayPlugin(new AdapayUnitClient(
            fn (): AdapaySdkException => new AdapaySdkException('refund rejected', false, 'refund_rejected')
        ));
        try {
            $definitiveRefund->refund($refundOrder);
            throw new \RuntimeException('AdaPay 已验签业务拒绝必须抛确定性异常');
        } catch (PaymentDefinitiveException) {
        }

        $missingReferenceClient = new AdapayUnitClient(fn (): array => []);
        $missingReference = $this->adapayPlugin($missingReferenceClient);
        $this->assertThrows(
            fn () => $missingReference->query(array_replace($order, [
                'chan_order_no' => '',
                'channel_context' => [],
            ])),
            'AdaPay 查单缺少 Payment id 时不得猜测其它字段'
        );
        $this->assertSame(0, count($missingReferenceClient->calls), '缺少 Payment id 的查单不得请求 AdaPay');
    }

    /**
     * 易宝 yop-auth-v3 请求签名与加密通知固定向量。
     */
    private function testYeepayYopAuthV3AndNotifyVectors(): void
    {
        $keys = $this->yeepayFixedKeyPair();
        $client = new YeepayYopClient([
            'app_key' => 'YOP-UNIT-APP',
            'merchant_private_key' => $keys['private_key'],
            'platform_public_key' => $keys['public_key'],
        ]);
        $sign = $this->privateMethod(YeepayYopClient::class, 'signedRequest');
        $snapshot = $sign->invoke(
            $client,
            'POST',
            '/rest/v1.0/aggpay/pre-pay',
            [
                'orderId' => 'PAY-UNIT-001',
                'orderAmount' => '1.23',
                'goodsName' => '易宝 固定向量+&',
            ],
            '2026-07-16T08:09:10Z',
            '00112233445566778899aabbccddeeff'
        );
        $canonicalParameters = 'goodsName=%E6%98%93%E5%AE%9D%20%E5%9B%BA%E5%AE%9A%E5%90%91%E9%87%8F%2B%26'
            . '&orderAmount=1.23&orderId=PAY-UNIT-001';
        $contentHash = '6357a14f9eebea192262f0964299c0559f0e8ef9eaf106236ec517f9aba41fe4';
        $canonicalRequest = "yop-auth-v3/YOP-UNIT-APP/2026-07-16T08:09:10Z/1800\n"
            . "POST\n/rest/v1.0/aggpay/pre-pay\n\n"
            . "x-yop-appkey:YOP-UNIT-APP\n"
            . 'x-yop-content-sha256:' . $contentHash . "\n"
            . 'x-yop-request-id:00112233445566778899aabbccddeeff';
        $authorization = 'YOP-RSA2048-SHA256 yop-auth-v3/YOP-UNIT-APP/2026-07-16T08:09:10Z/1800/'
            . 'x-yop-appkey;x-yop-content-sha256;x-yop-request-id/'
            . 'VBpDGft2MxstniGBWh4V3-OzNV6dshOThFL_pMJeBt3g7eXm8iLRDSU-TQS6J7E2MMsDPz6jzcIFzMFRU4zrfcOqAz_aSa4j4dfuHNbJ_3qfAQJKnCy05sSKgrLSdbtTA3NVNRFPCh8lZEz81B034wC2wASRrcctlRdSAVGv-fCQmngMxtAFDYMVcGt6nBtlze7yZMHsqF4OhJic1HCLrOPYvq7UrMZ418n3Qkjn6p0D2shDpNzXv-zA51JNfxQiNOzjUz-mFbsn2VaqDCuc2bj46b819eLR1eaiAbHhDjWytd2YZJG-SyaFa1Nnxt_AUTQWKhi3uGVCIHo6OFmQRw$SHA256';

        $this->assertSame($canonicalParameters, (string) $snapshot['canonical_parameters'], '易宝 canonical 参数固定向量不匹配');
        $this->assertSame($contentHash, (string) $snapshot['headers']['x-yop-content-sha256'], '易宝内容摘要固定向量不匹配');
        $this->assertSame($canonicalRequest, (string) $snapshot['canonical_request'], '易宝 yop-auth-v3 canonical 原文不匹配');
        $this->assertSame($authorization, (string) $snapshot['headers']['Authorization'], '易宝 RSA2048-SHA256 Authorization 固定向量不匹配');
        $this->assertSame('YOP-UNIT-APP', (string) $snapshot['headers']['x-yop-appkey'], '易宝请求头缺少签名 appKey');
        $this->assertSame('00112233445566778899aabbccddeeff', (string) $snapshot['headers']['x-yop-request-id'], '易宝请求 ID 未进入签名头');
        $this->assertSame(
            'goodsName=%25E6%2598%2593%25E5%25AE%259D%2520%25E5%259B%25BA%25E5%25AE%259A%25E5%2590%2591%25E9%2587%258F%252B%2526&orderAmount=1.23&orderId=PAY-UNIT-001',
            (string) $snapshot['wire_parameters'],
            '易宝 POST 表单必须在 canonical 编码后再做一次线缆编码'
        );

        $vectors = $this->yeepayNotifyVectors();
        $notify = $client->notifyDecrypt($vectors['valid']);
        $this->assertSame('PAY-YEEPAY-001', (string) $notify['orderId'], '易宝固定通知向量解密结果错误');
        $this->assertSame('1.23', (string) $notify['orderAmount'], '易宝固定通知向量金额错误');
        $this->assertThrows(
            fn () => $client->notifyDecrypt($vectors['bad_sign']),
            '易宝通知 AES 明文签名错误必须拒绝'
        );
        $parts = explode('$', $vectors['valid']);
        $parts[1][0] = $parts[1][0] === 'A' ? 'B' : 'A';
        $this->assertThrows(
            fn () => $client->notifyDecrypt(implode('$', $parts)),
            '易宝通知密文被篡改必须拒绝'
        );
        $this->assertThrows(
            fn () => $client->notifyDecrypt(str_replace('$AES$SHA256', '$DES$SHA256', $vectors['valid'])),
            '易宝通知不受支持的对称算法必须拒绝'
        );
    }

    private function testYeepayProductIdentityAndPresentation(): void
    {
        $client = new YeepayUnitClient(function (string $method, string $path, array $params): array {
            if ($method !== 'POST') {
                return [];
            }
            $base = [
                'code' => '00000',
                'orderId' => (string) ($params['orderId'] ?? ''),
                'orderAmount' => (string) ($params['orderAmount'] ?? ''),
                'parentMerchantNo' => 'PARENT-UNIT',
                'merchantNo' => 'MCH-UNIT',
                'uniqueOrderNo' => 'YOP-' . (string) ($params['orderId'] ?? ''),
            ];
            if ($path === '/rest/v1.0/aggpay/pre-pay') {
                $base['prePayTn'] = match ((string) ($params['payWay'] ?? '')) {
                    'USER_SCAN' => 'https://pay.unit.test/qrcode/' . rawurlencode((string) ($params['orderId'] ?? '')),
                    'ALIPAY_LIFE' => 'ALI-TRADE-UNIT',
                    'WECHAT_OFFIACCOUNT' => json_encode([
                        'appId' => 'wx-mp-unit',
                        'timeStamp' => '1721111111',
                        'nonceStr' => 'nonce-mp',
                        'package' => 'prepay_id=mp-unit',
                        'signType' => 'RSA',
                        'paySign' => 'mp-sign',
                    ], JSON_UNESCAPED_SLASHES),
                    'MINI_PROGRAM' => json_encode([
                        'timeStamp' => '1721222222',
                        'nonceStr' => 'nonce-mini',
                        'package' => 'prepay_id=mini-unit',
                        'signType' => 'RSA',
                        'paySign' => 'mini-sign',
                    ], JSON_UNESCAPED_SLASHES),
                    default => '',
                };
                return $base;
            }
            if ($path === '/rest/v1.0/aggpay/tutelage/pre-pay') {
                if (($params['payWay'] ?? '') === 'H5_PAY') {
                    $base['prePayTn'] = 'https://cashier.unit.test/yeepay-h5';
                } elseif (($params['channel'] ?? '') === 'ALIPAY') {
                    $base['prePayTn'] = 'alipays://platformapi/startapp?appId=20000067';
                } else {
                    $base += [
                        'appId' => 'wx-open-app-unit',
                        'miniProgramOrgId' => 'gh_yeepay_unit',
                        'miniProgramPath' => 'pages/pay/index?token=unit',
                    ];
                }
                return $base;
            }

            return [];
        });
        $plugin = $this->yeepayPlugin($client);

        $alipayIdentity = $plugin->identityRequirement($this->yeepayOrder('alipay', 'alipay'));
        $this->assertSame('buyer_id', (string) ($alipayIdentity['identity_field'] ?? ''), '易宝支付宝 JSAPI 必须声明 buyer_id');
        $this->assertSame([], (array) ($alipayIdentity['identity_aliases'] ?? []), '易宝支付宝 buyer_id 不得声明跨字段别名');
        $wxIdentity = $plugin->identityRequirement($this->yeepayOrder('wxpay', 'wechat'));
        $this->assertSame('openid', (string) ($wxIdentity['identity_field'] ?? ''), '易宝公众号必须声明 openid');
        $miniIdentity = $plugin->identityRequirement($this->yeepayOrder('wxpay', 'wechat', ['method' => 'mini']));
        $this->assertSame('mini_openid', (string) ($miniIdentity['identity_field'] ?? ''), '易宝小程序必须声明 mini_openid');
        $this->assertSame('wx-mini-unit', (string) ($miniIdentity['app_id'] ?? ''), '易宝小程序身份 AppID 作用域错误');
        $this->assertSame('pages/payment/index', (string) ($miniIdentity['mini_path'] ?? ''), '易宝小程序身份续跑路径错误');
        $this->assertThrows(
            fn () => $this->yeepayPlugin($client, ['bindwxa' => false]),
            '易宝启用小程序产品时 bindwxa=false 必须拒绝配置'
        );
        $keys = $this->yeepayFixedKeyPair();
        $rawPrivateKey = preg_replace('/-----[^-]+-----|\s+/', '', $keys['private_key']) ?? '';
        $rawPublicKey = preg_replace('/-----[^-]+-----|\s+/', '', $keys['public_key']) ?? '';
        $this->yeepayPlugin($client, [
            'merchant_private_key' => $rawPrivateKey,
            'platform_public_key' => $rawPublicKey,
        ]);

        $scan = $plugin->pay($this->yeepayOrder('alipay', 'pc', ['method' => 'qrcode'], 123));
        $this->assertSame('alipay_scan', (string) $scan['pay_product'], '易宝支付宝扫码产品记录错误');
        $this->assertSame('qrcode', (string) $scan['presentation']['pay_page'], '易宝扫码必须使用标准 qrcode 承接');
        $this->assertFalse(array_key_exists('raw', (array) $scan['presentation']['pay_params']), '易宝下单不得保存完整响应 raw');
        $scanCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('1.23', (string) $scanCall['data']['orderAmount'], '易宝下单必须把整数分精确转为两位元金额');
        $this->assertSame('OFFLINE', (string) $scanCall['data']['scene'], '易宝支付宝场景必须使用当前官方允许值');

        $mp = $plugin->pay($this->yeepayOrder('wxpay', 'wechat', [
            'method' => 'jsapi',
            'openid' => 'openid-mp-unit',
            'sub_appid' => 'wx-mp-unit',
        ]));
        $this->assertSame('wxpay_mp', (string) $mp['pay_product'], '易宝公众号产品记录错误');
        $mpCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('WECHAT_OFFIACCOUNT', (string) $mpCall['data']['payWay'], '易宝公众号 payWay 错误');
        $this->assertSame('openid-mp-unit', (string) $mpCall['data']['userId'], '易宝公众号用户标识错误');

        $mini = $plugin->pay($this->yeepayOrder('wxpay', 'wechat', [
            'method' => 'mini',
            'mini_openid' => 'openid-mini-unit',
            'sub_appid' => 'wx-mini-unit',
        ]));
        $this->assertSame('wxpay_mini', (string) $mini['pay_product'], '易宝小程序产品记录错误');
        $this->assertSame('wechatMini', (string) $mini['presentation']['pay_params']['_page'], '易宝小程序必须使用标准小程序承接组件');
        $this->assertSame('mini_program_request_payment', (string) $mini['presentation']['pay_params']['launch_type'], '易宝小程序承接类型错误');
        $this->assertSame('mini-sign', (string) $mini['presentation']['pay_params']['request_payment']['paySign'], '易宝小程序真实 requestPayment 参数丢失');
        $miniCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('MINI_PROGRAM', (string) $miniCall['data']['payWay'], '易宝小程序 payWay 错误');
        $this->assertFalse(array_key_exists('openid', $miniCall['data']), '易宝小程序不得把公众号 openid 写入请求');

        $h5 = $plugin->pay($this->yeepayOrder('wxpay', 'mobile', ['method' => 'h5']));
        $this->assertSame('wxpay_h5', (string) $h5['pay_product'], '易宝托管 H5 产品记录错误');
        $this->assertSame('jump', (string) $h5['presentation']['pay_page'], '易宝托管 H5 必须使用标准 jump 承接');
        $this->assertSame('https://cashier.unit.test/yeepay-h5', (string) $h5['presentation']['pay_params']['url'], '易宝托管 H5 地址错误');

        $alipayApp = $plugin->pay($this->yeepayOrder('alipay', 'mobile', ['method' => 'app']));
        $this->assertSame('alipay_app', (string) $alipayApp['pay_product'], '易宝支付宝 APP 产品记录错误');
        $this->assertSame('urlscheme', (string) $alipayApp['presentation']['pay_page'], '易宝支付宝 APP 必须使用标准 URL Scheme 承接');
        $alipayAppCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('ALIPAYS', (string) $alipayAppCall['data']['returnSchema'], '易宝支付宝 SDK 支付必须声明 returnSchema');

        $wechatApp = $plugin->pay($this->yeepayOrder('wxpay', 'mobile', ['method' => 'app']));
        $this->assertSame('wxpay_app', (string) $wechatApp['pay_product'], '易宝微信托管 APP 产品记录错误');
        $this->assertSame('page', (string) $wechatApp['presentation']['pay_params']['_page'], '易宝微信 APP 必须使用标准 page 承接');
        $this->assertSame('wechat_open_sdk_mini_program', (string) $wechatApp['presentation']['pay_params']['launch_type'], '易宝微信 APP 必须明确原生 OpenSDK 承接');
        $this->assertSame('gh_yeepay_unit', (string) $wechatApp['presentation']['pay_params']['mini_program_id'], '易宝微信托管小程序原始 ID 丢失');

        $bank = $plugin->pay($this->yeepayOrder('bank', 'pc', ['method' => 'qrcode']));
        $this->assertSame('bank_scan', (string) $bank['pay_product'], '易宝云闪付扫码产品记录错误');
        $bankCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('UNIONPAY', (string) $bankCall['data']['channel'], '易宝云闪付 channel 错误');
        $this->assertFalse(array_key_exists('scene', $bankCall['data']), '易宝云闪付不应发送无官方要求的 scene');

        $scanOnlyClient = new YeepayUnitClient(fn (): array => ['code' => '00000']);
        $scanOnly = $this->yeepayPlugin($scanOnlyClient, [
            'enabled_products' => ['wxpay_scan'],
            'bindwxa' => false,
        ]);
        $this->assertThrows(
            fn () => $scanOnly->pay($this->yeepayOrder('wxpay', 'mobile', ['method' => 'h5'])),
            '易宝显式托管 H5 未开通时必须拒绝且不能降级请求扫码'
        );
        $this->assertSame(0, count($scanOnlyClient->calls), '易宝未开通产品不得发起上游请求');
    }

    private function testYeepayNotifyBusinessValidation(): void
    {
        $payload = [
            'parentMerchantNo' => 'PARENT-UNIT',
            'merchantNo' => 'MCH-UNIT',
            'orderId' => 'PAY-YEEPAY-NOTIFY',
            'uniqueOrderNo' => 'YOP-UNIQUE-NOTIFY',
            'orderAmount' => '1.23',
            'currency' => 'CNY',
            'status' => 'SUCCESS',
            'paySuccessDate' => '2026-07-16 16:20:30',
            'payerInfo' => '{"userID":"sensitive-buyer"}',
        ];
        $client = new YeepayUnitClient(function (string $method, string $path) use (&$payload): array|Throwable {
            if ($method !== 'NOTIFY') {
                return [];
            }
            if ($path === 'bad-cipher') {
                return new YeepaySdkException('invalid response');
            }
            return $payload;
        });
        $plugin = $this->yeepayPlugin($client);
        $request = $this->rawFormRequest([
            'response' => 'valid-cipher',
            'customerIdentification' => 'YOP-UNIT-APP',
        ]);
        $result = $plugin->notify($request);
        $this->assertSame('success', (string) $result['status'], '易宝明确 SUCCESS 通知应归一为 success');
        $this->assertSame('PAY-YEEPAY-NOTIFY', (string) $result['pay_no'], '易宝回调 pay_no 关联错误');
        $this->assertSame(123, (int) $result['paid_amount'], '易宝回调元金额必须精确转换为整数分');
        $this->assertSame('YOP-UNIQUE-NOTIFY', (string) $result['chan_trade_no'], '易宝回调渠道交易号错误');
        $this->assertFalse(array_key_exists('raw', $result), '易宝回调不得返回解密明文 raw');
        $this->assertFalse(str_contains(json_encode($result), 'sensitive-buyer'), '易宝回调不得返回付款身份');
        $repeat = $plugin->notify($request);
        $this->assertSame($result, $repeat, '易宝重复通知应产生稳定结果并交由公共生命周期幂等处理');

        foreach ([
            ['parentMerchantNo', 'OTHER-PARENT', '错发起方商户号'],
            ['merchantNo', 'OTHER-MCH', '错收款商户号'],
            ['orderId', '', '空订单号'],
            ['orderAmount', '1.24', '错金额载荷'],
            ['currency', 'USD', '错币种'],
            ['status', 'UNKNOWN_STATUS', '未知状态'],
            ['uniqueOrderNo', '', '空渠道交易号'],
        ] as [$field, $value, $scene]) {
            $original = $payload[$field];
            $payload[$field] = $value;
            if ($field === 'orderAmount') {
                $changed = $plugin->notify($request);
                $this->assertSame(124, (int) $changed['paid_amount'], '易宝错金额应由插件保留为整数分供 URL 订单二次校验');
            } else {
                $this->assertThrows(fn () => $plugin->notify($request), '易宝回调必须拒绝' . $scene);
            }
            $payload[$field] = $original;
        }
        $this->assertThrows(
            fn () => $plugin->notify($this->rawFormRequest([
                'response' => 'valid-cipher',
                'customerIdentification' => 'OTHER-APP',
            ])),
            '易宝回调 customerIdentification 错误必须拒绝'
        );
        $this->assertThrows(
            fn () => $plugin->notify($this->rawFormRequest([
                'response' => 'bad-cipher',
                'customerIdentification' => 'YOP-UNIT-APP',
            ])),
            '易宝错密文不得返回可信通知结果'
        );

        $service = (new ReflectionClass(PayOrderCallbackService::class))->newInstanceWithoutConstructor();
        $payOrder = new PayOrder();
        $payOrder->pay_no = 'PAY-YEEPAY-NOTIFY';
        $payOrder->pay_amount = 123;
        $payOrder->channel_order_no = 'PAY-YEEPAY-NOTIFY';
        $payOrder->channel_trade_no = 'YOP-UNIQUE-NOTIFY';
        $amountGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyAmountMatches');
        $referenceGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyChannelReferencesMatch');
        $this->assertThrows(
            fn () => $amountGuard->invoke($service, $payOrder, array_replace($result, ['paid_amount' => 124])),
            '易宝合法签名但金额不属于 URL 支付单时必须拒绝'
        );
        $this->assertThrows(
            fn () => $referenceGuard->invoke($service, $payOrder, array_replace($result, ['chan_trade_no' => 'YOP-OTHER-TRADE'])),
            '易宝重放通知更换渠道交易号时必须拒绝'
        );
    }

    private function testYeepayQueryCloseRefund(): void
    {
        $queryStatus = 'SUCCESS';
        $refundStatus = 'PROCESSING';
        $businessFailure = false;
        $client = new YeepayUnitClient(function (string $method, string $path, array $params) use (&$queryStatus, &$refundStatus, &$businessFailure): array {
            if ($businessFailure) {
                return ['code' => 'YOP_FAIL', 'message' => 'unit failure'];
            }
            if ($method === 'GET' && $path === '/rest/v1.0/trade/order/query') {
                return [
                    'code' => 'OPR00000',
                    'parentMerchantNo' => 'PARENT-UNIT',
                    'merchantNo' => 'MCH-UNIT',
                    'orderId' => (string) $params['orderId'],
                    'orderAmount' => '1.23',
                    'currency' => 'CNY',
                    'status' => $queryStatus,
                    'uniqueOrderNo' => 'YOP-QUERY-UNIT',
                    'paySuccessDate' => '2026-07-16 18:00:00',
                ];
            }
            if ($method === 'POST' && $path === '/rest/v1.0/aggpay/close-order') {
                return [
                    'code' => '00000',
                    'orderId' => (string) $params['orderId'],
                    'parentMerchantNo' => 'PARENT-UNIT',
                    'merchantNo' => 'MCH-UNIT',
                    'status' => 'CLOSE',
                ];
            }
            if ($method === 'POST' && $path === '/rest/v1.0/trade/refund') {
                return [
                    'code' => 'OPR00000',
                    'orderId' => (string) $params['orderId'],
                    'parentMerchantNo' => 'PARENT-UNIT',
                    'merchantNo' => 'MCH-UNIT',
                    'uniqueRefundNo' => 'YOP-REFUND-UNIT',
                    'refundRequestId' => (string) $params['refundRequestId'],
                    'refundAmount' => (string) $params['refundAmount'],
                    'status' => $refundStatus,
                ];
            }

            return [];
        });
        $plugin = $this->yeepayPlugin($client);
        $order = $this->yeepayOrder('wxpay', 'pc', [], 123) + [
            'pay_amount' => 123,
            'chan_trade_no' => 'YOP-QUERY-UNIT',
        ];
        $query = $plugin->query($order);
        $this->assertSame('success', (string) $query['status'], '易宝查单 SUCCESS 状态映射错误');
        $this->assertSame('YOP-QUERY-UNIT', (string) $query['chan_trade_no'], '易宝查单渠道交易号错误');
        $queryStatus = 'PROCESSING';
        $this->assertSame('pending', (string) $plugin->query($order)['status'], '易宝查单 PROCESSING 状态映射错误');
        $queryStatus = 'TIME_OUT';
        $this->assertSame('failed', (string) $plugin->query($order)['status'], '易宝查单 TIME_OUT 状态映射错误');
        $queryStatus = 'CLOSE';
        $this->assertSame('closed', (string) $plugin->query($order)['status'], '易宝查单 CLOSE 状态映射错误');

        $closed = $plugin->close($order);
        $this->assertSame('closed', (string) $closed['status'], '易宝关单状态映射错误');

        $refundOrder = $order + [
            'refund_no' => 'REF-YEEPAY-UNIT',
            'refund_amount' => 23,
        ];
        $pending = $plugin->refund($refundOrder);
        $this->assertSame('pending', (string) $pending['status'], '易宝退款 PROCESSING 必须保持处理中');
        $this->assertSame(23, (int) $pending['refund_amount'], '易宝退款金额必须保持整数分');
        $refundStatus = 'SUCCESS';
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refundOrder)['status'], '易宝退款 SUCCESS 状态映射错误');
        $refundStatus = 'FAILED';
        $this->assertThrows(fn () => $plugin->refund($refundOrder), '易宝退款 FAILED 必须抛 PaymentException');

        $businessFailure = true;
        $this->assertThrows(fn () => $plugin->query($order), '易宝查单业务失败必须抛 PaymentException');
        $this->assertThrows(fn () => $plugin->close($order), '易宝关单业务失败必须抛 PaymentException');
        $this->assertThrows(fn () => $plugin->refund($refundOrder), '易宝退款业务失败必须抛 PaymentException');

        $paths = array_column($client->calls, 'path');
        $this->assertTrue(in_array('/rest/v1.0/trade/order/query', $paths, true), '易宝查单必须调用当前官方路径');
        $this->assertTrue(in_array('/rest/v1.0/aggpay/close-order', $paths, true), '易宝关单必须调用当前官方路径');
        $this->assertTrue(in_array('/rest/v1.0/trade/refund', $paths, true), '易宝退款必须调用当前官方路径');
    }

    /**
     * 拉卡拉请求、通知和响应的 SHA256withRSA 签名契约。
     */
    private function testLakalaSignatureContract(): void
    {
        $fixture = $this->lakalaCertificateFixture();
        try {
            $client = new LakalaOpenApiClient([
                'app_id' => 'LKL-APP-TEST',
                'merchant_cert_path' => $fixture['merchant_cert_path'],
                'merchant_private_key_path' => $fixture['merchant_private_key_path'],
                'platform_cert_path' => $fixture['platform_cert_path'],
            ]);
            $body = '{"ver":"1.0.0","reqData":{"amount":"100"}}';
            $authorization = (string) $this->privateMethod(LakalaOpenApiClient::class, 'authorization')->invoke($client, $body);
            preg_match_all('/([a-zA-Z0-9_]+)="([^"]*)"/', $authorization, $matches, PREG_SET_ORDER);
            $fields = [];
            foreach ($matches as $match) {
                $fields[(string) $match[1]] = (string) $match[2];
            }
            $this->assertSame(12, strlen((string) ($fields['nonce_str'] ?? '')), '拉卡拉请求随机串必须为 12 字符');
            $requestMessage = 'LKL-APP-TEST' . "\n"
                . (string) ($fields['serial_no'] ?? '') . "\n"
                . (string) ($fields['timestamp'] ?? '') . "\n"
                . (string) ($fields['nonce_str'] ?? '') . "\n"
                . $body . "\n";
            $this->assertSame(
                1,
                openssl_verify($requestMessage, base64_decode((string) ($fields['signature'] ?? ''), true) ?: '', file_get_contents($fixture['merchant_cert_path']) ?: '', OPENSSL_ALGO_SHA256),
                '拉卡拉请求五行签名串不正确'
            );

            $timestamp = (string) time();
            $nonce = 'ABCDEF123456';
            $notifySignature = $this->rsaTestSign($timestamp . "\n" . $nonce . "\n" . $body . "\n", $fixture['platform_private_key']);
            $notifyAuthorization = 'LKLAPI-SHA256withRSA timestamp="' . $timestamp . '",nonce_str="' . $nonce . '",signature="' . $notifySignature . '"';
            $this->assertTrue($client->verifyNotify($notifyAuthorization, $body), '拉卡拉三行通知验签应通过');
            $this->assertFalse($client->verifyNotify($notifyAuthorization, $body . 'x'), '拉卡拉通知正文被篡改后必须验签失败');

            $serial = 'PLATFORM-SERIAL';
            $responseMessage = 'LKL-APP-TEST' . "\n" . $serial . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
            $response = new \GuzzleHttp\Psr7\Response(200, [
                'Lklapi-Appid' => 'LKL-APP-TEST',
                'Lklapi-Serial' => $serial,
                'Lklapi-Timestamp' => $timestamp,
                'Lklapi-Nonce' => $nonce,
                'Lklapi-Signature' => $this->rsaTestSign($responseMessage, $fixture['platform_private_key']),
            ], $body);
            $this->assertTrue($client->verifyResponse($response, $body), '拉卡拉公开协议五行响应验签应通过');
            $this->assertFalse($client->verifyResponse($response, $body . 'x'), '拉卡拉响应正文被篡改后必须验签失败');
        } finally {
            $this->cleanupTestDirectory($fixture['directory']);
        }
    }

    /**
     * 拉卡拉产品选择、未开通过滤、身份需求和官方与私有协议档位路由。
     */
    private function testLakalaProductIdentityAndRouting(): void
    {
        $client = new LakalaUnitClient(function (string $kind, string $path, array $data): array {
            if ($kind === 'cashier') {
                return ['counter_url' => 'https://cashier.lakala.unit/pay', 'pay_order_no' => 'CCSS-1'];
            }
            if ($path !== LakalaOpenApiClient::LABS_PREORDER_PATH) {
                return [];
            }

            return match ([(string) ($data['payMode'] ?? ''), (string) ($data['transType'] ?? '')]) {
                ['ALIPAY', '41'] => ['codeUrl' => 'https://qr.lakala.unit/alipay', 'lklOrderId' => 'LKL-A-SCAN'],
                ['ALIPAY', '51'] => ['tradeNo' => 'ALI-PREPAY', 'lklOrderId' => 'LKL-A-JS'],
                ['WECHAT', '51'], ['WECHAT', '71'] => [
                    'appId' => (string) ($data['appId'] ?? ''),
                    'timeStamp' => '1700000000',
                    'nonceStr' => 'nonce',
                    'package' => 'prepay_id=WX-PREPAY',
                    'paySign' => 'wx-sign',
                    'signType' => 'RSA',
                    'lklOrderId' => 'LKL-WX',
                ],
                ['UQRCODEPAY', '41'] => ['codeUrl' => 'https://qr.lakala.unit/bank', 'lklOrderId' => 'LKL-B-SCAN'],
                ['UQRCODEPAY', '51'] => ['redirectUrl' => 'https://union.lakala.unit/js', 'lklOrderId' => 'LKL-B-JS'],
                default => [],
            };
        });
        $plugin = $this->lakalaPlugin($client);

        $scan = $plugin->pay($this->lakalaOrder('alipay', 'pc'));
        $this->assertSame('alipay_scan', (string) $scan['pay_product'], '支付宝 PC 应选择扫码产品');
        $this->assertSame('100', (string) $client->calls[0]['data']['amount'], '拉卡拉公开协议金额必须按整数分发送');
        $this->assertFalse(array_key_exists('raw', (array) $scan['presentation']['pay_params']), '拉卡拉展示参数不得保存完整原始响应');

        $aliRequirement = $plugin->identityRequirement($this->lakalaOrder('alipay', 'alipay'));
        $this->assertSame('buyer_id', (string) ($aliRequirement['identity_field'] ?? ''), '支付宝 JSAPI 缺身份时必须进入 buyer_id 流程');
        $wxRequirement = $plugin->identityRequirement($this->lakalaOrder('wxpay', 'wechat'));
        $this->assertSame('openid', (string) ($wxRequirement['identity_field'] ?? ''), '微信公众号缺身份时必须进入 openid 流程');
        $miniRequirement = $plugin->identityRequirement($this->lakalaOrder('wxpay', 'wechat', ['is_mini' => true]));
        $this->assertSame('mini_openid', (string) ($miniRequirement['identity_field'] ?? ''), '微信小程序缺身份时必须进入 mini_openid 流程');

        $aliJs = $plugin->pay($this->lakalaOrder('alipay', 'alipay', ['buyer_id' => '20880001']));
        $this->assertSame('alipay_jsapi', (string) $aliJs['pay_product'], '支付宝身份回填后必须续跑原 JSAPI 产品');
        $wxJs = $plugin->pay($this->lakalaOrder('wxpay', 'wechat', ['openid' => 'wx-openid']));
        $this->assertSame('wxpay_jsapi', (string) $wxJs['pay_product'], '微信公众号身份回填后产品不应变化');
        $wxMini = $plugin->pay($this->lakalaOrder('wxpay', 'wechat', ['is_mini' => true, 'mini_openid' => 'wx-mini-openid']));
        $this->assertSame('wxpay_mini', (string) $wxMini['pay_product'], '微信小程序必须使用独立产品');
        $this->assertSame('wechatMini', (string) $wxMini['presentation']['pay_params']['_page'], '微信小程序应返回标准小程序承接页');

        $bankJs = $plugin->pay($this->lakalaOrder('bank', 'pc', ['buyer_id' => 'union-user', 'method' => 'jsapi']));
        $this->assertSame('bank_jsapi', (string) $bankJs['pay_product'], '云闪付移动 JSAPI 产品未被选择');
        $this->assertSame('jump', (string) $bankJs['presentation']['pay_page'], '云闪付 JSAPI 应使用上游跳转地址承接');

        $disabledClient = new LakalaUnitClient(fn (): array => []);
        $disabled = $this->lakalaPlugin($disabledClient, ['enabled_products' => ['bank_scan']]);
        $this->assertThrows(fn () => $disabled->pay($this->lakalaOrder('alipay', 'pc')), '未开通支付宝产品必须在请求上游前失败');
        $this->assertSame(0, count($disabledClient->calls), '未开通产品不应请求拉卡拉');

        $wxOnlyClient = new LakalaUnitClient(fn (): array => []);
        $wxOnly = $this->lakalaPlugin($wxOnlyClient, ['enabled_products' => ['wxpay_jsapi']]);
        $this->assertThrows(fn () => $wxOnly->pay($this->lakalaOrder('wxpay', 'pc')), '微信不得降级成已废弃 Native 主扫');
        $this->assertSame(0, count($wxOnlyClient->calls), '微信 PC 无可用产品时不得伪造主扫请求');

        $legacy = $this->lakalaPlugin($client, [
            'payment_api_profile' => 'rainbow_v3',
            'enabled_products' => ['cashier'],
        ]);
        $cashier = $legacy->pay($this->lakalaOrder('alipay', 'mobile'));
        $this->assertSame('cashier', (string) $cashier['pay_product'], '彩虹私有 profile 应保留 CCSS 收银台');
        $this->assertFalse(array_key_exists('raw', (array) $cashier['presentation']['pay_params']), '私有收银台也不得持久化完整响应');
    }

    /**
     * 拉卡拉通知在验签后严格校验订单、整数分、币种、商户和终端。
     */
    private function testLakalaNotifyBusinessValidation(): void
    {
        $client = new LakalaUnitClient(fn (): array => []);
        $plugin = $this->lakalaPlugin($client);
        $payload = [
            'payStatus' => 'S',
            'merchantOrderNo' => 'P-LAKALA-NOTIFY',
            'payOrderNo' => 'LKL-NOTIFY',
            'amount' => '100',
            'currency' => '156',
            'merchantNo' => 'M-LAKALA',
            'termId' => 'T-LAKALA',
            'payTime' => '20260716120000',
        ];
        $request = $this->rawJsonRequestWithAuthorization($payload, 'LKLAPI-SHA256withRSA timestamp="1",nonce_str="n",signature="s"');
        $result = $plugin->notify($request);
        $replayed = $plugin->notify($request);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '拉卡拉明确成功通知应映射 success');
        $this->assertSame('P-LAKALA-NOTIFY', (string) $result['pay_no'], '拉卡拉通知必须返回商户支付单号');
        $this->assertSame(100, (int) $result['paid_amount'], '拉卡拉通知金额必须保持整数分');
        $this->assertSame($result, $replayed, '重复拉卡拉通知在插件层应得到相同归一结果');

        $pending = $plugin->notify($this->rawJsonRequestWithAuthorization(array_replace($payload, ['payStatus' => 'P']), 'signed'));
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], '拉卡拉处理中通知不得记为失败');
        $this->assertSame('', (string) $plugin->notify($this->rawJsonRequestWithAuthorization(
            array_diff_key(array_replace($payload, ['payStatus' => 'P']), ['payOrderNo' => true]),
            'signed'
        ))['chan_trade_no'], '拉卡拉处理中通知缺少渠道交易号时必须保持为空');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequestWithAuthorization(
            array_diff_key($payload, ['payOrderNo' => true]),
            'signed'
        )), '拉卡拉成功通知缺少渠道交易号必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequestWithAuthorization(array_replace($payload, ['amount' => '1.00']), 'signed')), '小数金额通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequestWithAuthorization(array_replace($payload, ['currency' => '840']), 'signed')), '非人民币通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequestWithAuthorization(array_replace($payload, ['merchantNo' => 'M-WRONG']), 'signed')), '错商户通知必须拒绝');
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequestWithAuthorization(array_replace($payload, ['termId' => 'T-WRONG']), 'signed')), '错终端通知必须拒绝');
        $badSignature = $this->lakalaPlugin(new LakalaUnitClient(fn (): array => [], false));
        $this->assertThrows(fn () => $badSignature->notify($request), '拉卡拉通知验签失败必须拒绝');

        $service = (new ReflectionClass(PayOrderCallbackService::class))->newInstanceWithoutConstructor();
        $payOrder = new PayOrder();
        $payOrder->forceFill(['pay_no' => 'P-B', 'pay_amount' => 100]);
        $payNoGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyPayNoMatches');
        $amountGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyAmountMatches');
        $this->assertThrows(
            fn () => $payNoGuard->invoke($service, $payOrder, ['pay_no' => 'P-A']),
            'A 单合法通知投递到 B 单 URL 必须失败'
        );
        $this->assertThrows(
            fn () => $amountGuard->invoke($service, $payOrder, ['paid_amount' => 101]),
            '回调整数分与支付单不一致必须失败'
        );
    }

    /**
     * 拉卡拉付款码 pending、查单、普通关单/付款码撤销和退款幂等参数。
     */
    private function testLakalaPendingQueryReverseRefund(): void
    {
        $queryStates = ['CREATE', 'SUCCESS', 'CLOSE'];
        $refundResponses = [
            ['_response_code' => 'BPS10034'],
            ['_response_code' => '000000', 'lklRefundOrderNo' => 'LKL-REFUND'],
            ['_response_code' => '000000', 'lklRefundOrderNo' => 'LKL-REFUND'],
        ];
        $client = new LakalaUnitClient(function (string $kind, string $path, array $data) use (&$queryStates, &$refundResponses): array {
            return match ($path) {
                LakalaOpenApiClient::LABS_MICROPAY_PATH => [
                    '_response_code' => 'BPS10029',
                    'tradeState' => 'DEAL',
                    'lklOrderNo' => 'LKL-MICRO',
                ],
                LakalaOpenApiClient::LABS_QUERY_PATH => [
                    'tradeState' => array_shift($queryStates) ?? 'CLOSE',
                    'orderId' => (string) ($data['ornOrderId'] ?? ''),
                    'lklOrderNo' => 'LKL-MICRO',
                    'amount' => '100',
                ],
                LakalaOpenApiClient::LABS_MICROPAY_REVERSE_PATH,
                LakalaOpenApiClient::LABS_CLOSE_PATH => ['retcode' => 'BBS00000'],
                LakalaOpenApiClient::LABS_REFUND_PATH => array_shift($refundResponses) ?? ['_response_code' => '000000', 'lklRefundOrderNo' => 'LKL-REFUND'],
                default => [],
            };
        });
        $plugin = $this->lakalaPlugin($client);
        $micro = $plugin->pay($this->lakalaOrder('alipay', 'pc', ['auth_code' => '280000000000000000']));
        $this->assertSame('page', (string) $micro['presentation']['pay_page'], '付款码处理中不得返回成功页');
        $this->assertSame('paymentPending', (string) $micro['presentation']['pay_params']['_page'], '付款码处理中必须使用标准等待组件');
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $micro['presentation']['pay_params']['status'], '付款码 DEAL 必须保持 pending');
        $this->assertSame('micropay', (string) $micro['pay_product'], '付款码不得降级成主扫二维码');

        $queryOrder = [
            'pay_no' => 'P-LAKALA-STATE',
            'amount' => 100,
            'chan_trade_no' => 'LKL-MICRO',
            'pay_product' => 'micropay',
        ];
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $plugin->query($queryOrder)['status'], 'CREATE 查单应为 pending');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->query($queryOrder)['status'], 'SUCCESS 查单映射错误');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->query($queryOrder)['status'], 'CLOSE 查单映射错误');

        $microClose = $queryOrder + ['pay_product' => 'micropay'];
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($microClose)['status'], '付款码撤销应成功');
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $plugin->close($microClose)['status'], '重复付款码撤销应保持幂等参数');
        $reverseCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === LakalaOpenApiClient::LABS_MICROPAY_REVERSE_PATH));
        $this->assertSame($reverseCalls[0]['data'], $reverseCalls[1]['data'], '重复撤销必须复用同一 orderId/ornOrderId');

        $normalClose = array_replace($queryOrder, ['pay_product' => 'alipay_scan']);
        $plugin->close($normalClose);
        $this->assertTrue(
            count(array_filter($client->calls, static fn (array $call): bool => $call['path'] === LakalaOpenApiClient::LABS_CLOSE_PATH)) === 1,
            '普通主扫必须调用 close，不能调用付款码 reverse'
        );

        $idempotentCloseClient = new LakalaUnitClient(static function (string $kind, string $path): array|Throwable {
            return $path === LakalaOpenApiClient::LABS_CLOSE_PATH
                ? new LakalaSdkException('订单已经关闭')
                : ['tradeState' => 'CLOSE', 'orderId' => 'P-LAKALA-STATE'];
        });
        $idempotentClose = $this->lakalaPlugin($idempotentCloseClient);
        $this->assertSame(PaymentPluginStatusConstant::CLOSED, (string) $idempotentClose->close($normalClose)['status'], '重复关单查单已关闭时应按幂等成功收敛');
        $this->assertSame(
            [LakalaOpenApiClient::LABS_CLOSE_PATH, LakalaOpenApiClient::LABS_QUERY_PATH],
            array_column($idempotentCloseClient->calls, 'path'),
            '关单业务错误只能在查单明确关闭后收敛成功'
        );

        $legacyCloseClient = new LakalaUnitClient(static fn (): array => []);
        $legacyClose = $this->lakalaPlugin($legacyCloseClient, [
            'payment_api_profile' => 'rainbow_v3',
            'enabled_products' => ['alipay_scan', 'micropay'],
        ]);
        $this->assertThrows(fn () => $legacyClose->close($normalClose), '彩虹私有普通主扫不得误调用付款码 revoked');
        $this->assertSame(0, count($legacyCloseClient->calls), '无私有关单证据时不得请求上游');

        $refund = ['pay_no' => 'P-LAKALA-STATE', 'chan_trade_no' => 'LKL-MICRO', 'refund_no' => 'R-LAKALA-1', 'refund_amount' => 50];
        $pendingRefund = $plugin->refund($refund);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pendingRefund['status'], '退款处理中状态映射错误');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refund)['status'], '拉卡拉明确成功退款应返回标准成功结果');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $plugin->refund($refund)['status'], '重复退款应保持同一幂等键');
        $refundCalls = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === LakalaOpenApiClient::LABS_REFUND_PATH));
        $this->assertSame($refundCalls[0]['data'], $refundCalls[1]['data'], '退款重试必须保持同一 refundOrderId 和整数分金额');
        $this->assertSame($refundCalls[1]['data'], $refundCalls[2]['data'], '退款重放不能生成新的上游退款单号');
        $this->assertSame('50', (string) $refundCalls[0]['data']['amount'], '退款金额必须按整数分发送');
    }

    /**
     * 拉卡拉进件 verify → upload → addMer、查询与通知、复议、卡 BIN 和数据最小化约束。
     */
    private function testLakalaOnboardingContract(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mpay-lakala-onboarding-' . bin2hex(random_bytes(5));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('创建拉卡拉进件测试目录失败');
        }
        $file = $directory . DIRECTORY_SEPARATOR . 'fixture.png';
        file_put_contents($file, 'unit-image');

        $uploadNo = 0;
        $client = new LakalaUnitClient(function (string $kind, string $path, array $data) use (&$uploadNo): array {
            if ($path === LakalaOpenApiClient::MMS_UPLOAD_PATH) {
                $uploadNo++;
                return ['attFileId' => 'FILE-' . $uploadNo];
            }
            if ($path === LakalaOpenApiClient::MMS_SUBMIT_PATH) {
                return [
                    'orderNo' => (string) $data['orderNo'],
                    'contractId' => 'CONTRACT-1',
                    'contractStatus' => 'COMMIT',
                    'merCupNo' => 'MER-UPSTREAM',
                    'termDatas' => [['termNo' => 'TERM-UPSTREAM']],
                ];
            }
            if ($path === LakalaOpenApiClient::MMS_QUERY_PATH) {
                return [
                    'orderNo' => (string) $data['orderNo'],
                    'contractId' => 'CONTRACT-1',
                    'contractStatus' => 'WAIT_FOR_CONTACT',
                    'merCupNo' => 'MER-UPSTREAM',
                    'termDatas' => [['termNo' => 'TERM-UPSTREAM']],
                ];
            }
            if ($path === LakalaOpenApiClient::MMS_RECONSIDER_PATH) {
                return ['orderNo' => (string) $data['orderNo'], 'contractId' => 'CONTRACT-1', 'contractStatus' => 'MANUAL_AUDIT'];
            }
            if ($path === LakalaOpenApiClient::MMS_CARD_BIN_PATH) {
                return ['bankCode' => 'BK001', 'clearingBankCode' => 'CLEAR001', 'bankName' => '测试银行'];
            }

            return [];
        });
        $plugin = $this->lakalaPlugin($client, ['org_code' => 'ORG-LAKALA', 'onboarding_verify_enabled' => true]);
        $form = [
            'pos_type' => 'GENERAL_POS',
            'mer_reg_name' => '测试商户',
            'mer_biz_name' => '测试门店',
            'mer_reg_dist_code' => '110101',
            'mer_reg_addr' => '测试地址',
            'mcc_code' => '6540',
            'mer_busi_content' => '零售',
            'lar_name' => '测试法人',
            'lar_id_type' => '01',
            'lar_idcard' => '110101199001010000',
            'lar_idcard_st_dt' => '2020-01-01',
            'lar_idcard_exp_dt' => '2040-01-01',
            'mer_contact_mobile' => '13800000000',
            'mer_contact_name' => '测试联系人',
            'openning_bank_code' => 'BK001',
            'openning_bank_name' => '测试银行',
            'clearing_bank_code' => 'CLEAR001',
            'acct_no' => '6222000000000000',
            'acct_name' => '测试法人',
            'acct_type_code' => '58',
            'settle_period' => 'T+1',
            'settlement_same_as_legal' => true,
            'fee_rate_type_code' => '01',
            'fee_rate_type_name' => '标准费率',
            'fee_rate_pct' => '0.38',
            'legal_id_front' => $file,
            'legal_id_back' => $file,
            'settlement_bank_card' => $file,
            'store_front_photo' => $file,
            'store_inside_photo' => $file,
        ];
        $payload = [
            'onboarding_no' => 'ONB20260716120000123456',
            'subject_type' => 'micro',
            'notify_url' => 'https://merchant.unit.test/lakala/onboarding',
            'form_data' => $form,
            'rate_config' => [],
        ];

        try {
            $submitted = $plugin->submitOnboarding($payload);
            $this->assertTrue((bool) $submitted['success'], '拉卡拉进件提交应返回标准成功结果');
            $this->assertTrue(preg_match('/^\d{22}$/', (string) $submitted['upstream_apply_id']) === 1, 'MMS 上游单号必须为 22 位数字');
            $paths = array_column($client->calls, 'path');
            $this->assertSame(LakalaOpenApiClient::MMS_VERIFY_PATH, (string) $paths[0], '进件必须先 verifyContractInfo');
            $this->assertSame(5, count(array_filter($paths, static fn (string $path): bool => $path === LakalaOpenApiClient::MMS_UPLOAD_PATH)), '五个必传附件必须逐一 uploadFile');
            $this->assertSame(LakalaOpenApiClient::MMS_SUBMIT_PATH, (string) end($paths), '附件上传后必须调用 addMer');

            $queried = $plugin->queryOnboarding($payload + [
                'upstream_apply_id' => (string) $submitted['upstream_apply_id'],
                'upstream_contract_id' => 'CONTRACT-1',
            ]);
            $this->assertSame('signed', (string) $queried['status'], 'WAIT_FOR_CONTACT 进件查询状态映射错误');
            $this->assertSame('TERM-UPSTREAM', (string) $queried['upstream_terminal_no'], '官方 termDatas 终端号未提取');

            $plugin->reconsiderOnboarding($payload + ['upstream_contract_id' => 'CONTRACT-1']);
            $this->assertTrue(
                count(array_filter($client->calls, static fn (array $call): bool => $call['path'] === LakalaOpenApiClient::MMS_RECONSIDER_PATH)) === 1,
                '复议必须调用 reconsiderSubmit'
            );

            $cardBin = $plugin->cardBin(['card_no' => '6222000000000000']);
            $this->assertFalse(array_key_exists('raw_data', $cardBin), '卡 BIN 结果不得携带完整原始响应');
            $cardCall = array_values(array_filter($client->calls, static fn (array $call): bool => $call['path'] === LakalaOpenApiClient::MMS_CARD_BIN_PATH))[0];
            $this->assertTrue(preg_match('/^\d{22}$/', (string) ($cardCall['data']['orderNo'] ?? '')) === 1, 'cardBin 必须携带官方要求的 orderNo');

            $notify = $plugin->notifyOnboarding($this->rawJsonRequestWithAuthorization([
                'data' => [
                    'orgCode' => 'ORG-LAKALA',
                    'orderNo' => (string) $submitted['upstream_apply_id'],
                    'contractId' => 'CONTRACT-1',
                    'contractStatus' => 'WAIT_FOR_CONTACT',
                    'merCupNo' => 'MER-UPSTREAM',
                    'termDatas' => [['termNo' => 'TERM-UPSTREAM']],
                ],
            ], 'signed'));
            $this->assertSame('TERM-UPSTREAM', (string) $notify['upstream_terminal_no'], '进件通知 termDatas 未正确解析');
            $this->assertSame((string) $submitted['upstream_apply_id'], (string) $notify['upstream_apply_id'], '进件通知上游申请单号未正确解析');
            $this->assertFalse(array_key_exists('onboarding_no', $notify), '上游申请单号不得冒充平台进件申请单号');
            $this->assertSame('{"code":"SUCCESS","message":"成功"}', $plugin->notifyOnboardingSuccess(), '进件成功应答格式不正确');
            $this->assertFalse(str_contains(json_encode($submitted) ?: '', '6222000000000000'), '进件标准结果不得泄露银行卡号');
        } finally {
            $this->cleanupTestDirectory($directory);
        }
    }

    /**
     * XorPay UTF-8 MD5 固定向量、AppId 路径和响应状态语义。
     */
    private function testXorpayMd5PathsAndResponseSemantics(): void
    {
        $history = [];
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'status' => 'ok',
                'info' => [
                    'qr' => 'weixin://wxpay/bizpayurl?pr=xorpay-unit',
                    'aoid' => 'XOR-AOID-001',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            new \GuzzleHttp\Psr7\Response(200, [], '{"status":"ok"}'),
            new \GuzzleHttp\Psr7\Response(200, [], '{"status":"new"}'),
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new XorpayClient([
            'app_id' => 'AID/路径',
            'app_secret' => 'xorpay-unit-secret',
        ]);
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client(['handler' => $stack]));

        $params = [
            'name' => 'XorPay协议测试',
            'pay_type' => 'native',
            'price' => '1.23',
            'order_id' => 'P-XORPAY-001',
            'notify_url' => 'https://merchant.example/api/pay/P-XORPAY-001/callback',
        ];
        $pay = $client->pay($params);
        $this->assertSame('XOR-AOID-001', (string) $pay['info']['aoid'], 'XorPay SDK 应原样返回 info.aoid');
        $this->assertSame(
            'https://xorpay.com/api/pay/' . rawurlencode('AID/路径'),
            (string) $history[0]['request']->getUri(),
            'XorPay 支付接口必须使用固定 HTTPS 地址并编码 AppId 路径段'
        );
        parse_str((string) $history[0]['request']->getBody(), $payBody);
        $this->assertSame($params['name'], (string) $payBody['name'], 'XorPay 表单 UTF-8 商品名编码后必须可逆');
        $this->assertSame(
            'e654989fbb431dbb5e45d137b8062dae',
            (string) $payBody['sign'],
            'XorPay 下单 MD5 固定向量不正确'
        );
        $this->assertSame(strtolower((string) $payBody['sign']), (string) $payBody['sign'], 'XorPay 出站 MD5 必须为小写');

        $cashier = $client->cashierPayload($params + ['return_url' => 'https://merchant.example/return']);
        $this->assertSame(
            'e654989fbb431dbb5e45d137b8062dae',
            (string) $cashier['sign'],
            'XorPay 收银台签名不得包含 return_url'
        );
        $this->assertSame(
            'https://xorpay.com/api/cashier/' . rawurlencode('AID/路径'),
            $client->cashierUrl(),
            'XorPay 收银台必须使用固定 HTTPS 地址'
        );

        $client->refund('XOR/AOID', '0.50');
        $this->assertSame(
            'https://xorpay.com/api/refund/' . rawurlencode('XOR/AOID'),
            (string) $history[1]['request']->getUri(),
            'XorPay 退款路径必须使用 aoid'
        );
        parse_str((string) $history[1]['request']->getBody(), $refundBody);
        $this->assertSame('e88f9fd7a66f98c39aae51de31098d71', (string) $refundBody['sign'], 'XorPay 退款 MD5 固定向量不正确');

        $client->query('XOR/AOID');
        $this->assertSame(
            'https://xorpay.com/api/query/' . rawurlencode('XOR/AOID'),
            (string) $history[2]['request']->getUri(),
            'XorPay 主动查单必须按 aoid 使用官方 query 路径'
        );

        $notify = [
            'aoid' => 'XOR-AOID-001',
            'order_id' => 'P-XORPAY-001',
            'pay_price' => '1.23',
            'pay_time' => '2026-07-17 12:34:56',
            'sign' => 'a8287754c443e92546615f7c7e537aef',
        ];
        $this->assertTrue($client->verify($notify), 'XorPay 回调 MD5 固定向量必须验签通过');
        $notify['sign'] = strtoupper((string) $notify['sign']);
        $this->assertFalse($client->verify($notify), 'XorPay 官方小写回调签名不应静默放宽大小写');

        $uncertainClient = new XorpayClient([
            'app_id' => 'AID-UNIT',
            'app_secret' => 'xorpay-unit-secret',
        ]);
        $this->setObjectProperty($uncertainClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, [], '{"status":"order_payed"}'),
            ]),
        ]));
        try {
            $uncertainClient->pay($params);
            throw new \RuntimeException('XorPay order_payed 必须作为结果不确定异常');
        } catch (XorpaySdkException $e) {
            $this->assertTrue($e->isUncertain(), 'XorPay 已存在/已支付订单不得按明确未受理处理');
            $this->assertSame('order_payed', $e->channelStatus(), 'XorPay SDK 应保留受控状态码');
        }

        foreach ([
            ['sign_error', false, 'pay'],
            ['future_pay_state', true, 'pay'],
            ['price_error', false, 'refund'],
            ['future_refund_state', true, 'refund'],
        ] as [$channelStatus, $expectedUncertain, $action]) {
            $statusClient = new XorpayClient([
                'app_id' => 'AID-UNIT',
                'app_secret' => 'xorpay-unit-secret',
            ]);
            $this->setObjectProperty($statusClient, 'httpClient', new \GuzzleHttp\Client([
                'handler' => new \GuzzleHttp\Handler\MockHandler([
                    new \GuzzleHttp\Psr7\Response(200, [], json_encode(['status' => $channelStatus])),
                ]),
            ]));
            try {
                $action === 'pay'
                    ? $statusClient->pay($params)
                    : $statusClient->refund('XOR-AOID-STATUS', '0.50');
                throw new \RuntimeException('XorPay 非 ok 状态必须抛出 SDK 异常');
            } catch (XorpaySdkException $e) {
                $this->assertSame($expectedUncertain, $e->isUncertain(), 'XorPay 已知错误与未来状态的确定性分类错误');
                $this->assertSame($channelStatus, $e->channelStatus(), 'XorPay 异常必须保留正文状态码');
            }
        }
    }

    /**
     * XorPay 三类产品的环境选择、产品开关和托管收银台表单安全。
     */
    private function testXorpayProductRoutingAndCashierForm(): void
    {
        $client = new XorpayUnitClient(static function (string $action, array $data, int $count): array {
            return $action === 'pay'
                ? [
                    'status' => 'ok',
                    'info' => [
                        'qr' => 'https://xorpay.unit.test/qr/' . $count,
                        'aoid' => 'XOR-AOID-' . $count,
                    ],
                ]
                : [];
        });
        $plugin = $this->xorpayPlugin($client);
        $this->assertFalse(
            $plugin instanceof PaymentIdentityRequirementInterface,
            'XorPay 托管收银台不得因 jsapi 名称声明 MPAY 身份授权'
        );

        $pcAlipay = $plugin->pay($this->xorpayOrder('alipay', 'pc'));
        $pcWechat = $plugin->pay($this->xorpayOrder('wxpay', 'pc'));
        $mobileWechat = $plugin->pay($this->xorpayOrder('wxpay', 'mobile'));
        $wechatCashier = $plugin->pay($this->xorpayOrder('wxpay', 'wechat'));
        foreach ([$pcAlipay, $pcWechat, $mobileWechat, $wechatCashier] as $result) {
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();
        }

        $this->assertSame('alipay', (string) $pcAlipay['pay_product'], 'PC 支付宝必须选择当面付扫码');
        $this->assertSame('alipay', (string) $client->calls[0]['data']['pay_type'], '支付宝上游 pay_type 必须为 alipay');
        $this->assertSame('XOR-AOID-1', (string) $pcAlipay['chan_order_no'], '扫码下单必须保存真实 info.aoid');
        $this->assertFalse(array_key_exists('raw', $pcAlipay['presentation']['pay_params']), 'XorPay 二维码结果不得持久化完整响应');
        $this->assertSame('wx_native', (string) $pcWechat['pay_product'], 'PC 微信必须选择 Native 扫码');
        $this->assertSame('native', (string) $client->calls[1]['data']['pay_type'], '微信扫码上游 pay_type 必须为 native');
        $this->assertSame('wx_native', (string) $mobileWechat['pay_product'], '普通移动浏览器无微信 H5 产品时必须保持 Native 扫码');
        $this->assertSame('wechat_cashier', (string) $wechatCashier['pay_product'], '微信内必须优先 XorPay 托管收银台');
        $this->assertSame('html', (string) $wechatCashier['presentation']['pay_page'], 'XorPay 托管收银台必须返回 HTML 承接');

        $formPlugin = new XorpayApiPayment();
        $formPlugin->init($this->xorpayConfig([
            'app_id' => 'aid"><script>alert(1)</script>',
            'enabled_products' => ['wechat_cashier'],
        ]));
        $formOrder = $this->xorpayOrder('wxpay', 'wechat');
        $formOrder['subject'] = '商品"><img src=x onerror=alert(1)>';
        $formOrder['return_url'] = 'https://merchant.unit.test/return?a="<tag>';
        $formResult = $formPlugin->pay($formOrder);
        $html = (string) $formResult['presentation']['pay_params']['html'];
        $expectedAction = 'https://xorpay.com/api/cashier/' . rawurlencode('aid"><script>alert(1)</script>');
        $this->assertTrue(str_contains($html, 'action="' . $expectedAction . '"'), 'XorPay 表单 action 必须固定为编码后的 HTTPS 上游');
        $this->assertFalse(str_contains($html, '<img'), 'XorPay 表单字段值必须 HTML 属性转义');
        $this->assertFalse(str_contains($html, '<script>alert'), 'AppId 不得逃逸固定表单 action');
        $this->assertTrue(str_contains($html, '&quot;') && str_contains($html, '&lt;'), 'XorPay 表单特殊字符必须完整转义');
        $this->assertFalse(str_contains($html, 'xorpay-unit-secret'), 'XorPay 收银台 HTML 不得包含 AppSecret');
        preg_match_all('/<input type="hidden" name="([^"]+)"/', $html, $matches);
        $this->assertSame(
            ['name', 'pay_type', 'price', 'order_id', 'notify_url', 'return_url', 'sign'],
            $matches[1] ?? [],
            'XorPay 收银台只能提交官方字段'
        );

        $disabledClient = new XorpayUnitClient(fn (): array => ['status' => 'ok']);
        $disabled = $this->xorpayPlugin($disabledClient, ['enabled_products' => ['alipay']]);
        $this->assertThrows(fn () => $disabled->pay($this->xorpayOrder('wxpay', 'pc')), '未开通微信产品必须在本地拒绝');
        $this->assertSame(0, count($disabledClient->calls), '未开通产品不得请求 XorPay');

        $cashierOnlyClient = new XorpayUnitClient(fn (): array => ['status' => 'ok']);
        $cashierOnly = $this->xorpayPlugin($cashierOnlyClient, ['enabled_products' => ['wechat_cashier']]);
        $this->assertThrows(fn () => $cashierOnly->pay($this->xorpayOrder('wxpay', 'pc')), 'PC 环境不得请求仅限微信内的收银台产品');
        $this->assertSame(0, count($cashierOnlyClient->calls), '环境不匹配的产品不得请求上游');

        $missingAoidClient = new XorpayUnitClient(fn (): array => [
            'status' => 'ok',
            'info' => ['qr' => 'https://xorpay.unit.test/qr/missing-aoid'],
        ]);
        $missingAoid = $this->xorpayPlugin($missingAoidClient, ['enabled_products' => ['alipay']]);
        try {
            $missingAoid->pay($this->xorpayOrder('alipay', 'pc'));
            throw new \RuntimeException('XorPay 缺少 aoid 必须保持结果不确定');
        } catch (PaymentUncertainException) {
        }
        $this->assertSame(1, count($missingAoidClient->calls), '下单结果不确定时不得换产品重试');
    }

    /**
     * XorPay 成功通知的签名、订单、金额、平台单号和渠道流水关联。
     */
    private function testXorpayNotifyBusinessValidation(): void
    {
        $plugin = new XorpayApiPayment();
        $plugin->init($this->xorpayConfig());
        $payload = $this->xorpaySignedNotify();
        $result = $plugin->notify($this->rawFormRequest($payload));
        $repeat = $plugin->notify($this->rawFormRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], 'XorPay 官方成功通知应归一为 success');
        $this->assertSame('P-XORPAY-NOTIFY-A', (string) $result['pay_no'], 'XorPay 回调必须返回真实 order_id');
        $this->assertSame(123, (int) $result['paid_amount'], 'XorPay 回调金额必须精确转换为整数分');
        $this->assertSame('XOR-AOID-NOTIFY-A', (string) $result['chan_order_no'], 'XorPay aoid 必须映射为渠道订单号');
        $this->assertSame('WX-TRANSACTION-NOTIFY-A', (string) $result['chan_trade_no'], 'XorPay detail.transaction_id 必须映射为渠道交易号');
        $this->assertSame('2026-07-17 12:34:56', (string) $result['paid_at'], 'XorPay 支付时间必须严格解析');
        $this->assertSame($result, $repeat, 'XorPay 相同通知重放必须得到稳定归一结果');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        $wrongStatus = $payload;
        $wrongStatus['status'] = 'pending';
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($wrongStatus)), 'XorPay 显式非成功状态不得推进成功');

        $badDetail = $payload;
        $badDetail['detail'] = '{invalid-json';
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($badDetail)), 'XorPay 回调 detail 非法时必须拒绝');
        $missingTransaction = $payload;
        $missingTransaction['detail'] = json_encode(['buyer' => 'unit-buyer']);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($missingTransaction)), 'XorPay 回调缺少精确 transaction_id 必须拒绝');

        $badSignature = $payload;
        $badSignature['sign'] = str_repeat('0', 32);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($badSignature)), 'XorPay 错签名通知必须拒绝');
        $wrongSecretPlugin = new XorpayApiPayment();
        $wrongSecretPlugin->init($this->xorpayConfig(['app_secret' => 'another-xorpay-secret']));
        $this->assertThrows(fn () => $wrongSecretPlugin->notify($this->rawFormRequest($payload)), 'XorPay 通知必须绑定当前 AppId 对应 AppSecret');

        $invalidTime = $this->xorpaySignedNotify(['pay_time' => '2026-02-30 12:34:56']);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($invalidTime)), 'XorPay 无效支付时间必须拒绝');

        $urlOrder = new PayOrder();
        $urlOrder->forceFill([
            'pay_no' => 'P-XORPAY-NOTIFY-B',
            'pay_amount' => 123,
            'channel_id' => 31,
            'channel_order_no' => 'XOR-AOID-NOTIFY-A',
            'channel_trade_no' => '',
        ]);
        $this->assertSame('fail', $this->xorpayCallbackAck($plugin, $urlOrder, $payload), 'XorPay A 单通知投 B 单 URL 必须失败');

        $wrongAmountOrder = clone $urlOrder;
        $wrongAmountOrder->pay_no = 'P-XORPAY-NOTIFY-A';
        $wrongAmountOrder->pay_amount = 124;
        $this->assertSame('fail', $this->xorpayCallbackAck($plugin, $wrongAmountOrder, $payload), 'XorPay 错金额通知必须失败');

        $wrongAoidOrder = clone $wrongAmountOrder;
        $wrongAoidOrder->pay_amount = 123;
        $wrongAoidOrder->channel_order_no = 'XOR-AOID-OTHER';
        $this->assertSame('fail', $this->xorpayCallbackAck($plugin, $wrongAoidOrder, $payload), 'XorPay aoid 与已保存平台单不一致必须失败');

        $wrongTradeOrder = clone $wrongAoidOrder;
        $wrongTradeOrder->channel_order_no = 'XOR-AOID-NOTIFY-A';
        $wrongTradeOrder->channel_trade_no = 'WX-TRANSACTION-OTHER';
        $this->assertSame('fail', $this->xorpayCallbackAck($plugin, $wrongTradeOrder, $payload), 'XorPay 渠道流水号重放不一致必须失败');
    }

    /**
     * XorPay 官方查单状态、退款确定性和不支持关单边界。
     */
    private function testXorpayQueryRefundAndUnsupportedClose(): void
    {
        $queryStatuses = ['success', 'payed', 'new', 'expire', 'not_exist', 'fee_error', 'future_status'];
        $client = new XorpayUnitClient(function (string $action) use (&$queryStatuses): array {
            if ($action === 'query') {
                return ['status' => array_shift($queryStatuses) ?? 'future_status'];
            }
            if ($action === 'refund') {
                return ['status' => 'ok', 'info' => ['refund_id' => 'UNDOCUMENTED-ID']];
            }

            return [];
        });
        $plugin = $this->xorpayPlugin($client);
        $order = [
            'pay_no' => 'P-XORPAY-STATE',
            'amount' => 100,
            'chan_order_no' => 'XOR-AOID-STATE',
            'chan_trade_no' => 'WX-TRANSACTION-STATE',
        ];
        foreach ([
            PaymentPluginStatusConstant::SUCCESS,
            PaymentPluginStatusConstant::SUCCESS,
            PaymentPluginStatusConstant::PENDING,
            PaymentPluginStatusConstant::CLOSED,
            PaymentPluginStatusConstant::UNKNOWN,
            PaymentPluginStatusConstant::UNKNOWN,
            PaymentPluginStatusConstant::UNKNOWN,
        ] as $expectedStatus) {
            $query = $plugin->query($order);
            $this->assertSame($expectedStatus, (string) $query['status'], 'XorPay 官方查单状态映射错误');
            PaymentPluginQueryResultValidator::make($query)->withScene('query_result')->validate();
        }

        $beforeMissingQuery = count($client->calls);
        $this->assertThrows(fn () => $plugin->query(array_replace($order, ['chan_order_no' => ''])), 'XorPay 无 aoid 时不得猜字段主动查单');
        $this->assertSame($beforeMissingQuery, count($client->calls), '缺少 aoid 的查单不得请求上游');

        try {
            $plugin->close($order);
            throw new \RuntimeException('XorPay 关单必须明确不支持');
        } catch (UnsupportedPaymentOperationException) {
        }

        $refundOrder = $order + [
            'refund_no' => 'R-XORPAY-STATE',
            'refund_amount' => 50,
        ];
        $refund = $plugin->refund($refundOrder);
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $refund['status'], 'XorPay 只有正文 status=ok 才能确认退款成功');
        $this->assertSame('', (string) $refund['chan_refund_no'], 'XorPay 官方未定义 refund_id，不得读取猜测字段');
        PaymentPluginRefundResultValidator::make($refund)->withScene('refund_result')->validate();
        $refundCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('XOR-AOID-STATE', (string) $refundCall['data']['xorpay_order_id'], 'XorPay 退款必须使用 aoid 而不是下游 transaction_id');
        $this->assertSame('0.50', (string) $refundCall['data']['amount'], 'XorPay 退款金额必须使用两位元字符串');

        $beforeMissingReference = count($client->calls);
        $this->assertThrows(
            fn () => $plugin->refund(array_replace($refundOrder, ['chan_trade_no' => ''])),
            'XorPay 退款缺少渠道交易号必须在请求前失败'
        );
        $this->assertThrows(
            fn () => $plugin->refund(array_replace($refundOrder, ['chan_order_no' => ''])),
            'XorPay 退款缺少 aoid 必须在请求前失败'
        );
        $this->assertSame($beforeMissingReference, count($client->calls), '缺少退款关联号不得请求 XorPay');

        $uncertain = $this->xorpayPlugin(new XorpayUnitClient(
            fn (): XorpaySdkException => new XorpaySdkException('unit timeout', true)
        ));
        try {
            $uncertain->refund($refundOrder);
            throw new \RuntimeException('XorPay 退款通信异常必须保持结果不确定');
        } catch (PaymentUncertainException) {
        }

        $definitive = $this->xorpayPlugin(new XorpayUnitClient(
            fn (): XorpaySdkException => new XorpaySdkException('order_error', false, 'order_error')
        ));
        try {
            $definitive->refund($refundOrder);
            throw new \RuntimeException('XorPay 退款明确业务拒绝必须抛确定性异常');
        } catch (PaymentDefinitiveException) {
        }
    }

    /**
     * 虎皮椒 MD5 固定向量、HTTPS 网关和三类官方请求编码。
     */
    private function testXunhupayMd5HttpsAndOfficialRequests(): void
    {
        $client = new XunhupayClient([
            'appid' => 'APP-001',
            'api_key' => 'unit-secret',
            'api_url' => 'https://api.xunhupay.com/payment/do.html',
        ]);
        $vector = [
            'zeta' => '最后',
            'appid' => 'APP-001',
            'empty' => '',
            'hash' => str_repeat('f', 32),
            'nonce_str' => 'abc123',
            'null' => null,
            'time' => 1700000000,
            'total_fee' => '1.00',
            'zero' => 0,
        ];
        $this->assertSame(
            '701c1bbe1e1b28b9e94802249a758153',
            $client->sign($vector),
            '虎皮椒 MD5 固定向量不正确'
        );
        $vector['hash'] = $client->sign($vector);
        $this->assertTrue($client->verify($vector), '虎皮椒固定向量必须验签通过');
        $vector['hash'] = strtoupper((string) $vector['hash']);
        $this->assertFalse($client->verify($vector), '虎皮椒签名必须严格使用 32 位小写 MD5');

        $reflection = new ReflectionClass($client);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        /** @var \GuzzleHttp\Client $defaultHttpClient */
        $defaultHttpClient = $httpClientProperty->getValue($client);
        $this->assertSame(true, $defaultHttpClient->getConfig('verify'), '虎皮椒 SDK 必须开启 TLS 证书校验');

        $invalidGateway = new XunhupayClient([
            'appid' => 'APP-001',
            'api_key' => 'unit-secret',
            'api_url' => 'http://api.xunhupay.com/payment/do.html',
        ]);
        $this->assertThrows(
            fn () => $invalidGateway->pay(['trade_order_id' => 'P-XH-INVALID']),
            '虎皮椒 SDK 必须在请求前拒绝 HTTP 网关'
        );

        $payResponse = [
            'errcode' => 0,
            'errmsg' => 'success!',
            'url' => 'https://api.xunhupay.com/payment/mobile.html',
            'url_qrcode' => 'https://api.xunhupay.com/qrcode.png',
            'open_order_id' => 'OPEN-XH-REQUEST',
        ];
        $payResponse['hash'] = $client->sign($payResponse);
        $refundResponse = [
            'errcode' => 0,
            'errmsg' => 'success!',
            'trade_order_id' => 'P-XH-REQUEST',
            'transaction_id' => 'WX-CANDIDATE-IGNORED',
            'out_refund_no' => 'RF-XH-REQUEST',
            'refund_fee' => '1.00',
            'refund_status' => 'CD',
        ];
        $refundResponse['hash'] = $client->sign($refundResponse);
        $history = [];
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($payResponse)),
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'errcode' => 0,
                'errmsg' => 'success!',
                'data' => [
                    'status' => 'WP',
                    'trade_order_id' => 'P-XH-REQUEST',
                    'total_fee' => '1.00',
                    'open_order_id' => 'OPEN-XH-REQUEST',
                ],
            ])),
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($refundResponse)),
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client([
            'handler' => $stack,
            'verify' => true,
        ]));

        $client->pay([
            'version' => '1.1',
            'trade_order_id' => 'P-XH-REQUEST',
            'payment' => 'alipay',
            'total_fee' => '1.00',
            'title' => '虎皮椒请求测试',
            'notify_url' => 'https://mpay.unit.test/api/pay/P-XH-REQUEST/callback',
            'return_url' => 'https://merchant.unit.test/return',
        ]);
        $client->query(['out_trade_order' => 'P-XH-REQUEST']);
        $client->refund(['open_order_id' => 'OPEN-XH-REQUEST']);

        $this->assertSame('/payment/do.html', $history[0]['request']->getUri()->getPath(), '虎皮椒下单路径不正确');
        $this->assertSame('/payment/query.html', $history[1]['request']->getUri()->getPath(), '虎皮椒查单路径不正确');
        $this->assertSame('/payment/refund.html', $history[2]['request']->getUri()->getPath(), '虎皮椒退款路径不正确');
        foreach ($history as $entry) {
            $this->assertSame('https', $entry['request']->getUri()->getScheme(), '虎皮椒所有 SDK 请求必须使用 HTTPS');
        }
        $this->assertTrue(
            str_starts_with(strtolower($history[0]['request']->getHeaderLine('Content-Type')), 'application/json'),
            '虎皮椒支付请求必须使用 JSON'
        );
        $payBody = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertTrue(is_array($payBody) && $client->verify($payBody), '虎皮椒支付请求必须包含可验证签名');
        $this->assertSame('alipay', (string) $payBody['payment'], '虎皮椒 payment 字段必须原样提交');

        parse_str((string) $history[1]['request']->getBody(), $queryBody);
        $this->assertSame('P-XH-REQUEST', (string) $queryBody['out_trade_order'], '虎皮椒查单必须使用 out_trade_order');
        $this->assertFalse(array_key_exists('trade_order_id', $queryBody), '虎皮椒查单不得发送猜测的 trade_order_id');
        $this->assertTrue($client->verify($queryBody), '虎皮椒查单表单必须签名');
        parse_str((string) $history[2]['request']->getBody(), $refundBody);
        $this->assertSame('OPEN-XH-REQUEST', (string) $refundBody['open_order_id'], '虎皮椒退款必须使用 open_order_id');
        $this->assertFalse(array_key_exists('refund_fee', $refundBody), '虎皮椒全额退款请求不得新增官方未定义的金额字段');

        $signedError = ['errcode' => 500, 'errmsg' => 'explicit reject'];
        $signedError['hash'] = $client->sign($signedError);
        $errorClient = new XunhupayClient([
            'appid' => 'APP-001',
            'api_key' => 'unit-secret',
        ]);
        $this->setObjectProperty($errorClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'errcode' => 500,
                    'errmsg' => 'forged reject',
                    'hash' => str_repeat('0', 32),
                ])),
                new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($signedError)),
            ]),
        ]));
        try {
            $errorClient->refund(['open_order_id' => 'OPEN-XH-REQUEST']);
            throw new \RuntimeException('虎皮椒错签错误响应必须抛 SDK 异常');
        } catch (XunhupaySdkException $e) {
            $this->assertFalse($e->isDefinitive(), '虎皮椒错签 errcode 不得被当作明确拒绝');
        }
        try {
            $errorClient->refund(['open_order_id' => 'OPEN-XH-REQUEST']);
            throw new \RuntimeException('虎皮椒已签名非零 errcode 必须抛 SDK 异常');
        } catch (XunhupaySdkException $e) {
            $this->assertTrue($e->isDefinitive(), '虎皮椒只有已验签的非零 errcode 才是明确拒绝');
            $this->assertSame('500', $e->channelErrorCode(), '虎皮椒明确拒绝必须保留受控错误码');
        }
    }

    /**
     * 虎皮椒终端环境、稳定产品编码、WAP 字段和不确定下单边界。
     */
    private function testXunhupayProductRoutingAndWapFields(): void
    {
        $client = new XunhupayUnitClient(static fn (string $action, array $data, int $count): array => [
            'errcode' => 0,
            'errmsg' => 'success!',
            'hash' => str_repeat('a', 32),
            'url' => 'https://api.xunhupay.com/mobile/' . $count,
            'url_qrcode' => 'https://api.xunhupay.com/qrcode/' . $count . '.png',
            'open_order_id' => 'OPEN-XH-' . $count,
        ]);
        $plugin = $this->xunhupayPlugin($client);
        $mobileCases = [
            ['alipay', 'mobile', 'alipay_h5', 'alipay'],
            ['wxpay', 'mobile', 'wechat_h5', 'wechat'],
            ['alipay', 'wechat', 'alipay_h5', 'alipay'],
            ['wxpay', 'wechat', 'wechat_h5', 'wechat'],
            ['alipay', 'alipay', 'alipay_h5', 'alipay'],
            ['wxpay', 'alipay', 'wechat_h5', 'wechat'],
        ];
        foreach ($mobileCases as [$payType, $env, $product, $payment]) {
            $order = $this->xunhupayOrder($payType, $env);
            $result = $plugin->pay($order);
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();
            $call = $client->calls[array_key_last($client->calls)];
            $this->assertSame($product, (string) $result['pay_product'], '虎皮椒 H5 稳定产品编码不正确');
            $this->assertSame('jump', (string) $result['presentation']['pay_page'], '虎皮椒非 PC 环境必须使用 WAP 跳转');
            $this->assertSame($payment, (string) $call['data']['payment'], '虎皮椒上游 payment 映射不正确');
            $this->assertSame('WAP', (string) $call['data']['type'], '虎皮椒 H5 type 必须精确为 WAP');
            $this->assertSame('merchant.unit.test', (string) $call['data']['wap_url'], '虎皮椒 wap_url 必须是同步返回主机名');
            $this->assertSame('MPAY Unit Shop', (string) $call['data']['wap_name'], '虎皮椒 wap_name 配置未生效');
            $this->assertSame($order['pay_no'], (string) $call['data']['trade_order_id'], '虎皮椒 trade_order_id 必须是当前 pay_no');
            $this->assertSame('1.23', (string) $call['data']['total_fee'], '虎皮椒 total_fee 必须是两位元字符串');
            $this->assertSame($order['callback_url'], (string) $call['data']['notify_url'], '虎皮椒通知必须复用公共支付回调路由');
            $this->assertSame($order['return_url'], (string) $call['data']['return_url'], '虎皮椒 return_url 必须精确传递');
            $raw = $result['presentation']['pay_params']['raw'];
            $this->assertFalse(array_key_exists('hash', $raw), '虎皮椒 pay_params.raw 不得保留 hash');
            $this->assertFalse(array_key_exists('url', $raw), '虎皮椒 pay_params.raw 不得保留完整跳转地址');
            $this->assertFalse(in_array('hash', $raw['response_fields'], true), '虎皮椒响应字段摘要不得列出 hash');
        }

        $pcAlipay = $plugin->pay($this->xunhupayOrder('alipay', 'pc'));
        $pcWechat = $plugin->pay($this->xunhupayOrder('wxpay', 'pc'));
        foreach ([$pcAlipay, $pcWechat] as $result) {
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();
            $this->assertSame('qrcode', (string) $result['presentation']['pay_page'], '虎皮椒 PC 环境必须使用二维码承接');
        }
        $this->assertSame('alipay', (string) $pcAlipay['pay_product'], '虎皮椒 PC 支付宝产品编码不稳定');
        $this->assertSame('wechat', (string) $pcWechat['pay_product'], '虎皮椒 PC 微信产品编码不稳定');
        $this->assertSame(2, count($client->qrcodeUrls), '虎皮椒 PC 下单必须解析 url_qrcode');

        $schema = $plugin->getConfigSchema();
        $enabled = array_values(array_filter($schema, static fn (array $field): bool => ($field['field'] ?? '') === 'enabled_products'));
        $options = array_map(static fn (array $option): string => (string) $option['value'], $enabled[0]['options'] ?? []);
        $this->assertSame(['alipay_h5', 'wechat_h5', 'alipay', 'wechat'], $options, '虎皮椒产品开关必须使用同一组稳定编码');

        $disabledClient = new XunhupayUnitClient(fn (): array => []);
        $disabled = $this->xunhupayPlugin($disabledClient, ['enabled_products' => ['alipay_h5']]);
        $this->assertThrows(fn () => $disabled->pay($this->xunhupayOrder('alipay', 'pc')), '虎皮椒未开通扫码产品必须本地拒绝');
        $this->assertSame(0, count($disabledClient->calls), '虎皮椒产品关闭时不得请求上游');

        $uncertainClient = new XunhupayUnitClient(
            fn (): XunhupaySdkException => new XunhupaySdkException('unit timeout')
        );
        $uncertain = $this->xunhupayPlugin($uncertainClient);
        try {
            $uncertain->pay($this->xunhupayOrder('wxpay', 'mobile'));
            throw new \RuntimeException('虎皮椒下单超时必须保持结果不确定');
        } catch (PaymentUncertainException $e) {
            $context = json_encode($e->getData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            $this->assertFalse(str_contains($context, 'xunhu-unit-secret'), '虎皮椒异常上下文不得保存密钥');
            $this->assertFalse(str_contains($context, 'hash'), '虎皮椒异常上下文不得保存签名或完整响应');
        }
        $this->assertSame(1, count($uncertainClient->calls), '虎皮椒下单不确定后不得尝试另一个产品');
    }

    /**
     * 虎皮椒二维码图片的协议、目标、响应类型、大小和 data 内容约束。
     */
    private function testXunhupayQrcodeSafety(): void
    {
        $client = new XunhupayClient([
            'appid' => 'APP-001',
            'api_key' => 'unit-secret',
            'api_url' => 'https://api.xunhupay.com/payment/do.html',
        ]);
        $content = 'weixin://wxpay/bizpayurl?pr=secure-unit';
        $url = 'https://api.xunhupay.com/qrcode.png?data=' . rawurlencode(base64_encode($content));
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'image/png'], 'PNGDATA'),
            ]),
        ]));
        $this->assertSame($content, $client->parseQrcode($url), '虎皮椒二维码必须返回 data 中的真实支付内容');

        $redirectClient = new XunhupayClient([
            'appid' => 'APP-001',
            'api_key' => 'unit-secret',
            'api_url' => 'https://api.xunhupay.com/payment/do.html',
        ]);
        $redirectTarget = 'https://api.xunhupay.com/final.png?data=' . rawurlencode(base64_encode('alipays://platformapi/startapp?appId=20000067'));
        $this->setObjectProperty($redirectClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(302, ['Location' => $redirectTarget]),
                new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'image/jpeg'], 'JPEGDATA'),
            ]),
        ]));
        $this->assertSame(
            'alipays://platformapi/startapp?appId=20000067',
            $redirectClient->parseQrcode('https://api.xunhupay.com/redirect.png'),
            '虎皮椒二维码最多一次受信跳转后仍应解析真实内容'
        );

        $this->assertThrows(
            fn () => $client->parseQrcode('http://api.xunhupay.com/qrcode.png?data=x'),
            '虎皮椒二维码必须拒绝 HTTP 地址'
        );
        $this->assertThrows(
            fn () => $client->parseQrcode('https://evil.example/qrcode.png?data=x'),
            '虎皮椒二维码必须拒绝任意远程目标'
        );

        $untrustedRedirectClient = new XunhupayClient(['appid' => 'APP-001', 'api_key' => 'unit-secret']);
        $this->setObjectProperty($untrustedRedirectClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(302, ['Location' => 'https://evil.example/qrcode.png?data=x']),
            ]),
        ]));
        $this->assertThrows(
            fn () => $untrustedRedirectClient->parseQrcode('https://api.xunhupay.com/redirect.png'),
            '虎皮椒二维码必须拒绝跳转到非受信目标'
        );

        $htmlClient = new XunhupayClient(['appid' => 'APP-001', 'api_key' => 'unit-secret']);
        $this->setObjectProperty($htmlClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html>not qr</html>'),
            ]),
        ]));
        $this->assertThrows(fn () => $htmlClient->parseQrcode($url), '虎皮椒二维码不得把 HTML 当图片');

        $largeClient = new XunhupayClient(['appid' => 'APP-001', 'api_key' => 'unit-secret']);
        $this->setObjectProperty($largeClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => '1048577',
                ], 'x'),
            ]),
        ]));
        $this->assertThrows(fn () => $largeClient->parseQrcode($url), '虎皮椒二维码图片必须限制响应大小');

        $unsafeClient = new XunhupayClient(['appid' => 'APP-001', 'api_key' => 'unit-secret']);
        $unsafeUrl = 'https://api.xunhupay.com/qrcode.png?data=' . rawurlencode(base64_encode('javascript:alert(1)'));
        $this->setObjectProperty($unsafeClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'image/png'], 'PNGDATA'),
            ]),
        ]));
        $this->assertThrows(fn () => $unsafeClient->parseQrcode($unsafeUrl), '虎皮椒二维码内容必须拒绝可执行协议');

        $missingDataClient = new XunhupayClient(['appid' => 'APP-001', 'api_key' => 'unit-secret']);
        $this->setObjectProperty($missingDataClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => new \GuzzleHttp\Handler\MockHandler([
                new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'image/png'], 'PNGDATA'),
            ]),
        ]));
        $this->assertThrows(
            fn () => $missingDataClient->parseQrcode('https://api.xunhupay.com/qrcode.png'),
            '没有可逆 data 的远程位图不得被当作二维码内容'
        );
    }

    /**
     * 虎皮椒查单强关联、状态语义、全额退款和结果确定性。
     */
    private function testXunhupayQueryAndRefundSemantics(): void
    {
        $queryResponses = [];
        foreach (['OD', 'WP', 'CD', 'ZZ'] as $status) {
            $queryResponses[] = [
                'status' => $status,
                'trade_order_id' => 'P-XH-STATE',
                'total_fee' => '1.00',
                'open_order_id' => 'OPEN-XH-STATE',
            ];
        }
        $queryClient = new XunhupayUnitClient(static function (string $action) use (&$queryResponses): array {
            return $action === 'query' ? (array) array_shift($queryResponses) : [];
        });
        $plugin = $this->xunhupayPlugin($queryClient);
        $order = [
            'pay_no' => 'P-XH-STATE',
            'amount' => 100,
            'chan_order_no' => 'P-XH-STATE',
            'chan_trade_no' => 'OPEN-XH-STATE',
        ];
        foreach ([
            PaymentPluginStatusConstant::SUCCESS,
            PaymentPluginStatusConstant::PENDING,
            PaymentPluginStatusConstant::CLOSED,
            PaymentPluginStatusConstant::UNKNOWN,
        ] as $expectedStatus) {
            $query = $plugin->query($order);
            $this->assertSame($expectedStatus, (string) $query['status'], '虎皮椒查单状态映射错误');
            $this->assertSame('P-XH-STATE', (string) $query['pay_no'], '虎皮椒查单必须返回当前 MPAY pay_no');
            $this->assertSame('P-XH-STATE', (string) $query['chan_order_no'], '虎皮椒渠道订单号必须是一对一 trade_order_id');
            $this->assertSame('OPEN-XH-STATE', (string) $query['chan_trade_no'], '虎皮椒渠道流水必须是一对一 open_order_id');
            PaymentPluginQueryResultValidator::make($query)->withScene('query_result')->validate();
        }
        foreach ($queryClient->calls as $call) {
            $this->assertSame(['out_trade_order' => 'P-XH-STATE'], $call['data'], '虎皮椒查单请求只能使用 out_trade_order');
        }

        foreach ([
            ['trade_order_id' => 'P-XH-OTHER', 'total_fee' => '1.00', 'open_order_id' => 'OPEN-XH-STATE'],
            ['trade_order_id' => 'P-XH-STATE', 'total_fee' => '1.01', 'open_order_id' => 'OPEN-XH-STATE'],
            ['trade_order_id' => 'P-XH-STATE', 'total_fee' => '1.00', 'open_order_id' => 'OPEN-XH-OTHER'],
        ] as $wrong) {
            $wrongClient = new XunhupayUnitClient(fn (): array => ['status' => 'OD'] + $wrong);
            $wrongPlugin = $this->xunhupayPlugin($wrongClient);
            $this->assertThrows(fn () => $wrongPlugin->query($order), '虎皮椒查单错订单号、金额或 open_order_id 必须拒绝');
        }

        try {
            $plugin->close($order);
            throw new \RuntimeException('虎皮椒无确认协议时必须明确不支持关单');
        } catch (UnsupportedPaymentOperationException) {
        }

        $refundResponses = [];
        foreach (['CD', 'RD', 'OD', 'ZZ'] as $status) {
            $refundResponses[] = [
                'trade_order_id' => 'P-XH-STATE',
                'transaction_id' => 'DO-NOT-GUESS-THIS',
                'out_refund_no' => 'RF-XH-' . $status,
                'refund_fee' => '1.00',
                'refund_status' => $status,
            ];
        }
        $refundClient = new XunhupayUnitClient(static function (string $action) use (&$refundResponses): array {
            return $action === 'refund' ? (array) array_shift($refundResponses) : [];
        });
        $refundPlugin = $this->xunhupayPlugin($refundClient);
        $refundOrder = $order + ['refund_no' => 'R-XH-STATE', 'refund_amount' => 100];
        foreach ([
            PaymentPluginStatusConstant::SUCCESS,
            PaymentPluginStatusConstant::PENDING,
            PaymentPluginStatusConstant::PENDING,
            PaymentPluginStatusConstant::UNKNOWN,
        ] as $index => $expectedStatus) {
            $refund = $refundPlugin->refund($refundOrder);
            $this->assertSame($expectedStatus, (string) $refund['status'], '虎皮椒退款状态映射错误');
            $expectedRefundNo = ['RF-XH-CD', 'RF-XH-RD', 'RF-XH-OD', 'RF-XH-ZZ'][$index];
            $this->assertSame($expectedRefundNo, (string) $refund['chan_refund_no'], '虎皮椒退款号只能来自 out_refund_no');
            PaymentPluginRefundResultValidator::make($refund)->withScene('refund_result')->validate();
        }
        foreach ($refundClient->calls as $call) {
            $this->assertSame(['open_order_id' => 'OPEN-XH-STATE'], $call['data'], '虎皮椒退款只能提交 open_order_id');
        }

        $beforeInvalidRefund = count($refundClient->calls);
        $this->assertThrows(
            fn () => $refundPlugin->refund(array_replace($refundOrder, ['refund_amount' => 50])),
            '虎皮椒必须拒绝部分退款'
        );
        $this->assertThrows(
            fn () => $refundPlugin->refund(array_replace($refundOrder, ['chan_trade_no' => ''])),
            '虎皮椒退款缺少 open_order_id 必须拒绝'
        );
        $this->assertSame($beforeInvalidRefund, count($refundClient->calls), '虎皮椒无效退款不得请求上游');

        $failedRefund = $this->xunhupayPlugin(new XunhupayUnitClient(fn (): array => [
            'trade_order_id' => 'P-XH-STATE',
            'out_refund_no' => 'RF-XH-UD',
            'refund_fee' => '1.00',
            'refund_status' => 'UD',
        ]));
        try {
            $failedRefund->refund($refundOrder);
            throw new \RuntimeException('虎皮椒明确退款失败必须抛确定性异常');
        } catch (PaymentDefinitiveException) {
        }
        $uncertainRefund = $this->xunhupayPlugin(new XunhupayUnitClient(
            fn (): XunhupaySdkException => new XunhupaySdkException('unit timeout')
        ));
        try {
            $uncertainRefund->refund($refundOrder);
            throw new \RuntimeException('虎皮椒退款超时必须保持结果不确定');
        } catch (PaymentUncertainException) {
        }
        $definitiveRefund = $this->xunhupayPlugin(new XunhupayUnitClient(
            fn (): XunhupaySdkException => new XunhupaySdkException('unit rejected', true, '400')
        ));
        try {
            $definitiveRefund->refund($refundOrder);
            throw new \RuntimeException('虎皮椒明确拒绝退款必须抛确定性异常');
        } catch (PaymentDefinitiveException) {
        }
    }

    /**
     * 虎皮椒回调验签、订单/金额/商户关联、重复通知和非 OD 语义。
     */
    private function testXunhupayNotifyBusinessValidation(): void
    {
        $payNo = 'P-XH-NOTIFY';
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => $payNo,
            'pay_amount' => 100,
            'channel_id' => 81,
            'channel_order_no' => $payNo,
            'channel_trade_no' => 'OPEN-XH-NOTIFY',
        ]);
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $client = new XunhupayUnitClient(fn (): array => []);
        $plugin = $this->xunhupayPlugin($client, [], $repository);
        $payload = [
            'trade_order_id' => $payNo,
            'total_fee' => '1.00',
            'transaction_id' => 'DO-NOT-GUESS-THIS',
            'open_order_id' => 'OPEN-XH-NOTIFY',
            'status' => 'OD',
            'appid' => 'XUNHU-APP-001',
            'time' => '1700000000',
            'nonce_str' => 'notify-unit',
        ];
        $payload['hash'] = $client->sign($payload);
        $result = $plugin->notify($this->rawFormRequest($payload));
        $repeat = $plugin->notify($this->rawFormRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '虎皮椒仅 status=OD 才能归一为支付成功');
        $this->assertSame(100, (int) $result['paid_amount'], '虎皮椒成功回调金额必须是整数分');
        $this->assertSame($payNo, (string) $result['chan_order_no'], '虎皮椒 trade_order_id 必须映射为渠道订单号');
        $this->assertSame('OPEN-XH-NOTIFY', (string) $result['chan_trade_no'], '虎皮椒 open_order_id 必须映射为渠道流水号');
        $this->assertSame($result, $repeat, '虎皮椒相同通知重放必须得到稳定归一结果');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        $pending = $payload;
        $pending['status'] = 'WP';
        $pending['hash'] = $client->sign($pending);
        $pendingResult = $plugin->notify($this->rawFormRequest($pending));
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pendingResult['status'], '虎皮椒非 OD 通知不得一律终态失败');
        $this->assertSame(null, $pendingResult['paid_amount'], '虎皮椒 pending 通知不得携带成功金额');
        PaymentPluginNotifyResultValidator::make($pendingResult)->withScene('notify_result')->validate();

        $badSignature = $payload;
        $badSignature['hash'] = str_repeat('0', 32);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($badSignature)), '虎皮椒错签名回调必须拒绝');

        $wrongTrade = $payload;
        $wrongTrade['trade_order_id'] = 'P-XH-OTHER';
        $wrongTrade['hash'] = $client->sign($wrongTrade);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($wrongTrade)), '虎皮椒错订单回调必须拒绝');

        $wrongAmount = $payload;
        $wrongAmount['total_fee'] = '1.01';
        $wrongAmount['hash'] = $client->sign($wrongAmount);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($wrongAmount)), '虎皮椒错金额回调必须拒绝');

        $wrongOpenId = $payload;
        $wrongOpenId['open_order_id'] = 'OPEN-XH-OTHER';
        $wrongOpenId['hash'] = $client->sign($wrongOpenId);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($wrongOpenId)), '虎皮椒重复回调 open_order_id 不一致必须拒绝');

        $wrongAppid = $payload;
        $wrongAppid['appid'] = 'XUNHU-OTHER';
        $wrongAppid['hash'] = $client->sign($wrongAppid);
        $this->assertThrows(fn () => $plugin->notify($this->rawFormRequest($wrongAppid)), '虎皮椒回调必须绑定当前商户 APPID');
    }

    /**
     * 掌易收固定签名向量和响应类型契约。
     */
    private function testZhangyishouSignatureAndResponseContract(): void
    {
        $client = new ZhangyishouClient([
            'merchant_no' => 'MNO-UNIT',
            'api_key' => 'secret-key',
        ]);
        $addOrder = [
            'MerchantId' => 'LOGIN-UNIT',
            'DownstreamOrderNo' => 'P-ZYS-SIGN',
            'OrderTime' => '2026-07-17 12:34:56',
            'PayChannelId' => '12001',
            'AsynPath' => 'https://mpay.test/api/pay/P-ZYS-SIGN/callback',
            'OrderMoney' => '1.23',
            'IPPath' => '127.0.0.1',
            'Mproductdesc' => '掌易收测试订单',
            'ReturnUrl' => 'https://mpay.test/payment/P-ZYS-SIGN',
        ];
        $addPayload = $this->privateMethod(ZhangyishouClient::class, 'buildAddOrderPayload')
            ->invoke($client, $addOrder);
        $this->assertSame([
            'MerchantId', 'DownstreamOrderNo', 'OrderTime', 'PayChannelId', 'AsynPath',
            'OrderMoney', 'IPPath', 'MD5Sign', 'MerchantNo', 'Mproductdesc', 'ReturnUrl',
        ], array_keys($addPayload), '掌易收 AddOrder 报文字段顺序不符合 rainbow_legacy');
        $this->assertSame(
            'd3b6b48b4e46bef9492b00736b75a299',
            (string) $addPayload['MD5Sign'],
            '掌易收 AddOrder 固定签名向量不匹配'
        );
        $changedUnsigned = $addOrder;
        $changedUnsigned['Mproductdesc'] = '不参与签名的标题';
        $changedUnsigned['ReturnUrl'] = 'https://mpay.test/changed';
        $changedPayload = $this->privateMethod(ZhangyishouClient::class, 'buildAddOrderPayload')
            ->invoke($client, $changedUnsigned);
        $this->assertSame(
            (string) $addPayload['MD5Sign'],
            (string) $changedPayload['MD5Sign'],
            'MerchantNo、Mproductdesc 和 ReturnUrl 不得进入 AddOrder 签名'
        );

        $refundPayload = $this->privateMethod(ZhangyishouClient::class, 'buildRefundPayload')->invoke($client, [
            'MerchantId' => 'LOGIN-UNIT',
            'MerchantOrder' => 'ZYS-ORDER-001',
            'RefundAmount' => '0.50',
        ]);
        $this->assertSame(
            ['MerchantId', 'MerchantOrder', 'RefundAmount', 'MD5Sign'],
            array_keys($refundPayload),
            '掌易收退款报文字段顺序不符合 rainbow_legacy'
        );
        $this->assertSame(
            '34a88af31155bc080e5d64783d11ff63',
            (string) $refundPayload['MD5Sign'],
            '掌易收退款固定签名向量不匹配'
        );

        $notifySignature = $this->privateMethod(ZhangyishouClient::class, 'notifySignature')->invoke($client, [
            'MerchantId' => 'LOGIN-UNIT',
            'DownstreamOrderNo' => 'P-ZYS-SIGN',
        ]);
        $this->assertSame('49d2eb25bbaa205480695927b995de9a', $notifySignature, '掌易收通知固定签名向量不匹配');
        $this->assertTrue($client->verify([
            'MerchantId' => 'LOGIN-UNIT',
            'DownstreamOrderNo' => 'P-ZYS-SIGN',
            'Signature' => $notifySignature,
        ]), '掌易收固定通知签名应验签通过');

        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'Code' => '1009',
                'Info' => ['jsApiParameters' => ['appId' => 'unknown']],
                'Message' => 'OK',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
        $this->setObjectProperty($client, 'httpClient', new \GuzzleHttp\Client([
            'handler' => \GuzzleHttp\HandlerStack::create($mock),
            'http_errors' => false,
        ]));
        $thrown = null;
        try {
            $client->addOrder($addOrder);
        } catch (ZhangyishouSdkException $e) {
            $thrown = $e;
        }
        $this->assertTrue($thrown instanceof ZhangyishouSdkException, '掌易收 AddOrder 错响应类型必须抛 SDK 异常');
        $this->assertTrue($thrown?->isUncertain() === true, '成功码但 Info 类型不明时必须标记结果不确定');

        $rejectedClient = new ZhangyishouClient(['merchant_no' => 'MNO-UNIT', 'api_key' => 'secret-key']);
        $rejectedMock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], '{"Code":"2001","Message":"拒绝","Info":"sensitive"}'),
        ]);
        $this->setObjectProperty($rejectedClient, 'httpClient', new \GuzzleHttp\Client([
            'handler' => \GuzzleHttp\HandlerStack::create($rejectedMock),
            'http_errors' => false,
        ]));
        $rejected = null;
        try {
            $rejectedClient->addOrder($addOrder);
        } catch (ZhangyishouSdkException $e) {
            $rejected = $e;
        }
        $this->assertTrue($rejected instanceof ZhangyishouSdkException, '掌易收业务拒绝必须抛 SDK 异常');
        $this->assertFalse($rejected?->isUncertain() ?? true, '明确业务拒绝不得标记为结果不确定');
        $this->assertFalse(str_contains((string) $rejected?->getMessage(), 'sensitive'), '掌易收异常不得拼接完整 Info');
    }

    /**
     * 掌易收稳定产品、配置拆分、环境承接和不确定结果禁止 fallback。
     */
    private function testZhangyishouProductRoutingAndPresentation(): void
    {
        $client = new ZhangyishouUnitClient(static function (string $operation, array $data): array {
            if ($operation !== 'add_order') {
                return [];
            }

            $info = (string) ($data['PayChannelId'] ?? '') === 'WX-MOBILE-UNIT'
                ? (str_contains((string) ($data['DownstreamOrderNo'] ?? ''), 'HTTP')
                    ? 'https://pay.zhangyishou.test/mobile'
                    : 'weixin://dl/business/?ticket=unit')
                : 'https://pay.zhangyishou.test/default';

            return ['Code' => '1009', 'Info' => $info, 'Message' => 'OK'];
        });
        $plugin = $this->zhangyishouPlugin($client);
        $schema = [];
        foreach ($plugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $this->assertSame(
            ['merchant_id', 'merchant_no', 'api_key', 'pay_channel_id', 'wxpay_mobile_channel_id', 'enabled_products'],
            array_keys($schema),
            '掌易收配置必须拆成独立标准字段'
        );
        foreach (['appid', 'appurl', 'appkey', 'appmchid'] as $legacyField) {
            $this->assertFalse(array_key_exists($legacyField, $schema), '掌易收不得恢复旧配置字段：' . $legacyField);
        }
        $products = array_map(
            static fn (array $option): string => (string) ($option['value'] ?? ''),
            (array) ($schema['enabled_products']['options'] ?? [])
        );
        $this->assertSame(['default_channel', 'wxpay_mobile'], $products, '掌易收 enabled_products 必须使用稳定产品编码');

        foreach (['alipay', 'wxpay', 'qqpay', 'bank'] as $payType) {
            $result = $plugin->pay($this->zhangyishouOrder($payType, 'pc'));
            $this->assertSame('default_channel', (string) $result['pay_product'], 'PC 必须记录稳定默认产品：' . $payType);
            $this->assertSame('qrcode', (string) $result['presentation']['pay_page'], 'PC 必须使用二维码承接：' . $payType);
            $this->assertSame('DEFAULT-UNIT', (string) $result['channel_context']['pay_channel_id'], '实际通道 ID 必须进入 channel_context');
            $this->assertFalse(array_key_exists('raw', (array) $result['presentation']['pay_params']), '掌易收 presentation 不得保存完整响应');
            PaymentPluginPayResultValidator::make($result)->withScene('pay_result')->validate();
        }

        foreach (['alipay', 'qqpay', 'bank'] as $payType) {
            $result = $plugin->pay($this->zhangyishouOrder($payType, 'mobile'));
            $this->assertSame('qrcode', (string) $result['presentation']['pay_page'], '普通移动端非微信支付不得误标跳转：' . $payType);
            $this->assertSame('default_channel', (string) $result['pay_product'], '普通移动端非微信支付必须使用默认产品');
        }

        $mobileScheme = $plugin->pay($this->zhangyishouOrder('wxpay', 'mobile'));
        $this->assertSame('wxpay_mobile', (string) $mobileScheme['pay_product'], '普通移动微信必须使用稳定移动产品');
        $this->assertSame('urlscheme', (string) $mobileScheme['presentation']['pay_page'], '明确 scheme 返回值必须使用 urlscheme 承接');
        $this->assertSame('WX-MOBILE-UNIT', (string) $mobileScheme['channel_context']['pay_channel_id'], '移动实际通道 ID 必须进入 channel_context');

        $mobileHttpOrder = $this->zhangyishouOrder('wxpay', 'mobile');
        $mobileHttpOrder['pay_no'] = 'P-ZYS-WXPAY-MOBILE-HTTP';
        $mobileHttp = $plugin->pay($mobileHttpOrder);
        $this->assertSame('jump', (string) $mobileHttp['presentation']['pay_page'], '移动通道返回 HTTP URL 时不得误标 urlscheme');
        $this->assertSame('https://pay.zhangyishou.test/mobile', (string) $mobileHttp['presentation']['pay_params']['url'], '移动跳转 URL 映射错误');

        $wechat = $plugin->pay($this->zhangyishouOrder('wxpay', 'wechat'));
        $this->assertSame('default_channel', (string) $wechat['pay_product'], '微信内必须使用掌易收默认通道托管授权');
        $this->assertSame('jump', (string) $wechat['presentation']['pay_page'], '微信内默认通道必须返回跳转承接');
        $wechatCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('DEFAULT-UNIT', (string) $wechatCall['data']['PayChannelId'], '微信内不得误用普通移动通道');
        $this->assertSame('https://mpay.test/payment/P-ZYS-WXPAY-WECHAT', (string) $wechatCall['data']['ReturnUrl'], '微信内微信支付必须发送 ReturnUrl');

        $alipay = $plugin->pay($this->zhangyishouOrder('alipay', 'alipay'));
        $this->assertSame('jump', (string) $alipay['presentation']['pay_page'], '支付宝环境必须直接打开掌易收返回 URL');
        $alipayCall = $client->calls[array_key_last($client->calls)];
        $this->assertFalse(array_key_exists('ReturnUrl', $alipayCall['data']), '支付宝环境旧协议不签发 ReturnUrl');

        $qq = $plugin->pay($this->zhangyishouOrder('qqpay', 'qq'));
        $this->assertSame('jump', (string) $qq['presentation']['pay_page'], 'QQ 环境必须直接打开掌易收返回 URL');
        $qqCall = $client->calls[array_key_last($client->calls)];
        $this->assertSame('https://mpay.test/payment/P-ZYS-QQPAY-QQ', (string) $qqCall['data']['ReturnUrl'], 'QQ 内 QQ 支付必须发送 ReturnUrl');

        $fallbackClient = new ZhangyishouUnitClient(fn (): array => [
            'Code' => '1009',
            'Info' => 'https://pay.zhangyishou.test/default',
        ]);
        $fallback = $this->zhangyishouPlugin($fallbackClient, ['wxpay_mobile_channel_id' => '']);
        $fallbackResult = $fallback->pay($this->zhangyishouOrder('wxpay', 'mobile'));
        $this->assertSame('default_channel', (string) $fallbackResult['pay_product'], '移动通道未配置时只能在请求前回退默认通道');
        $this->assertSame(1, count($fallbackClient->calls), '本地未配置移动通道不得产生额外上游请求');

        $legacyProductClient = new ZhangyishouUnitClient(fn (): array => []);
        $legacyProduct = $this->zhangyishouPlugin($legacyProductClient, [
            'enabled_products' => ['pay_channel_id', 'wxpay_mobile_channel_id'],
        ]);
        $this->assertThrows(
            fn () => $legacyProduct->pay($this->zhangyishouOrder('alipay', 'pc')),
            '旧配置槽位不得继续充当掌易收产品编码'
        );
        $this->assertSame(0, count($legacyProductClient->calls), '旧产品编码必须在上游请求前被过滤');

        $uncertainClient = new ZhangyishouUnitClient(fn (): ZhangyishouSdkException => new ZhangyishouSdkException(
            '模拟成功响应结构不明',
            true
        ));
        $uncertain = $this->zhangyishouPlugin($uncertainClient);
        $this->assertThrowsClass(
            fn () => $uncertain->pay($this->zhangyishouOrder('wxpay', 'mobile')),
            PaymentUncertainException::class,
            '掌易收移动产品结果不确定时必须阻止默认通道 fallback'
        );
        $this->assertSame(1, count($uncertainClient->calls), '结果不确定时不得创建第二笔上游订单');

        foreach (['alipays://platformapi/startapp', '{"appId":"wx-unit"}'] as $ambiguousInfo) {
            $ambiguousClient = new ZhangyishouUnitClient(static fn (): array => [
                'Code' => '1009',
                'Info' => $ambiguousInfo,
            ]);
            $ambiguous = $this->zhangyishouPlugin($ambiguousClient);
            $this->assertThrowsClass(
                fn () => $ambiguous->pay($this->zhangyishouOrder('wxpay', 'mobile')),
                PaymentUncertainException::class,
                '未知 URI Scheme 或 JSAPI 字符串不得猜成微信 URL Scheme'
            );
            $this->assertSame(1, count($ambiguousClient->calls), '未知移动承接结果不得 fallback 重下单');
        }

        $pipeClient = new ZhangyishouUnitClient(fn (): array => []);
        $pipe = $this->zhangyishouPlugin($pipeClient, ['pay_channel_id' => 'DEFAULT|MOBILE']);
        $this->assertThrowsClass(
            fn () => $pipe->pay($this->zhangyishouOrder('alipay', 'pc')),
            PaymentDefinitiveException::class,
            '掌易收不得恢复竖线通道配置兼容'
        );
        $this->assertSame(0, count($pipeClient->calls), '竖线配置必须在上游请求前拒绝');
    }

    /**
     * 掌易收 JSON 通知签名、商户、订单、金额、状态和核心关联校验。
     */
    private function testZhangyishouNotifyBusinessValidation(): void
    {
        $plugin = $this->zhangyishouPlugin(new ZhangyishouUnitClient(fn (): array => []));
        $payload = [
            'MerchantId' => 'LOGIN-UNIT',
            'DownstreamOrderNo' => 'P-ZYS-NOTIFY-A',
            'OrderMoney' => '1.23',
            'OrderNo' => 'ZYS-ORDER-001',
            'OrderState' => '1',
            'Remark' => '支付成功',
        ];
        $payload['Signature'] = $this->zhangyishouNotifySignature($payload);
        $result = $plugin->notify($this->rawJsonRequest($payload));
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], '掌易收 OrderState=1 必须映射成功');
        $this->assertSame('P-ZYS-NOTIFY-A', (string) $result['pay_no'], '掌易收 DownstreamOrderNo 映射错误');
        $this->assertSame(123, (int) $result['paid_amount'], '掌易收通知元金额必须精确转整数分');
        $this->assertSame('ZYS-ORDER-001', (string) $result['chan_trade_no'], '掌易收 OrderNo 映射错误');
        PaymentPluginNotifyResultValidator::make($result)->withScene('notify_result')->validate();

        foreach (['0', '2', '9'] as $state) {
            $pendingPayload = array_replace($payload, ['OrderState' => $state, 'Remark' => '状态待确认']);
            $pendingPayload['Signature'] = $this->zhangyishouNotifySignature($pendingPayload);
            $pending = $plugin->notify($this->rawJsonRequest($pendingPayload));
            $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $pending['status'], '未公开状态不得误判终态失败：' . $state);
            PaymentPluginNotifyResultValidator::make($pending)->withScene('notify_result')->validate();
        }

        $badSignature = array_replace($payload, ['Signature' => 'bad-signature']);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($badSignature)), '掌易收错签名通知必须拒绝');

        $wrongMerchant = array_replace($payload, ['MerchantId' => 'LOGIN-WRONG']);
        $wrongMerchant['Signature'] = $this->zhangyishouNotifySignature($wrongMerchant);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($wrongMerchant)), '掌易收签名合法但错 MerchantId 必须拒绝');

        $missingTradeNo = array_replace($payload, ['OrderNo' => '']);
        $missingTradeNo['Signature'] = $this->zhangyishouNotifySignature($missingTradeNo);
        $this->assertThrows(fn () => $plugin->notify($this->rawJsonRequest($missingTradeNo)), '掌易收成功通知缺少 OrderNo 必须拒绝');

        $service = (new ReflectionClass(PayOrderCallbackService::class))->newInstanceWithoutConstructor();
        $urlOrder = new PayOrder();
        $urlOrder->forceFill(['pay_no' => 'P-ZYS-NOTIFY-B', 'pay_amount' => 123]);
        $payNoGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyPayNoMatches');
        $amountGuard = $this->privateMethod(PayOrderCallbackService::class, 'assertNotifyAmountMatches');
        $this->assertThrows(
            fn () => $payNoGuard->invoke($service, $urlOrder, $result),
            '掌易收 A 单通知投递到 B 单 URL 必须失败'
        );
        $this->assertThrows(
            fn () => $amountGuard->invoke($service, $urlOrder, array_replace($result, ['paid_amount' => 124])),
            '掌易收通知金额与本地支付单不一致必须失败'
        );

        $this->assertSame('OK', $plugin->notifySuccess(), '掌易收成功 ACK 必须保持 OK');
        $this->assertSame('ERROR', $plugin->notifyFail(), '掌易收失败 ACK 必须保持 ERROR');
    }

    /**
     * 掌易收退款受理语义、异常确定性及查单关单边界。
     */
    private function testZhangyishouRefundAndUnsupportedOperations(): void
    {
        $client = new ZhangyishouUnitClient(static function (string $operation, array $data, int $count): array {
            if ($operation !== 'refund') {
                return [];
            }

            return $count === 1
                ? ['Code' => '1009', 'RefundNo' => 'ZYS-REFUND-001']
                : ['Code' => '1009'];
        });
        $plugin = $this->zhangyishouPlugin($client);
        $order = [
            'refund_no' => 'R-ZYS-001',
            'pay_no' => 'P-ZYS-REFUND',
            'refund_amount' => 50,
            'chan_trade_no' => 'ZYS-ORDER-001',
        ];
        $accepted = $plugin->refund($order);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $accepted['status'], '掌易收 Code=1009 只能表示退款受理');
        $this->assertSame('ZYS-REFUND-001', (string) $accepted['chan_refund_no'], '掌易收顶层 RefundNo 必须精确映射');
        PaymentPluginRefundResultValidator::make($accepted)->withScene('refund_result')->validate();
        $this->assertSame('ZYS-ORDER-001', (string) $client->calls[0]['data']['MerchantOrder'], '掌易收 MerchantOrder 必须使用支付回调 OrderNo');
        $this->assertSame('0.50', (string) $client->calls[0]['data']['RefundAmount'], '掌易收退款金额必须使用两位元字符串');

        $withoutRefundNo = $plugin->refund($order);
        $this->assertSame(PaymentPluginStatusConstant::PENDING, (string) $withoutRefundNo['status'], '缺少明确资金状态时退款必须保持 pending');
        $this->assertSame('', (string) $withoutRefundNo['chan_refund_no'], '掌易收不得从候选字段猜测 RefundNo');
        $this->assertFalse((string) $accepted['status'] === PaymentPluginStatusConstant::SUCCESS, '返回 RefundNo 也不等于资金退款成功');

        $missingOrderNo = $this->zhangyishouPlugin(new ZhangyishouUnitClient(fn (): array => []));
        $this->assertThrowsClass(
            fn () => $missingOrderNo->refund(array_replace($order, ['chan_trade_no' => ''])),
            PaymentDefinitiveException::class,
            '掌易收退款缺少原 OrderNo 必须在请求前明确拒绝'
        );

        $definitive = $this->zhangyishouPlugin(new ZhangyishouUnitClient(
            fn (): ZhangyishouSdkException => new ZhangyishouSdkException('业务拒绝')
        ));
        $this->assertThrowsClass(
            fn () => $definitive->refund($order),
            PaymentDefinitiveException::class,
            '掌易收退款业务拒绝必须映射确定失败异常'
        );

        $uncertain = $this->zhangyishouPlugin(new ZhangyishouUnitClient(
            fn (): ZhangyishouSdkException => new ZhangyishouSdkException('通信超时', true)
        ));
        $this->assertThrowsClass(
            fn () => $uncertain->refund($order),
            PaymentUncertainException::class,
            '掌易收退款通信异常必须保持结果不确定'
        );

        $this->assertThrowsClass(
            fn () => $plugin->query(['pay_no' => 'P-ZYS-REFUND']),
            UnsupportedPaymentOperationException::class,
            '掌易收没有完整主动查单合同时必须明确不支持'
        );
        $this->assertThrowsClass(
            fn () => $plugin->close(['pay_no' => 'P-ZYS-REFUND']),
            UnsupportedPaymentOperationException::class,
            '掌易收没有关单证据时必须明确不支持'
        );
    }

    /**
     * 插件下单返回契约。
     */
    private function testPaymentPluginPayResultContract(): void
    {
        $valid = [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => 'P202605170001',
            'pay_type' => 'alipay',
            'pay_product' => 'scan',
            'pay_action' => 'qrcode',
            'chan_order_no' => 'C202605170001',
            'chan_trade_no' => '',
            'channel_context' => [],
            'presentation' => [
                'pay_page' => 'qrcode',
                'pay_type' => 'alipay',
                'pay_product' => 'scan',
                'pay_action' => 'qrcode',
                'pay_params' => ['qrcode' => 'https://example.test/pay'],
            ],
        ];
        $result = PaymentPluginPayResultValidator::make($valid)->withScene('pay_result')->validate();
        $this->assertSame('qrcode', (string) $result['presentation']['pay_page'], '插件下单有效返回应通过校验');

        $invalid = $valid;
        unset($invalid['presentation']);
        $this->assertThrows(
            fn () => PaymentPluginPayResultValidator::make($invalid)->withScene('pay_result')->validate(),
            'pending 下单结果必须提供完整收银台承接信息'
        );
    }

    /**
     * 插件回调返回契约。
     *
     * @return void
     */
    private function testPaymentPluginNotifyResultContract(): void
    {
        $valid = [
            'status' => 'success',
            'pay_no' => 'P202605170001',
            'paid_amount' => 100,
            'chan_order_no' => 'C202605170001',
            'chan_trade_no' => 'T202605170001',
            'paid_at' => '2026-05-17 12:00:00',
        ];
        $result = PaymentPluginNotifyResultValidator::make($valid)->withScene('notify_result')->validate();
        $this->assertSame('success', (string) $result['status'], '插件回调有效返回应通过校验');

        $invalid = $valid;
        $invalid['status'] = 'done';
        $this->assertThrows(
            fn () => PaymentPluginNotifyResultValidator::make($invalid)->withScene('notify_result')->validate(),
            '插件回调状态只能是 success/failed/pending'
        );
    }

    /**
     * 查单、关单和退款结果使用各自的精确状态与必要字段。
     */
    private function testPaymentPluginOperationResultContract(): void
    {
        $query = [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => 'P202605170001',
            'paid_amount' => 100,
            'chan_order_no' => 'C202605170001',
            'chan_trade_no' => 'T202605170001',
        ];
        PaymentPluginQueryResultValidator::make($query)->withScene('query_result')->validate();
        $this->assertThrows(
            fn () => PaymentPluginQueryResultValidator::make(array_diff_key($query, ['paid_amount' => true]))
                ->withScene('query_result')
                ->validate(),
            '成功查单必须返回整数分实付金额'
        );
        $maintenance = (new ReflectionClass(PaymentRuntimeMaintenanceService::class))->newInstanceWithoutConstructor();
        $validateQuery = $this->privateMethod(PaymentRuntimeMaintenanceService::class, 'validateQueryResult');
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P202605170001',
            'pay_amount' => 100,
            'channel_order_no' => '',
            'channel_trade_no' => '',
        ]);
        $this->assertThrows(
            fn () => $validateQuery->invoke($maintenance, $payOrder, array_replace($query, [
                'chan_order_no' => '',
                'chan_trade_no' => '',
            ])),
            '成功查单必须返回至少一个明确的渠道流水号'
        );

        PaymentPluginCloseResultValidator::make([
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => 'P202605170001',
        ])->withScene('close_result')->validate();
        $this->assertThrows(
            fn () => PaymentPluginCloseResultValidator::make([
                'status' => PaymentPluginStatusConstant::SUCCESS,
                'pay_no' => 'P202605170001',
            ])->withScene('close_result')->validate(),
            '关单结果不能用支付成功状态代替关闭状态'
        );

        $refund = [
            'status' => PaymentPluginStatusConstant::PENDING,
            'refund_no' => 'R202605170001',
            'pay_no' => 'P202605170001',
            'refund_amount' => 100,
            'chan_refund_no' => 'CR202605170001',
        ];
        PaymentPluginRefundResultValidator::make($refund)->withScene('refund_result')->validate();
        $this->assertThrows(
            fn () => PaymentPluginRefundResultValidator::make(array_replace($refund, [
                'status' => PaymentPluginStatusConstant::FAILED,
            ]))->withScene('refund_result')->validate(),
            '退款申请明确拒绝必须通过确定性异常表达'
        );
        PaymentPluginRefundResultValidator::make(array_replace($refund, [
            'status' => PaymentPluginStatusConstant::FAILED,
        ]))->withScene('refund_status_result')->validate();
    }

    /**
     * 支付后续操作只能读取下单时保存的明确渠道上下文。
     */
    private function testPaymentContextContract(): void
    {
        $service = (new ReflectionClass(RefundDispatchService::class))->newInstanceWithoutConstructor();
        $resolve = $this->privateMethod(RefundDispatchService::class, 'paymentContext');
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-CONTEXT-001',
            'ext_json' => [
                'presentation' => [
                    'pay_type' => 'alipay',
                    'pay_product' => 'scan',
                    'pay_params' => ['legacy' => 'must-not-be-inferred'],
                ],
            ],
        ]);
        $this->assertThrows(
            fn () => $resolve->invoke($service, $payOrder),
            '核心流程不得从 presentation 或 pay_params 推断支付上下文'
        );

        $payOrder->ext_json = [
            'payment_context' => [
                'pay_type' => 'alipay',
                'pay_product' => 'scan',
                'pay_action' => 'qrcode',
                'channel_context' => ['bill_date' => '2026-07-17'],
            ],
        ];
        $this->assertSame([
            'pay_type_code' => 'alipay',
            'pay_product' => 'scan',
            'pay_action' => 'qrcode',
            'channel_context' => ['bill_date' => '2026-07-17'],
        ], $resolve->invoke($service, $payOrder), '核心流程必须按标准字段原样传递支付上下文');
    }

    /**
     * 插件配置键不得被 MPAY 运行时上下文覆盖。
     */
    private function testPaymentPluginConfigNamespace(): void
    {
        $pluginConf = new PaymentPluginConf();
        $pluginConf->forceFill([
            'plugin_code' => 'unit_plugin',
            'config' => ['merchant_id' => 'UPSTREAM-MERCHANT'],
            'settlement_cycle_type' => 1,
            'settlement_cutoff_time' => '23:59:59',
        ]);
        $repository = new class($pluginConf) extends PaymentPluginConfRepository {
            public function __construct(private readonly PaymentPluginConf $record)
            {
                parent::__construct();
            }

            public function find(int|string $id, array $columns = ['*']): ?\support\Model
            {
                return $this->record;
            }
        };
        $factory = (new ReflectionClass(PaymentPluginFactoryService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty($factory, 'paymentPluginConfRepository', $repository);

        $channel = new PaymentChannel();
        $channel->forceFill([
            'id' => 17,
            'merchant_id' => 99,
            'pay_type_id' => 2,
            'api_config_id' => 7,
            'channel_mode' => 1,
        ]);
        $plugin = new PaymentPlugin();
        $plugin->forceFill([
            'code' => 'unit_plugin',
            'name' => 'Unit Plugin',
            'pay_types' => ['alipay'],
            'transfer_types' => [],
        ]);

        $build = $this->privateMethod(PaymentPluginFactoryService::class, 'buildChannelConfig');
        $config = $build->invoke($factory, $channel, $plugin);
        $this->assertSame('UPSTREAM-MERCHANT', (string) $config['merchant_id'], '插件 merchant_id 配置不得被内部商户 ID 覆盖');
        $this->assertSame(99, (int) $config['_runtime']['merchant_id'], '内部商户 ID 必须进入保留命名空间');
        $this->assertFalse(array_key_exists('_configured_merchant_id', $config), '不得继续生成临时兼容配置键');
    }

    /**
     * 退款单超过首次派发等待时间后，应提示后台人工重新派发。
     */
    private function testRefundDispatchAbnormalPresentation(): void
    {
        $service = new \app\service\payment\order\RefundReportService();
        $abnormal = $service->formatRefundOrderRow([
            'status' => TradeConstant::REFUND_STATUS_CREATED,
            'created_at' => date('Y-m-d H:i:s', time() - 61),
        ]);
        $this->assertTrue((bool) $abnormal['dispatch_abnormal'], '超时 CREATED 退款单应标记首次派发超时');
        $this->assertSame(
            (string) TradeConstant::refundStatusMap()[TradeConstant::REFUND_STATUS_CREATED],
            (string) $abnormal['status_text'],
            '派发超时不得覆盖退款单真实状态文案'
        );
        $this->assertSame('首次派发超时', (string) $abnormal['dispatch_warning_text'], '派发超时应作为独立提示');

        $waiting = $service->formatRefundOrderRow([
            'status' => TradeConstant::REFUND_STATUS_CREATED,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertFalse((bool) $waiting['dispatch_abnormal'], '刚创建的退款单仍处于正常派发等待期');
        $this->assertSame('', (string) $waiting['dispatch_warning_text'], '正常等待派发时不应显示异常提示');

        $manual = $service->formatRefundOrderRow([
            'status' => TradeConstant::REFUND_STATUS_CREATED,
            'created_at' => date('Y-m-d H:i:s', time() - 61),
            'ext_json' => ['admin_action' => ['type' => 'manual_refund']],
        ]);
        $this->assertFalse((bool) $manual['dispatch_abnormal'], '手动登记退款不得提示调用上游重新派发');
    }

    /**
     * 收银台在展示快照缺失时应按本地订单状态安全承接。
     */
    private function testCashierPaymentStatePresentation(): void
    {
        $service = (new ReflectionClass(\app\service\payment\cashier\CashierService::class))
            ->newInstanceWithoutConstructor();
        $resolve = $this->privateMethod(\app\service\payment\cashier\CashierService::class, 'resolvePresentation');

        $existing = new PayOrder();
        $existing->forceFill([
            'status' => TradeConstant::ORDER_STATUS_PAYING,
            'ext_json' => [
                'presentation' => [
                    'pay_page' => 'qrcode',
                    'pay_type' => 'wechat',
                    'pay_product' => 'native',
                    'pay_action' => 'qrcode',
                    'pay_params' => [
                        'qrcode' => 'weixin://unit-test',
                        'raw' => ['upstream_response' => 'private'],
                        'options' => ['raw' => ['upstream_response' => 'private']],
                    ],
                ],
            ],
        ]);
        $existingPresentation = $resolve->invoke($service, $existing);
        $this->assertSame('qrcode', (string) $existingPresentation['pay_page'], '已有插件展示快照不得被订单状态投影覆盖');
        $this->assertSame('weixin://unit-test', (string) $existingPresentation['pay_params']['qrcode'], '已有插件承接参数必须保持不变');
        $this->assertFalse(
            array_key_exists('raw', (array) $existingPresentation['pay_params']),
            '收银台支付详情不得暴露 pay_params 第一层原始诊断数据'
        );
        $this->assertTrue(
            array_key_exists('raw', (array) $existingPresentation['pay_params']['options']),
            '收银台不得递归改写插件自定义的嵌套参数'
        );

        $failed = new PayOrder();
        $failed->forceFill([
            'status' => TradeConstant::ORDER_STATUS_FAILED,
            'channel_error_code' => " UPSTREAM_FAIL\n",
            'channel_error_msg' => "<b>渠道拒绝</b>\n参数错误",
            'ext_json' => [
                'presentation' => [
                    'pay_page' => 'qrcode',
                    'pay_params' => ['qrcode' => 'weixin://stale-payment'],
                ],
            ],
        ]);
        $failedPresentation = $resolve->invoke($service, $failed);
        $this->assertSame('error', (string) $failedPresentation['pay_page'], '确定失败订单必须覆盖旧展示快照并进入错误承接页');
        $this->assertSame('渠道拒绝 参数错误', (string) $failedPresentation['pay_params']['error_msg'], '公开错误信息必须移除 HTML 和控制空白');
        $this->assertSame('UPSTREAM_FAIL', (string) $failedPresentation['pay_params']['code'], '公开错误码必须经过规范化');

        $pending = new PayOrder();
        $pending->forceFill([
            'status' => TradeConstant::ORDER_STATUS_PAYING,
            'ext_json' => [],
        ]);
        $pendingPresentation = $resolve->invoke($service, $pending);
        $this->assertSame('page', (string) $pendingPresentation['pay_page'], '结果不确定的订单不得误展示为支付失败');
        $this->assertSame('paymentPending', (string) $pendingPresentation['pay_params']['_page'], '结果不确定的订单必须进入等待承接页');

        foreach ([
            TradeConstant::ORDER_STATUS_CLOSED => '已关闭',
            TradeConstant::ORDER_STATUS_TIMEOUT => '已超时',
        ] as $status => $message) {
            $terminal = new PayOrder();
            $terminal->forceFill(['status' => $status, 'ext_json' => []]);
            $terminalPresentation = $resolve->invoke($service, $terminal);
            $this->assertSame('error', (string) $terminalPresentation['pay_page'], '关闭或超时订单必须进入错误承接页');
            $this->assertTrue(
                str_contains((string) $terminalPresentation['pay_params']['error_msg'], $message),
                '关闭或超时订单必须展示明确的本地状态'
            );
        }

        $format = $this->privateMethod(\app\service\payment\cashier\CashierService::class, 'formatConfirmAttempt');
        $bizOrder = new \app\model\payment\BizOrder();
        $bizOrder->forceFill([
            'biz_no' => 'B-CASHIER-PUBLIC',
            'merchant_order_no' => 'M-CASHIER-PUBLIC',
            'subject' => '收银台公开订单',
            'order_amount' => 100,
            'paid_amount' => 0,
            'refund_amount' => 0,
            'status' => TradeConstant::ORDER_STATUS_CREATED,
            'attempt_count' => 0,
            'ext_json' => ['merchant' => ['param' => 'private']],
        ]);
        $formatBizOrder = $this->privateMethod(\app\service\payment\cashier\CashierService::class, 'formatBizOrder');
        $publicBizOrder = $formatBizOrder->invoke($service, $bizOrder);
        $this->assertFalse(array_key_exists('ext_json', $publicBizOrder), '收银台业务单上下文不得返回完整扩展字段');

        $publicResult = $format->invoke($service, [
            'pay_order' => $existing,
            'payment_result' => [
                'status' => PaymentPluginStatusConstant::PENDING,
                'channel_context' => ['upstream_token' => 'private'],
                'presentation' => [
                    'pay_page' => 'qrcode',
                    'pay_params' => [
                        'qrcode' => 'weixin://unit-test',
                        'raw' => ['upstream_response' => 'private'],
                        'options' => ['raw' => ['upstream_response' => 'private']],
                    ],
                ],
            ],
        ], $bizOrder);
        $this->assertFalse(array_key_exists('payment_result', $publicResult), '收银台公开响应不得暴露完整插件结果');
        $this->assertFalse(array_key_exists('raw', (array) $publicResult['pay_info']), '收银台公开响应不得暴露插件原始诊断数据');
        $this->assertTrue(
            array_key_exists('raw', (array) $publicResult['pay_info']['options']),
            '收银台公开响应不得递归改写插件自定义的嵌套参数'
        );
    }

    /**
     * 网页流水监听插件运行时声明契约。
     *
     * @return void
     */
    private function testReceiptWatcherRuntimeContract(): void
    {
        $expected = [
            \app\common\payment\AlipayBillReceiptPayment::class => ['direct', false],
            \app\common\payment\FubeiDirectReceiptPayment::class => ['direct', true],
            \app\common\payment\FubeiReceiptPayment::class => ['browser', true],
            \app\common\payment\FuiouReceiptPayment::class => ['browser', true],
            \app\common\payment\HaikeMaqianReceiptPayment::class => ['browser', true],
            \app\common\payment\LakalaDirectReceiptPayment::class => ['direct', true],
            \app\common\payment\LakalaReceiptPayment::class => ['browser', true],
            \app\common\payment\PostarDirectReceiptPayment::class => ['direct', true],
            \app\common\payment\PostarReceiptPayment::class => ['browser', true],
            \app\common\payment\ShouQianBaReceiptPayment::class => ['direct', true],
            \app\common\payment\TianquePretranReceiptPayment::class => ['direct', true],
            \app\common\payment\TianqueReceiptPayment::class => ['browser', true],
            \app\common\payment\UsdtTrc20ReceiptPayment::class => ['direct', false],
            \app\common\payment\WangpuDirectReceiptPayment::class => ['direct', true],
            \app\common\payment\WangpuReceiptPayment::class => ['browser', true],
            \app\common\payment\YeepayBossDirectReceiptPayment::class => ['direct', true],
            \app\common\payment\YeepayBossReceiptPayment::class => ['browser', true],
            \app\common\payment\YishengDirectReceiptPayment::class => ['direct', true],
            \app\common\payment\YishengReceiptPayment::class => ['browser', true],
        ];

        foreach ($expected as $className => [$runtime, $preloginSupported]) {
            $plugin = (new ReflectionClass($className))->newInstanceWithoutConstructor();
            $this->assertTrue(
                $plugin instanceof \app\common\interface\ChannelNotifyPayloadInterface,
                $className . ' 必须实现 ChannelNotifyPayloadInterface'
            );
            $info = $plugin->receiptWatcherInfo();
            $this->assertSame($runtime, (string) ($info['runtime'] ?? ''), $className . ' watcher runtime 不正确');
            $this->assertSame(
                $preloginSupported,
                $info['prelogin_supported'] ?? null,
                $className . ' prelogin_supported 不正确'
            );
        }
    }

    /**
     * 网页流水监听四条 Stream 路由契约。
     *
     * @return void
     */
    private function testReceiptWatcherStreamContract(): void
    {
        $className = \app\service\payment\receipt\ReceiptWatcherService::class;
        $service = (new ReflectionClass($className))->newInstanceWithoutConstructor();
        $queryStreamKey = $this->privateMethod($className, 'queryStreamKey');
        $preloginStreamKey = $this->privateMethod($className, 'preloginStreamKey');

        $this->assertSame(
            'receipt_watcher_direct_query_stream',
            $queryStreamKey->invoke($service, 'direct'),
            'direct 查单 Stream 不正确'
        );
        $this->assertSame(
            'receipt_watcher_browser_query_stream',
            $queryStreamKey->invoke($service, 'browser'),
            'browser 查单 Stream 不正确'
        );
        $this->assertSame(
            'receipt_watcher_direct_prelogin_stream',
            $preloginStreamKey->invoke($service, 'direct'),
            'direct 预登录 Stream 不正确'
        );
        $this->assertSame(
            'receipt_watcher_browser_prelogin_stream',
            $preloginStreamKey->invoke($service, 'browser'),
            'browser 预登录 Stream 不正确'
        );
        $this->assertSame(null, $queryStreamKey->invoke($service, 'legacy'), '无效运行时不应获得查单 Stream');
        $this->assertSame(null, $preloginStreamKey->invoke($service, 'legacy'), '无效运行时不应获得预登录 Stream');
    }

    /**
     * 微信个人收款通知金额解析。
     *
     * @return void
     */
    private function testWechatReceiptAmountParser(): void
    {
        $plugin = (new ReflectionClass(WechatReceiptPayment::class))->newInstanceWithoutConstructor();
        $amountFromPayload = $this->privateMethod(WechatReceiptPayment::class, 'amountFromPayload');

        $this->assertSame(200, $amountFromPayload->invoke($plugin, [
            'content' => json_encode([
                'title' => '微信支付',
                'msg' => '个人收款码到账¥2.00',
            ], JSON_UNESCAPED_UNICODE),
        ]), '个人收款码到账金额应支持 ¥ 符号');

        $this->assertSame(123, $amountFromPayload->invoke($plugin, [
            'content' => json_encode([
                'title' => '微信收款助手',
                'msg' => '收款到账1.23元',
            ], JSON_UNESCAPED_UNICODE),
        ]), '原有收款到账金额格式应继续支持');
    }

    /**
     * 转账金额字符串解析。
     *
     * @return void
     */
    private function testTransferMoneyParser(): void
    {
        $service = (new ReflectionClass(TransferService::class))->newInstanceWithoutConstructor();
        $parse = $this->privateMethod(TransferService::class, 'parseMoneyToAmount');

        $this->assertSame(1, $parse->invoke($service, '0.01'), '0.01 应解析为 1 分');
        $this->assertSame(1000, $parse->invoke($service, '10'), '10 应解析为 1000 分');
        $this->assertSame(1203, $parse->invoke($service, '12.03'), '12.03 应解析为 1203 分');
        $this->assertSame(0, $parse->invoke($service, '12.345'), '超过两位小数应视为非法');
        $this->assertSame(0, $parse->invoke($service, 'abc'), '非数字金额应视为非法');
    }

    /**
     * 转账公开状态映射与未知结果资金语义。
     *
     * @return void
     */
    private function testTransferStatusSemantics(): void
    {
        $plugin = (new ReflectionClass(EpayV2Payment::class))->newInstanceWithoutConstructor();
        $transferResult = $this->privateMethod(EpayV2Payment::class, 'transferResult');

        $processing = $transferResult->invoke($plugin, ['code' => 0, 'status' => TransferConstant::TRANSFER_STATUS_PROCESSING]);
        $success = $transferResult->invoke($plugin, ['code' => 0, 'status' => TransferConstant::TRANSFER_STATUS_SUCCESS]);
        $failed = $transferResult->invoke($plugin, ['code' => 0, 'status' => TransferConstant::TRANSFER_STATUS_FAILED]);

        $this->assertSame(PaymentPluginStatusConstant::PENDING, $processing['status'] ?? '', 'V2 转账处理中状态不得映射为成功');
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, $success['status'] ?? '', 'V2 转账成功状态必须映射为成功');
        $this->assertSame(PaymentPluginStatusConstant::FAILED, $failed['status'] ?? '', 'V2 转账失败状态必须映射为失败');

        $service = (new ReflectionClass(TransferService::class))->newInstanceWithoutConstructor();
        $normalize = $this->privateMethod(TransferService::class, 'normalizePluginStatus');
        $this->assertSame(
            TransferConstant::TRANSFER_STATUS_PROCESSING,
            $normalize->invoke($service, [
                'success' => false,
                'status' => PaymentPluginStatusConstant::PENDING,
                'status_code' => TransferConstant::TRANSFER_STATUS_PROCESSING,
            ]),
            '协议 code 失败但转账状态未明确失败时不得释放资金'
        );
    }

    /**
     * 路由金额区间与日限额过滤。
     *
     * @return void
     */
    private function testRouteAmountAndDailyLimit(): void
    {
        $resolver = (new ReflectionClass(PaymentRouteResolverService::class))->newInstanceWithoutConstructor();
        $amountAllowed = $this->privateMethod(PaymentRouteResolverService::class, 'isAmountAllowed');
        $dailyAllowed = $this->privateMethod(PaymentRouteResolverService::class, 'isDailyLimitAllowed');

        $channel = $this->channel([
            'id' => 1,
            'min_amount' => 100,
            'max_amount' => 10000,
            'daily_limit_amount' => 20000,
            'daily_limit_count' => 2,
        ]);

        $this->assertTrue($amountAllowed->invoke($resolver, $channel, 100), '等于最小金额应允许');
        $this->assertFalse($amountAllowed->invoke($resolver, $channel, 99), '低于最小金额应拒绝');
        $this->assertFalse($amountAllowed->invoke($resolver, $channel, 10001), '高于最大金额应拒绝');

        $stat = (object) ['pay_amount' => 15000, 'pay_success_count' => 1];
        $this->assertTrue($dailyAllowed->invoke($resolver, $channel, 5000, '2026-05-17', $stat), '未超过日金额和日笔数应允许');
        $this->assertFalse($dailyAllowed->invoke($resolver, $channel, 5001, '2026-05-17', $stat), '超过日金额应拒绝');

        $stat = (object) ['pay_amount' => 1000, 'pay_success_count' => 2];
        $this->assertFalse($dailyAllowed->invoke($resolver, $channel, 100, '2026-05-17', $stat), '超过日笔数应拒绝');
    }

    /**
     * 默认通道选择规则。
     *
     * @return void
     */
    private function testRouteDefaultChannelSelection(): void
    {
        $resolver = (new ReflectionClass(PaymentRouteResolverService::class))->newInstanceWithoutConstructor();
        $sort = $this->privateMethod(PaymentRouteResolverService::class, 'sortCandidates');
        $select = $this->privateMethod(PaymentRouteResolverService::class, 'selectDefaultChannel');

        $candidates = [
            $this->candidate(2, 0, 1, 10),
            $this->candidate(1, 1, 2, 10),
            $this->candidate(3, 0, 0, 10),
        ];

        $ordered = $sort->invoke($resolver, $candidates, RouteConstant::ROUTE_MODE_FIRST_AVAILABLE);
        $selected = $select->invoke($resolver, $ordered);

        $this->assertSame(1, (int) $selected['channel']->id, '默认启用通道应优先被选择');
    }

    /**
     * 路由候选过滤原因。
     *
     * @return void
     */
    private function testRouteRejectReasons(): void
    {
        $resolver = (new ReflectionClass(PaymentRouteResolverService::class))->newInstanceWithoutConstructor();
        $reject = $this->privateMethod(PaymentRouteResolverService::class, 'resolveCandidateRejectReasons');
        $channel = $this->channel([
            'id' => 10,
            'status' => CommonConstant::STATUS_DISABLED,
            'pay_type_id' => 2,
            'plugin_code' => 'mock',
            'min_amount' => 100,
            'max_amount' => 200,
        ]);
        $plugin = new PaymentPlugin([
            'code' => 'mock',
            'status' => CommonConstant::STATUS_DISABLED,
            'pay_types' => ['wechat'],
        ]);

        $reasons = $reject->invoke($resolver, $channel, $plugin, 1, 'alipay', 50, '2026-05-17', null);

        $this->assertContains('通道已禁用', $reasons, '禁用通道应给出过滤原因');
        $this->assertContains('通道支付方式不匹配', $reasons, '支付方式不匹配应给出过滤原因');
        $this->assertContains('插件已禁用', $reasons, '禁用插件应给出过滤原因');
        $this->assertTrue(
            count(array_filter($reasons, static fn (string $reason): bool => str_contains($reason, '金额不在通道范围内'))) === 1,
            '金额区间不匹配应给出过滤原因'
        );
    }

    /**
     * 回调载荷处理契约。
     *
     * @return void
     */
    private function testCallbackPayloadContract(): void
    {
        $service = (new ReflectionClass(PayOrderCallbackService::class))->newInstanceWithoutConstructor();
        $build = $this->privateMethod(PayOrderCallbackService::class, 'buildCallbackPayload');
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P202605170001',
            'channel_id' => 12,
        ]);

        $payload = $build->invoke($service, $payOrder, ['raw' => 'payload'], [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'chan_order_no' => 'CO202605170001',
            'chan_trade_no' => 'CT202605170001',
            'paid_amount' => 100,
            'paid_at' => '2026-05-17 12:00:00',
        ]);

        $this->assertSame(true, $payload['success'], '成功回调载荷 success 应为 true');
        $this->assertSame(NotifyConstant::VERIFY_STATUS_SUCCESS, (int) $payload['verify_status'], '成功解析的回调应标记验签成功');
        $this->assertSame(NotifyConstant::PROCESS_STATUS_SUCCESS, (int) $payload['process_status'], '成功回调应标记处理成功');
        $this->assertSame('CO202605170001', (string) $payload['channel_order_no'], '回调载荷应保留渠道订单号');

        $pending = $build->invoke($service, $payOrder, [], [
            'status' => PaymentPluginStatusConstant::PENDING,
            'chan_order_no' => 'CO202605170002',
            'chan_trade_no' => 'CT202605170002',
        ]);
        $this->assertSame(NotifyConstant::PROCESS_STATUS_PENDING, (int) $pending['process_status'], '处理中回调应保持待处理状态');

        $failed = $build->invoke($service, $payOrder, [], [
            'status' => PaymentPluginStatusConstant::FAILED,
            'chan_order_no' => 'CO202605170003',
            'chan_trade_no' => 'CT202605170003',
            'channel_error_code' => 'FAIL',
            'channel_error_msg' => '支付失败',
        ]);
        $this->assertSame(false, $failed['success'], '失败回调载荷 success 应为 false');
        $this->assertSame(NotifyConstant::PROCESS_STATUS_FAILED, (int) $failed['process_status'], '失败回调应标记处理失败');
        $this->assertSame('FAIL', (string) $failed['channel_error_code'], '失败回调应保留渠道错误码');
    }

    /**
     * 重复回调请求摘要稳定性。
     *
     * @return void
     */
    private function testCallbackDuplicateRequestHash(): void
    {
        $service = (new ReflectionClass(NotifyService::class))->newInstanceWithoutConstructor();
        $hash = $this->privateMethod(NotifyService::class, 'payloadHash');
        $payload = [
            'trade_no' => 'P202605170001',
            'money' => '10.00',
            'nested' => ['a' => 1],
        ];

        $first = $hash->invoke($service, $payload);
        $second = $hash->invoke($service, $payload);
        $changedPayload = $payload;
        $changedPayload['money'] = '10.01';
        $changed = $hash->invoke($service, $changedPayload);

        $this->assertSame($first, $second, '相同回调载荷应生成相同 request_hash，便于识别重复通知');
        $this->assertFalse($first === $changed, '不同回调载荷不应生成相同 request_hash');
    }

    /**
     * 商户通知重试策略。
     *
     * @return void
     */
    private function testNotifyRetryPolicy(): void
    {
        $service = (new ReflectionClass(NotifyService::class))->newInstanceWithoutConstructor();
        $this->setObjectProperty($service, 'systemConfigRuntimeService', new class extends SystemConfigRuntimeService {
            /**
             * 创建不依赖真实配置仓库的测试配置读取器。
             */
            public function __construct()
            {
            }

            public function get(string $configKey, string|int|float|bool|null $default = '', bool $refresh = false): string
            {
                return match ($configKey) {
                    'pay_notify_retry_interval' => '10',
                    'pay_notify_retry_limit' => '3',
                    default => (string) $default,
                };
            }
        });

        $nextRetryAt = $this->privateMethod(NotifyService::class, 'nextRetryAt');
        $retryLimit = $this->privateMethod(NotifyService::class, 'retryLimit');

        $this->assertSame(3, $retryLimit->invoke($service), '通知最大重试次数应读取系统配置');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 0), 55, 70, '首次入队默认应约 60 秒后可重试');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 1), 595, 610, '首次失败后应按基础间隔重试');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 2), 1795, 1810, '第二次失败后应按三倍基础间隔重试');
        $this->assertDelayBetween($nextRetryAt->invoke($service, 3), 3595, 3610, '更高次数失败后应按六倍基础间隔重试');
    }

    /**
     * 敏感数据递归脱敏。
     *
     * @return void
     */
    private function testSensitiveMasking(): void
    {
        $masked = \app\common\util\FormatHelper::maskSensitiveData([
            'app_id' => 'appid-001',
            'app_secret' => 'secret-value-123456',
            'nested' => [
                'private_key' => 'abcdefghijklmnopqrstuvwxyz',
                'notify_url' => 'https://example.test/notify',
            ],
            'req' => '%253Cxml%253Efull-signed-payload%253C%252Fxml%253E',
            'response' => json_encode([
                'code' => 'SUCCESS',
                'amount' => '1.00',
                'openid' => 'wx-response-openid',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'auth_code' => '130000000000000000',
            'openid' => 'wx-user-openid-001',
            'sign' => 'base64-signature-value',
        ]);

        $this->assertSame('appid-001', (string) $masked['app_id'], '非敏感字段不应被脱敏');
        $this->assertSame('secr****3456', (string) $masked['app_secret'], 'secret 字段应脱敏保留首尾');
        $this->assertSame('abcd****wxyz', (string) $masked['nested']['private_key'], '嵌套 private_key 应脱敏');
        $this->assertSame('https://example.test/notify', (string) $masked['nested']['notify_url'], '普通 URL 字段不应被脱敏');
        $this->assertTrue((bool) ($masked['req']['opaque'] ?? false), '无法结构化解析的 req 只应保留摘要');
        $this->assertSame('json', (string) $masked['response']['format'], 'JSON response 应保留结构化诊断信息');
        $this->assertSame('SUCCESS', (string) $masked['response']['data']['code'], '非敏感响应码不应被整体遮蔽');
        $this->assertSame('1.00', (string) $masked['response']['data']['amount'], '非敏感金额应保留用于排障');
        $this->assertFalse((string) $masked['response']['data']['openid'] === 'wx-response-openid', '结构化 response 内用户身份仍需脱敏');
        $this->assertFalse((string) $masked['auth_code'] === '130000000000000000', '付款码不得明文写入日志');
        $this->assertFalse((string) $masked['openid'] === 'wx-user-openid-001', '用户身份不得明文写入日志');
        $this->assertFalse((string) $masked['sign'] === 'base64-signature-value', '签名不得明文写入日志');
    }

    /**
     * 结果不确定时不得切换产品重复创建上游订单。
     */
    private function testUncertainResultNeverFallback(): void
    {
        $plugin = (new ReflectionClass(ChinaumsApiPayment::class))->newInstanceWithoutConstructor();
        $canFallback = $this->privateMethod(ChinaumsApiPayment::class, 'directPaymentCanFallback');

        $this->assertFalse(
            (bool) $canFallback->invoke($plugin, new PaymentUncertainException('产品未开通，但请求结果不确定')),
            '结果不确定异常即使包含可回退关键词，也不得继续尝试第二支付产品'
        );
        $this->assertTrue(
            (bool) $canFallback->invoke($plugin, new PaymentException('产品未开通')),
            '明确的产品未开通错误仍应允许回退到下一候选产品'
        );
        $selectedProduct = $this->privateMethod(ChinaumsApiPayment::class, 'directPaymentSelectedProduct');
        $this->assertSame(
            'alipay_qr',
            (string) $selectedProduct->invoke($plugin, [
                'pay_type_code' => 'alipay',
            ], [
                'products' => ['alipay' => 'alipay_qr'],
                'handler' => static fn (): array => [],
            ], 'qrcode'),
            '结果不确定时必须保留已选处理器对应的真实插件产品，供原通道查单'
        );
        $execute = $this->privateMethod(ChinaumsApiPayment::class, 'executeDirectPaymentProduct');
        $this->assertThrowsClass(
            fn () => $execute->invoke($plugin, [
                'pay_type_code' => 'alipay',
                '_env' => 'pc',
                'extra' => ['payment' => []],
            ], [], '单元测试通道'),
            PaymentDefinitiveException::class,
            '没有可用产品属于请求上游前的确定失败'
        );

        $wechat = (new ReflectionClass(WechatApiPayment::class))->newInstanceWithoutConstructor();
        $wechatCanFallback = $this->privateMethod(WechatApiPayment::class, 'shouldFallbackProduct');
        $this->assertFalse(
            (bool) $wechatCanFallback->invoke($wechat, new PaymentUncertainException('产品未开通，但请求结果不确定')),
            '微信支付结果不确定时不得切换产品重复创建上游订单'
        );
    }

    /**
     * 晚到重复支付必须在后台展示为待退款异常，不改变支付成功状态。
     */
    private function testLateDuplicatePresentation(): void
    {
        $service = (new ReflectionClass(\app\service\payment\order\PayOrderQueryService::class))
            ->newInstanceWithoutConstructor();
        $present = $this->privateMethod(\app\service\payment\order\PayOrderQueryService::class, 'withLateDuplicatePresentation');
        $sanitize = $this->privateMethod(\app\service\payment\order\PayOrderQueryService::class, 'withoutInternalRecoveryMetadata');
        $sourceRow = [
            'status' => TradeConstant::ORDER_STATUS_SUCCESS,
            'payment_exception_no' => 'EXC202607190001',
            'payment_exception_type' => PaymentExceptionConstant::TYPE_PAY_LATE_DUPLICATE,
            'payment_exception_severity' => PaymentExceptionConstant::SEVERITY_HIGH,
            'payment_exception_status' => PaymentExceptionConstant::STATUS_OPEN,
            'payment_exception_source_type' => 'PAY_CALLBACK',
            'payment_exception_source_id' => 0,
            'payment_exception_summary' => '业务单已由其他支付单完成',
            'payment_exception_detail_json' => json_encode(['pay_amount' => 123], JSON_UNESCAPED_UNICODE),
            'payment_exception_resolution' => '',
            'payment_exception_resolution_ref_no' => '',
            'payment_exception_detected_at' => '2026-07-19 12:00:00',
            'payment_exception_resolved_at' => null,
            'ext_json' => [
                'payment_context' => [
                    'pay_type' => 'alipay',
                    '_provisional' => true,
                ],
                'merchant' => ['custom' => 'keep'],
            ],
        ];
        $row = $present->invoke($service, $sourceRow);

        $this->assertTrue((bool) $row['is_late_duplicate'], '重复支付必须带可检索异常标记');
        $this->assertSame('重复支付待退款', (string) $row['exception_status_text'], '重复支付处置状态文案不正确');
        $this->assertSame(123, (int) $row['late_duplicate']['pay_amount'], '重复支付证据快照应来自异常表');
        $this->assertFalse(array_key_exists('payment_exception_no', $row), '管理端展示完成后应移除内部联表别名字段');
        $this->assertSame(TradeConstant::ORDER_STATUS_SUCCESS, (int) $row['status'], '异常处置不得篡改真实支付成功状态');

        $publicRow = $sanitize->invoke($service, [
            'status' => TradeConstant::ORDER_STATUS_SUCCESS,
            'ext_json' => $sourceRow['ext_json'],
        ]);
        $this->assertFalse(array_key_exists('is_late_duplicate', $publicRow), 'V1/V2 响应不得增加重复支付展示字段');
        $this->assertFalse(array_key_exists('payment_context', $publicRow['ext_json']), 'V1/V2 响应不得暴露临时查单上下文');

        $lifecycle = (new ReflectionClass(\app\service\payment\order\PayOrderLifecycleService::class))
            ->newInstanceWithoutConstructor();
        $keepExt = $this->privateMethod(\app\service\payment\order\PayOrderLifecycleService::class, 'keepSupportedExtJson');
        $kept = $keepExt->invoke($lifecycle, $sourceRow['ext_json']);
        $this->assertSame('keep', (string) $kept['merchant']['custom'], '商户扩展数据应继续保留');
    }

    /**
     * 恢复调度必须落在专用表，不得回退到订单 JSON 扫描。
     */
    private function testPaymentRecoveryArchitectureContract(): void
    {
        $paths = [
            base_path('app/repository/payment/trade/PayOrderRepository.php'),
            base_path('app/repository/payment/trade/RefundOrderRepository.php'),
            base_path('app/repository/payment/trade/TransferOrderRepository.php'),
            base_path('app/service/payment/order/PayOrderLifecycleService.php'),
            base_path('app/service/payment/order/RefundLifecycleService.php'),
            base_path('app/service/payment/order/PayOrderQueryService.php'),
            base_path('app/service/payment/runtime/PaymentRuntimeMaintenanceService.php'),
            base_path('app/service/payment/transfer/TransferService.php'),
        ];
        foreach ($paths as $path) {
            $content = (string) file_get_contents($path);
            $this->assertFalse(str_contains($content, 'JSON_EXTRACT'), basename($path) . ' 不得扫描订单 JSON');
        }

        $ddl = (string) file_get_contents(base_path('database/schema/payment-middle-ddl.sql'));
        foreach ([
            'ma_payment_recovery_task',
            'ma_payment_exception',
            'account_reverse_required_amount',
            'account_reverse_collected_amount',
            'account_reverse_due_amount',
            'idx_task_schedule',
            'uk_subject_exception',
        ] as $required) {
            $this->assertTrue(str_contains($ddl, $required), '数据库结构缺少: ' . $required);
        }
    }

    /**
     * 支付运行时代码不得关闭 HTTPS 证书校验。
     */
    private function testPaymentTlsVerification(): void
    {
        $paths = [
            base_path('app/common/sdk'),
            base_path('app/common/payment'),
            base_path('app/service/payment'),
        ];
        $violations = [];
        foreach ($paths as $path) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    continue;
                }
                if (preg_match('/[\'\"]verify[\'\"]\s*=>\s*false\b/', $content) === 1
                    || preg_match('/[\'\"]verify[\'\"]\]\s*=\s*false\b/', $content) === 1
                    || preg_match('/bool\([\'\"]verify[\'\"]\s*,\s*false\b/', $content) === 1) {
                    $violations[] = str_replace('\\', '/', $file->getPathname());
                }
                if (preg_match('/private\s+const\s+(?!TEST_)[A-Z0-9_]*(?:GATEWAY|BASE_URL|ENDPOINT)[A-Z0-9_]*\s*=\s*[\'\"]http:\/\//', $content) === 1) {
                    $violations[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $violations, '支付运行时代码不得关闭 TLS 证书校验');
    }

    /**
     * 身份字段契约、私密配置过滤和 token 独占消费。
     */
    private function testIdentityContractAndClaim(): void
    {
        $service = (new ReflectionClass(PaymentIdentityService::class))->newInstanceWithoutConstructor();
        $validate = $this->privateMethod(PaymentIdentityService::class, 'validateRequirement');
        $identityValue = $this->privateMethod(PaymentIdentityService::class, 'identityValueForRequirement');
        $publicRequirement = $this->privateMethod(PaymentIdentityService::class, 'publicRequirement');

        $requirement = $validate->invoke($service, [
            'provider' => 'alipay',
            'auth_type' => 'alipay_oauth',
            'identity_field' => 'buyer_id',
            'identity_aliases' => [],
            'app_id' => '2026000000000001',
            '_alipay_config' => ['private_key' => 'private'],
        ]);
        $this->assertSame('', $identityValue->invoke($service, $requirement, [
            'buyer_open_id' => 'OPEN-ID',
        ]), '未声明别名时 buyer_open_id 不得冒充 buyer_id');

        $requirement['identity_aliases'] = ['buyer_open_id'];
        $this->assertSame('OPEN-ID', $identityValue->invoke($service, $requirement, [
            'buyer_open_id' => 'OPEN-ID',
        ]), '插件显式声明后才允许使用身份字段别名');
        $this->assertFalse(array_key_exists('_alipay_config', $publicRequirement->invoke($service, $requirement)), '授权私钥配置不得进入公共上下文');

        $this->assertThrows(
            fn () => $validate->invoke($service, [
                'provider' => 'wxpay',
                'auth_type' => 'mini_program',
                'identity_field' => 'openid',
                'app_id' => 'wx-mini-app',
                '_app_secret' => 'secret',
            ]),
            '小程序授权不得声明公众号 openid 字段'
        );

        $token = 'unit-' . bin2hex(random_bytes(8));
        $cacheKey = 'mpay_payment_identity_' . $token;
        Cache::set($cacheKey, ['created_at' => time()], 30);
        $claim = $service->claim($token);
        try {
            $this->assertThrows(fn () => $service->claim($token), '同一身份 token 不得被并发消费');
        } finally {
            $service->releaseClaim($claim);
            Cache::delete($cacheKey);
        }
    }

    /**
     * 构造支付宝密钥模式测试配置。
     *
     * @param array{private_key:string,public_key:string} $pair
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function alipayKeyConfig(array $pair, array $overrides = []): array
    {
        return array_replace([
            'mode' => 'key',
            'app_id' => '2026000000000001',
            'private_key' => $pair['private_key'],
            'alipay_public_key' => $pair['public_key'],
            'sandbox' => true,
        ], $overrides);
    }

    /**
     * 给支付宝通知测试数据生成 RSA2 签名。
     *
     * @param array<string, mixed> $payload 通知参数
     * @param string $privateKey 支付宝测试私钥
     * @return array<string, mixed> 已签名通知参数
     */
    private function signAlipayNotify(array $payload, string $privateKey): array
    {
        unset($payload['sign']);
        $payload['sign'] = AlipaySigner::sign(AlipaySigner::notifyContent($payload), $privateKey);

        return $payload;
    }

    /**
     * 构造支付宝插件标准下单参数。
     *
     * @param string $method 支付产品或协议方法
     * @param string $env 支付环境
     * @return array<string, mixed> 标准下单参数
     */
    private function alipayOrder(string $method = 'scan', string $env = 'pc'): array
    {
        return [
            'pay_no' => 'P202607150001',
            'amount' => 100,
            'subject' => 'MPAY 测试订单',
            'body' => '支付宝官方 API 单元测试',
            'callback_url' => 'https://example.test/api/pay/P202607150001/callback',
            'return_url' => 'https://example.test/payment/P202607150001',
            '_env' => $env,
            'extra' => [
                'payment' => $method !== '' ? ['method' => $method] : [],
            ],
        ];
    }

    /**
     * 构造支付宝 SDK 测试响应。
     *
     * @param string $method 支付宝接口方法
     * @param array<string, mixed> $data 响应节点数据
     * @return AlipayResponse SDK 响应
     */
    private function alipayResponse(string $method, array $data): AlipayResponse
    {
        $key = str_replace('.', '_', $method) . '_response';
        $decoded = [$key => $data, 'sign' => 'unit-test-signature'];

        return new AlipayResponse(
            $method,
            (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $decoded,
            true
        );
    }

    /**
     * 构造带 RSA2 签名的支付宝网关响应正文。
     *
     * @param string $method 支付宝接口方法
     * @param array<string, mixed> $data 响应节点数据
     * @param string $privateKey 支付宝测试私钥
     * @return string 网关响应正文
     */
    private function signedAlipayGatewayBody(string $method, array $data, string $privateKey): string
    {
        $node = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($node === false) {
            throw new \RuntimeException('生成支付宝测试响应失败');
        }
        $sign = AlipaySigner::sign($node, $privateKey);
        $key = str_replace('.', '_', $method) . '_response';

        return '{' . json_encode($key) . ':' . $node . ',"sign":' . json_encode($sign) . '}';
    }

    /**
     * 创建只在系统临时目录存在的测试证书链。
     *
     * @return array<string, string>
     */
    private function alipayCertificateFixture(): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mpay-alipay-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('创建支付宝测试证书目录失败');
        }

        try {
            $rootPair = RsaKeyPairGenerator::generate(2048);
            $appPair = RsaKeyPairGenerator::generate(2048);
            $alipayPair = RsaKeyPairGenerator::generate(2048);
            $opensslConfig = base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
            $options = ['digest_alg' => 'sha256', 'config' => $opensslConfig];

            $rootKey = openssl_pkey_get_private($rootPair['private_key']);
            $appKey = openssl_pkey_get_private($appPair['private_key']);
            $alipayKey = openssl_pkey_get_private($alipayPair['private_key']);
            $rootCsr = openssl_csr_new(['commonName' => 'MPAY Alipay Test Root'], $rootKey, $options);
            $appCsr = openssl_csr_new(['commonName' => 'MPAY Alipay Test App'], $appKey, $options);
            $alipayCsr = openssl_csr_new(['commonName' => 'MPAY Alipay Test Platform'], $alipayKey, $options);
            if ($rootCsr === false || $appCsr === false || $alipayCsr === false) {
                throw new \RuntimeException('生成支付宝测试证书请求失败');
            }

            $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 3650, $options, 1001);
            $appCert = openssl_csr_sign($appCsr, null, $appKey, 3650, $options, 1002);
            $alipayCert = openssl_csr_sign($alipayCsr, $rootCert, $rootKey, 3650, $options, 1003);
            if ($rootCert === false || $appCert === false || $alipayCert === false) {
                throw new \RuntimeException('签发支付宝测试证书失败');
            }

            $rootPem = $appPem = $alipayPem = '';
            if (!openssl_x509_export($rootCert, $rootPem)
                || !openssl_x509_export($appCert, $appPem)
                || !openssl_x509_export($alipayCert, $alipayPem)) {
                throw new \RuntimeException('导出支付宝测试证书失败');
            }

            $appPath = $directory . DIRECTORY_SEPARATOR . 'appCertPublicKey.crt';
            $alipayPath = $directory . DIRECTORY_SEPARATOR . 'alipayCertPublicKey_RSA2.crt';
            $rootPath = $directory . DIRECTORY_SEPARATOR . 'alipayRootCert.crt';
            file_put_contents($appPath, $appPem);
            file_put_contents($alipayPath, $alipayPem);
            file_put_contents($rootPath, $rootPem);

            return [
                'directory' => $directory,
                'app_private_key' => $appPair['private_key'],
                'alipay_private_key' => $alipayPair['private_key'],
                'app_cert_path' => $appPath,
                'alipay_cert_path' => $alipayPath,
                'root_cert_path' => $rootPath,
            ];
        } catch (Throwable $e) {
            $this->cleanupTestDirectory($directory);
            throw $e;
        }
    }

    /**
     * 删除测试证书临时目录。
     *
     * @param string $directory 临时目录
     * @return void
     */
    private function cleanupTestDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * Jeepay 官方 MD5 固定向量、HTTPS 门禁和同步响应验签。
     */
    private function testJeepayMd5HttpsAndResponseSignature(): void
    {
        $config = [
            'api_url' => 'https://pay.jeepay.unit.test',
            'api_key' => 'UNpEETkvMpqC9oDLBr9S2X7U92k462h3zhHiy7hj4xbw23PiWhMv6TCAQ2vh8PzynZXZYo9n6puxHkAHG7li6LZi8IpaQrshzydnBll64iKlb4U59ggiyCTaHJeqffiW',
        ];
        $client = new JeepayClient($config);
        $vector = [
            'mchNo' => 'M1682391685',
            'appId' => '6447428682ca7458118af79f',
            'mchOrderNo' => 'mho1694051705945',
            'wayCode' => 'ALI_BAR',
            'amount' => 1,
            'currency' => 'CNY',
            'clientIp' => '192.166.1.132',
            'subject' => '商品标题',
            'body' => '商品描述',
            'notifyUrl' => 'https://www.jeequan.com',
            'reqTime' => '1694051706',
            'version' => '1.0',
            'signType' => 'MD5',
            'channelExtra' => '{"authCode":"284957415846666792"}',
        ];
        $this->assertSame(
            '924065BA077FA461A9B06D2E76E9ED3C',
            $client->sign($vector),
            'Jeepay 官方 MD5 固定向量不匹配'
        );
        $this->assertThrows(
            fn () => new JeepayClient(['api_url' => 'http://pay.jeepay.unit.test', 'api_key' => 'secret']),
            'Jeepay 生产 SDK 必须拒绝 HTTP 地址'
        );
        $this->assertThrows(
            fn () => new JeepayClient(['api_url' => 'https://user:pass@pay.jeepay.unit.test', 'api_key' => 'secret']),
            'Jeepay SDK 必须拒绝 URL 内嵌凭证'
        );

        $businessData = [
            'payOrderId' => 'JP-PAY-SIGNED',
            'mchOrderNo' => 'P-JEEPAY-SIGNED',
            'orderState' => 1,
            'payDataType' => 'codeUrl',
            'payData' => 'https://pay.jeepay.unit.test/qrcode',
        ];
        $responseSigner = new JeepayClient(['api_url' => 'https://pay.jeepay.unit.test', 'api_key' => 'response-secret']);
        $body = json_encode([
            'code' => 0,
            'msg' => 'SUCCESS',
            'data' => $businessData,
            'sign' => $responseSigner->sign($businessData),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], (string) $body),
        ]);
        $signedClient = new JeepayClient(
            ['api_url' => 'https://pay.jeepay.unit.test', 'api_key' => 'response-secret'],
            new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock)])
        );
        $this->assertSame(
            $businessData,
            $signedClient->post('/api/pay/unifiedOrder', ['mchNo' => 'M-UNIT']),
            'Jeepay SDK 必须验签后返回同步 data'
        );

        $badBody = json_encode(['code' => 0, 'msg' => 'SUCCESS', 'data' => $businessData, 'sign' => str_repeat('0', 32)]);
        $badMock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], (string) $badBody),
        ]);
        $badClient = new JeepayClient(
            ['api_url' => 'https://pay.jeepay.unit.test', 'api_key' => 'response-secret'],
            new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($badMock)])
        );
        try {
            $badClient->post('/api/pay/unifiedOrder', ['mchNo' => 'M-UNIT']);
            throw new \RuntimeException('Jeepay 错签同步响应必须拒绝');
        } catch (JeepaySdkException $e) {
            $this->assertTrue($e->isUncertain(), 'Jeepay 成功响应错签时必须标记为结果不确定');
        }
    }

    /**
     * Jeepay 稳定产品、真实 wayCode、严格身份和全部已支持承接类型。
     */
    private function testJeepayProductsIdentityAndPayDataTypes(): void
    {
        $client = new JeepayUnitClient(static function (string $path, array $payload, int $index): array {
            $extra = json_decode((string) ($payload['channelExtra'] ?? '{}'), true);
            $extra = is_array($extra) ? $extra : [];
            [$type, $payData] = match ((string) $payload['wayCode']) {
                'ALI_QR', 'WX_NATIVE', 'QR_CASHIER' => ['codeUrl', 'https://jeepay.unit.test/qrcode/' . $index],
                'ALI_WAP', 'ALI_PC' => ($extra['payDataType'] ?? '') === 'form'
                    ? ['form', '<form method="post" action="https://openapi.alipay.com/gateway.do?charset=UTF-8"><input type="hidden" name="biz" value="A&amp;&quot;&lt;"><input type="submit"></form>']
                    : ['payUrl', 'https://jeepay.unit.test/pay/' . $index],
                'WX_H5' => ['payUrl', 'https://jeepay.unit.test/wxh5/' . $index],
                'ALI_JSAPI' => ['aliapp', json_encode(['alipayTradeNo' => 'ALI-JEEPAY-' . $index])],
                'WX_JSAPI', 'WX_LITE' => ['wxapp', json_encode([
                    'appId' => 'wx-jeepay-app',
                    'timeStamp' => '1700000000',
                    'nonceStr' => 'nonce-' . $index,
                    'package' => 'prepay_id=JEEPAY-' . $index,
                    'signType' => 'RSA',
                    'paySign' => 'pay-sign-' . $index,
                ])],
            };

            return [
                'payOrderId' => 'JP-PAY-' . $index,
                'mchOrderNo' => (string) $payload['mchOrderNo'],
                'orderState' => 1,
                'payDataType' => $type,
                'payData' => $payData,
            ];
        });

        $qrPlugin = $this->jeepayPlugin($client, ['alipay_way_code' => 'ALI_QR']);
        $qr = $qrPlugin->pay($this->jeepayOrder('alipay', 'pc', ['method' => 'qrcode'], ['pay_no' => 'P-JP-QR']));
        $this->assertSame('alipay_configured', (string) $qr['pay_product'], 'Jeepay pay_product 必须是稳定业务产品而非 wayCode');
        $this->assertSame('qrcode', (string) $qr['presentation']['pay_page'], 'Jeepay codeUrl 必须精确映射二维码承接');
        $this->assertSame('JP-PAY-1', (string) $qr['chan_order_no'], 'Jeepay payOrderId 必须映射 chan_order_no');
        $this->assertSame('', (string) $qr['chan_trade_no'], 'Jeepay 下单未返回 channelOrderNo 时 chan_trade_no 必须保持空');

        $jumpPlugin = $this->jeepayPlugin($client, ['alipay_way_code' => 'ALI_WAP']);
        $jump = $jumpPlugin->pay($this->jeepayOrder('alipay', 'mobile', ['method' => 'h5'], ['pay_no' => 'P-JP-JUMP']));
        $this->assertSame('jump', (string) $jump['presentation']['pay_page'], 'Jeepay payUrl 必须精确映射 HTTPS 跳转');

        $formPlugin = $this->jeepayPlugin($client, [
            'alipay_way_code' => 'ALI_WAP',
            'alipay_pay_data_type' => 'form',
        ]);
        $form = $formPlugin->pay($this->jeepayOrder('alipay', 'mobile', ['method' => 'h5'], ['pay_no' => 'P-JP-FORM']));
        $formHtml = (string) $form['presentation']['pay_params']['html'];
        $this->assertSame('html', (string) $form['presentation']['pay_page'], 'Jeepay form 必须映射 HTML 承接');
        $this->assertTrue(str_contains($formHtml, 'method="post"'), 'Jeepay form 必须重建为 POST');
        $this->assertTrue(str_contains($formHtml, 'value="A&amp;&quot;&lt;"'), 'Jeepay form 字段必须重新转义');
        $this->assertFalse(str_contains($formHtml, '<input type="submit">'), 'Jeepay 不得原样保留上游可执行表单内容');

        $aliPlugin = $this->jeepayPlugin($client);
        $aliOrder = $this->jeepayOrder('alipay', 'alipay', ['method' => 'jsapi', 'buyer_id' => '2088-JEEPAY'], ['pay_no' => 'P-JP-ALI-JS']);
        $this->assertSame(null, $aliPlugin->identityRequirement($aliOrder), 'Jeepay ALI_JSAPI 已有 buyer_id 时不应重复授权');
        $ali = $aliPlugin->pay($aliOrder);
        $this->assertSame('alipay_jsapi', (string) $ali['pay_product'], 'Jeepay ALI_JSAPI 必须返回稳定支付宝产品');
        $this->assertSame('ALI-JEEPAY-4', (string) $ali['presentation']['pay_params']['tradeNO'], 'Jeepay aliapp 必须只读取 alipayTradeNo');

        $wxPlugin = $this->jeepayPlugin($client);
        $wx = $wxPlugin->pay($this->jeepayOrder('wxpay', 'wechat', ['method' => 'jsapi', 'sub_openid' => 'WX-MP-OPENID'], ['pay_no' => 'P-JP-WX-MP']));
        $this->assertSame('wxpay_mp', (string) $wx['pay_product'], 'Jeepay WX_JSAPI 必须返回稳定公众号产品');
        $this->assertSame('jsapi', (string) $wx['presentation']['pay_page'], 'Jeepay WX_JSAPI wxapp 必须映射 JSAPI');
        $this->assertSame('prepay_id=JEEPAY-5', (string) $wx['presentation']['pay_params']['package'], 'Jeepay WX_JSAPI 参数映射错误');

        $mini = $wxPlugin->pay($this->jeepayOrder('wxpay', 'wechat', ['method' => 'mini', 'mini_openid' => 'WX-MINI-OPENID'], ['pay_no' => 'P-JP-WX-MINI']));
        $this->assertSame('wxpay_mini', (string) $mini['pay_product'], 'Jeepay WX_LITE 必须返回稳定小程序产品');
        $this->assertSame('page', (string) $mini['presentation']['pay_page'], 'Jeepay WX_LITE 必须映射小程序 page 承接');
        $this->assertSame('wechatMini', (string) $mini['presentation']['pay_params']['_page'], 'Jeepay WX_LITE 页面类型错误');

        $bank = $this->jeepayPlugin($client)->pay($this->jeepayOrder('bank', 'pc', ['method' => 'qrcode'], ['pay_no' => 'P-JP-BANK']));
        $this->assertSame('bank_configured', (string) $bank['pay_product'], 'Jeepay 银行卡托管收银台必须使用稳定产品');
        $this->assertSame('QR_CASHIER', (string) $client->calls[6]['data']['wayCode'], 'Jeepay 银行卡当前 profile 只能生成 QR_CASHIER');

        $instantClient = new JeepayUnitClient(static fn (string $path, array $payload): array => [
            'payOrderId' => 'JP-INSTANT-SUCCESS',
            'mchOrderNo' => (string) $payload['mchOrderNo'],
            'orderState' => 2,
            'payDataType' => 'none',
            'payData' => '',
        ]);
        $instant = $this->jeepayPlugin($instantClient)->pay(
            $this->jeepayOrder('alipay', 'pc', ['method' => 'qrcode'], ['pay_no' => 'P-JP-INSTANT'])
        );
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $instant['status'], 'Jeepay orderState=2 必须精确映射同步成功');
        $this->assertFalse(isset($instant['presentation']), 'Jeepay同步成功不应伪造 none presentation');

        foreach ($client->calls as $call) {
            $request = $call['data'];
            $this->assertSame('/api/pay/unifiedOrder', (string) $call['path'], 'Jeepay 支付路径必须固定 unifiedOrder');
            $this->assertSame('M-JEEPAY-UNIT', (string) $request['mchNo'], 'Jeepay mchNo 必须来自通道配置');
            $this->assertSame('APP-JEEPAY-UNIT', (string) $request['appId'], 'Jeepay appId 必须来自通道配置');
            $this->assertSame('1.0', (string) $request['version'], 'Jeepay 接口版本必须固定 1.0');
            $this->assertSame('MD5', (string) $request['signType'], 'Jeepay 签名类型必须固定 MD5');
            $this->assertTrue(preg_match('/^\d{13}$/', (string) $request['reqTime']) === 1, 'Jeepay reqTime 必须是 13 位毫秒字符串');
        }
        $aliExtra = json_decode((string) $client->calls[3]['data']['channelExtra'], true);
        $wxExtra = json_decode((string) $client->calls[4]['data']['channelExtra'], true);
        $miniExtra = json_decode((string) $client->calls[5]['data']['channelExtra'], true);
        $this->assertSame(['buyerUserId' => '2088-JEEPAY'], $aliExtra, 'Jeepay ALI_JSAPI channelExtra 不得携带任意 payment 扩展');
        $this->assertSame(['openid' => 'WX-MP-OPENID'], $wxExtra, 'Jeepay WX_JSAPI 只能发送公众号身份');
        $this->assertSame(['openid' => 'WX-MINI-OPENID'], $miniExtra, 'Jeepay WX_LITE 只能发送小程序身份');
        $encodedResults = json_encode([$qr, $jump, $form, $ali, $wx, $mini, $bank], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertFalse(is_string($encodedResults) && str_contains($encodedResults, '"raw"'), 'Jeepay presentation 不得保存完整 data/payData');

        $schema = [];
        foreach ($aliPlugin->getConfigSchema() as $field) {
            $schema[(string) ($field['field'] ?? '')] = $field;
        }
        $products = array_map(
            static fn (array $option): string => (string) ($option['value'] ?? ''),
            (array) ($schema['enabled_products']['options'] ?? [])
        );
        $this->assertSame(
            ['alipay_jsapi', 'wxpay_mp', 'wxpay_mini', 'alipay_configured', 'wxpay_configured', 'bank_configured'],
            $products,
            'Jeepay 产品开关必须使用稳定业务产品，不能使用配置字段名或真实 wayCode'
        );

        $missingAli = $aliPlugin->identityRequirement($this->jeepayOrder('alipay', 'alipay', ['method' => 'jsapi', 'buyer_open_id' => 'WRONG-FIELD']));
        $this->assertSame('buyer_id', (string) ($missingAli['identity_field'] ?? ''), 'Jeepay ALI_JSAPI 不得用 buyer_open_id 替代 buyer_id');
        $missingMp = $wxPlugin->identityRequirement($this->jeepayOrder('wxpay', 'wechat', ['method' => 'jsapi', 'buyer_id' => 'NOT-WECHAT']));
        $this->assertSame('openid', (string) ($missingMp['identity_field'] ?? ''), 'Jeepay WX_JSAPI 只接受公众号 openid');
        $missingMini = $wxPlugin->identityRequirement($this->jeepayOrder('wxpay', 'wechat', ['method' => 'mini', 'openid' => 'MP-NOT-MINI']));
        $this->assertSame('mini_openid', (string) ($missingMini['identity_field'] ?? ''), 'Jeepay WX_LITE 不得用公众号 openid 替代 mini_openid');
        $hosted = $this->jeepayPlugin($client, ['alipay_way_code' => 'QR_CASHIER']);
        $this->assertSame(
            null,
            $hosted->identityRequirement($this->jeepayOrder('alipay', 'alipay', ['method' => 'qrcode'])),
            'Jeepay QR_CASHIER 是上游托管收银台，不得声明本地身份需求'
        );
        $this->assertThrows(
            fn () => $wxPlugin->pay($this->jeepayOrder('wxpay', 'wechat', ['method' => 'jsapi', 'openid' => 'A', 'sub_openid' => 'B'])),
            'Jeepay WX_JSAPI 两个公众号身份不一致时必须拒绝'
        );

        foreach (['codeImgUrl', 'ysfapp', 'none', 'unknownType'] as $unsupportedType) {
            $unsupportedClient = new JeepayUnitClient(static fn (string $path, array $payload): array => [
                'payOrderId' => 'JP-UNSUPPORTED',
                'mchOrderNo' => (string) $payload['mchOrderNo'],
                'orderState' => 1,
                'payDataType' => $unsupportedType,
                'payData' => 'https://jeepay.unit.test/unsupported',
            ]);
            $unsupportedPlugin = $this->jeepayPlugin($unsupportedClient, ['alipay_way_code' => 'ALI_QR']);
            $this->assertThrowsClass(
                fn () => $unsupportedPlugin->pay($this->jeepayOrder('alipay', 'pc', ['method' => 'qrcode'])),
                PaymentUncertainException::class,
                'Jeepay 不支持/未知 payDataType ' . $unsupportedType . ' 必须按已创建订单结果不确定拒绝'
            );
        }

        $evilClient = new JeepayUnitClient(static fn (string $path, array $payload): array => [
            'payOrderId' => 'JP-EVIL-FORM',
            'mchOrderNo' => (string) $payload['mchOrderNo'],
            'orderState' => 1,
            'payDataType' => 'form',
            'payData' => '<form method="post" action="https://evil.example/steal"><input type="hidden" name="x" value="1"></form>',
        ]);
        $evilPlugin = $this->jeepayPlugin($evilClient, ['alipay_way_code' => 'ALI_WAP', 'alipay_pay_data_type' => 'form']);
        $this->assertThrowsClass(
            fn () => $evilPlugin->pay($this->jeepayOrder('alipay', 'mobile', ['method' => 'h5'])),
            PaymentUncertainException::class,
            'Jeepay HTML form 不可信 action 必须拒绝'
        );

        $rejectingClient = new JeepayUnitClient(static fn (): JeepaySdkException => new JeepaySdkException('产品未开通', false, 'PRODUCT_NOT_OPEN'));
        $rejectingPlugin = $this->jeepayPlugin($rejectingClient);
        $this->assertThrowsClass(
            fn () => $rejectingPlugin->pay($this->jeepayOrder('alipay', 'alipay', ['method' => 'jsapi', 'buyer_id' => '2088-REJECT'])),
            PaymentDefinitiveException::class,
            'Jeepay 明确拒绝后必须终止，不能切换 wayCode'
        );
        $this->assertSame(1, count($rejectingClient->calls), 'Jeepay 下单失败后不得换 wayCode 再请求');
    }

    /**
     * Jeepay 支付通知验签、商户、订单、金额、状态和渠道编号强关联。
     */
    private function testJeepayNotifyBusinessValidation(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-JEEPAY-NOTIFY',
            'pay_amount' => 100,
            'channel_id' => 88,
            'channel_order_no' => 'JP-PAY-NOTIFY',
            'channel_trade_no' => 'ALI-TRADE-NOTIFY',
            'ext_json' => ['payment_context' => ['channel_context' => ['way_code' => 'ALI_QR']]],
        ]);
        $client = new JeepayUnitClient(static fn (): array => []);
        $plugin = $this->jeepayPlugin($client, [], $this->jeepayRepository($payOrder));
        $payload = [
            'payOrderId' => 'JP-PAY-NOTIFY',
            'mchNo' => 'M-JEEPAY-UNIT',
            'appId' => 'APP-JEEPAY-UNIT',
            'mchOrderNo' => 'P-JEEPAY-NOTIFY',
            'ifCode' => 'alipay',
            'wayCode' => 'ALI_QR',
            'amount' => '100',
            'currency' => 'cny',
            'state' => '2',
            'channelOrderNo' => 'ALI-TRADE-NOTIFY',
            'channelUser' => 'SENSITIVE-CHANNEL-USER',
            'createdAt' => '1784332800000',
            'successTime' => '1784332801000',
            'reqTime' => '1784332802000',
        ];
        $result = $plugin->notify($this->jeepaySignedFormRequest($payload, $client));
        PaymentPluginNotifyResultValidator::make($result)
            ->withScene('notify_result')
            ->withException(PaymentException::class)
            ->validate();
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $result['status'], 'Jeepay state=2 必须映射支付成功');
        $this->assertSame('P-JEEPAY-NOTIFY', (string) $result['pay_no'], 'Jeepay mchOrderNo 必须映射 pay_no');
        $this->assertSame('JP-PAY-NOTIFY', (string) $result['chan_order_no'], 'Jeepay payOrderId 必须映射 chan_order_no');
        $this->assertSame('ALI-TRADE-NOTIFY', (string) $result['chan_trade_no'], 'Jeepay channelOrderNo 必须映射 chan_trade_no');
        $this->assertFalse(array_key_exists('channelUser', $result), 'Jeepay 回调不得持久化 channelUser 身份');
        $this->assertFalse(array_key_exists('raw_data', $result), 'Jeepay 回调不得返回 raw_data');

        foreach ([
            ['mchNo', 'M-WRONG', '错商户'],
            ['appId', 'APP-WRONG', '错应用'],
            ['mchOrderNo', 'P-JEEPAY-WRONG', '错订单'],
            ['amount', '101', '错金额'],
            ['currency', 'usd', '错币种'],
            ['state', '1', '错状态'],
            ['payOrderId', 'JP-PAY-WRONG', '错 payOrderId'],
            ['channelOrderNo', 'ALI-TRADE-WRONG', '错 channelOrderNo'],
            ['wayCode', 'WX_NATIVE', '错 wayCode'],
            ['reqTime', '1784332802', '错通知时间'],
            ['successTime', 'not-millis', '错成功时间'],
        ] as [$field, $value, $scene]) {
            $mutated = array_replace($payload, [$field => $value]);
            $this->assertThrows(
                fn () => $plugin->notify($this->jeepaySignedFormRequest($mutated, $client)),
                'Jeepay支付回调' . $scene . '必须拒绝'
            );
        }
        $badSign = $payload;
        $badSign['sign'] = str_repeat('0', 32);
        $this->assertThrows(
            fn () => $plugin->notify($this->rawFormRequest($badSign)),
            'Jeepay支付回调错签必须拒绝'
        );

        $withoutTradeOrder = new PayOrder();
        $withoutTradeOrder->forceFill([
            'pay_no' => 'P-JEEPAY-NO-TRADE',
            'pay_amount' => 100,
            'channel_id' => 88,
            'channel_order_no' => 'JP-PAY-NO-TRADE',
            'channel_trade_no' => '',
            'ext_json' => ['payment_context' => ['channel_context' => ['way_code' => 'ALI_QR']]],
        ]);
        $withoutTradePlugin = $this->jeepayPlugin($client, [], $this->jeepayRepository($withoutTradeOrder));
        $withoutTradePayload = array_replace($payload, [
            'mchOrderNo' => 'P-JEEPAY-NO-TRADE',
            'payOrderId' => 'JP-PAY-NO-TRADE',
        ]);
        unset($withoutTradePayload['channelOrderNo']);
        $withoutTrade = $withoutTradePlugin->notify($this->jeepaySignedFormRequest($withoutTradePayload, $client));
        $this->assertSame('', (string) $withoutTrade['chan_trade_no'], 'Jeepay通知缺少可选 channelOrderNo 时不得用 payOrderId 冒充');
    }

    /**
     * Jeepay 退款状态、独立退款通知能力及不支持操作。
     */
    private function testJeepayRefundAndRefundNotify(): void
    {
        $payOrder = new PayOrder();
        $payOrder->forceFill([
            'pay_no' => 'P-JEEPAY-REFUND-PAY',
            'pay_amount' => 500,
            'channel_id' => 88,
            'channel_order_no' => 'JP-PAY-REFUND',
            'channel_trade_no' => 'ALI-PAY-TRADE',
        ]);
        $repository = $this->jeepayRepository($payOrder);
        $successClient = new JeepayUnitClient(static fn (string $path, array $payload): array => [
            'refundOrderId' => 'JP-REFUND-1',
            'mchRefundNo' => (string) $payload['mchRefundNo'],
            'payAmount' => 500,
            'refundAmount' => (int) $payload['refundAmount'],
            'state' => 2,
            'channelOrderNo' => 'ALI-CHANNEL-REFUND-1',
        ]);
        $plugin = $this->jeepayPlugin($successClient, [], $repository);
        $this->assertTrue($plugin instanceof RefundNotifyInterface, 'Jeepay 官方已证明独立退款通知，插件必须打开 RefundNotifyInterface 能力门');
        $refundOrder = $this->jeepayRefundOrder();
        $success = $plugin->refund($refundOrder);
        PaymentPluginRefundResultValidator::make($success)
            ->withScene('refund_result')
            ->withException(PaymentException::class)
            ->validate();
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $success['status'], 'Jeepay退款 state=2 必须映射 success');
        $this->assertSame('JP-REFUND-1', (string) $success['chan_refund_no'], 'Jeepay refundOrderId 必须映射 chan_refund_no');
        $refundRequest = $successClient->calls[0]['data'];
        $this->assertSame('/api/refund/refundOrder', (string) $successClient->calls[0]['path'], 'Jeepay退款路径错误');
        $this->assertSame('JP-PAY-REFUND', (string) $refundRequest['payOrderId'], 'Jeepay退款必须使用 chan_order_no/payOrderId');
        $this->assertFalse(isset($refundRequest['mchOrderNo']), 'Jeepay退款不得混发商户订单号候选');
        $this->assertSame('https://mpay.unit.test/refund/callback', (string) $refundRequest['notifyUrl'], 'Jeepay具备退款通知能力时必须发送公共退款回调地址');

        foreach ([1 => PaymentPluginStatusConstant::PENDING, 9 => PaymentPluginStatusConstant::UNKNOWN] as $state => $expected) {
            $stateClient = new JeepayUnitClient(static fn (string $path, array $payload): array => [
                'refundOrderId' => 'JP-REFUND-' . $state,
                'mchRefundNo' => (string) $payload['mchRefundNo'],
                'payAmount' => 500,
                'refundAmount' => (int) $payload['refundAmount'],
                'state' => $state,
            ]);
            $stateResult = $this->jeepayPlugin($stateClient, [], $repository)->refund($refundOrder);
            $this->assertSame($expected, (string) $stateResult['status'], 'Jeepay退款状态映射错误：' . $state);
        }
        $this->assertThrows(
            fn () => $plugin->refund(array_replace($refundOrder, ['chan_order_no' => '', 'chan_trade_no' => 'ALI-PAY-TRADE'])),
            'Jeepay退款不得用 chan_trade_no/channelOrderNo 冒充 payOrderId'
        );
        $this->assertThrowsClass(fn () => $plugin->query(['pay_no' => 'P']), UnsupportedPaymentOperationException::class, 'Jeepay支付 query 必须保持不支持');
        $this->assertThrowsClass(fn () => $plugin->close(['pay_no' => 'P']), UnsupportedPaymentOperationException::class, 'Jeepay支付 close 必须保持不支持');

        $notifyPayload = [
            'refundOrderId' => 'JP-REFUND-1',
            'payOrderId' => 'JP-PAY-REFUND',
            'mchNo' => 'M-JEEPAY-UNIT',
            'appId' => 'APP-JEEPAY-UNIT',
            'mchRefundNo' => 'R-JEEPAY-UNIT',
            'payAmount' => '500',
            'refundAmount' => '200',
            'currency' => 'cny',
            'state' => '2',
            'channelOrderNo' => 'ALI-CHANNEL-REFUND-1',
            'createdAt' => '1784332800000',
            'successTime' => '1784332801000',
            'reqTime' => '1784332802000',
        ];
        $refundContext = [
            'refund_no' => 'R-JEEPAY-UNIT',
            'pay_no' => 'P-JEEPAY-REFUND-PAY',
            'refund_amount' => 200,
            'chan_refund_no' => 'JP-REFUND-1',
            'chan_trade_no' => 'ALI-PAY-TRADE',
            'amount' => 500,
        ];
        $notified = $plugin->refundNotify($this->jeepaySignedFormRequest($notifyPayload, $successClient), $refundContext);
        PaymentPluginRefundResultValidator::make($notified)
            ->withScene('refund_status_result')
            ->withException(PaymentException::class)
            ->validate();
        $this->assertSame(PaymentPluginStatusConstant::SUCCESS, (string) $notified['status'], 'Jeepay退款通知 state=2 必须映射 success');
        $this->assertSame('JP-REFUND-1', (string) $notified['chan_refund_no'], 'Jeepay退款通知必须精确返回 refundOrderId');
        $this->assertSame('success', (string) $plugin->refundNotifySuccess(), 'Jeepay退款通知成功 ACK 必须是 success');
        $this->assertSame('fail', (string) $plugin->refundNotifyFail(), 'Jeepay退款通知失败 ACK 必须是 fail');

        foreach (['1' => PaymentPluginStatusConstant::PENDING, '3' => PaymentPluginStatusConstant::FAILED, '9' => PaymentPluginStatusConstant::UNKNOWN] as $state => $expected) {
            $statePayload = array_replace($notifyPayload, ['state' => $state]);
            $stateResult = $plugin->refundNotify($this->jeepaySignedFormRequest($statePayload, $successClient), $refundContext);
            $this->assertSame($expected, (string) $stateResult['status'], 'Jeepay退款通知状态映射错误：' . $state);
        }
        foreach ([
            ['mchNo', 'M-WRONG', '错商户'],
            ['appId', 'APP-WRONG', '错应用'],
            ['mchRefundNo', 'R-WRONG', '错退款单'],
            ['refundAmount', '201', '错退款金额'],
            ['payAmount', '501', '错原支付金额'],
            ['payOrderId', 'JP-PAY-WRONG', '错 payOrderId'],
            ['refundOrderId', 'JP-REFUND-WRONG', '错 refundOrderId'],
            ['currency', 'usd', '错币种'],
            ['reqTime', '1784332802', '错通知时间'],
            ['state', 'SUCCESS', '错状态格式'],
        ] as [$field, $value, $scene]) {
            $mutated = array_replace($notifyPayload, [$field => $value]);
            $this->assertThrows(
                fn () => $plugin->refundNotify($this->jeepaySignedFormRequest($mutated, $successClient), $refundContext),
                'Jeepay退款回调' . $scene . '必须拒绝'
            );
        }
        $badSign = $notifyPayload;
        $badSign['sign'] = str_repeat('0', 32);
        $this->assertThrows(
            fn () => $plugin->refundNotify($this->rawFormRequest($badSign), $refundContext),
            'Jeepay退款回调错签必须拒绝'
        );
    }

    /**
     * 创建已注入测试客户端的 Jeepay 插件。
     *
     * @param array<string, mixed> $overrides
     */
    private function jeepayPlugin(
        JeepayUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): JeepayApiPayment {
        $plugin = new JeepayApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->jeepayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构建 Jeepay 测试配置。
     *
     * @return array<string, mixed>
     */
    private function jeepayConfig(array $overrides = []): array
    {
        return array_replace([
            'channel_id' => 88,
            'api_url' => 'https://jeepay.unit.test',
            'mch_no' => 'M-JEEPAY-UNIT',
            'app_id' => 'APP-JEEPAY-UNIT',
            'api_key' => 'jeepay-unit-secret',
            'alipay_way_code' => 'ALI_QR',
            'alipay_pay_data_type' => 'payUrl',
            'wxpay_way_code' => 'WX_NATIVE',
            'bank_way_code' => 'QR_CASHIER',
            'trusted_html_hosts' => 'openapi.alipay.com',
            'alipay_oauth_app_id' => 'ali-oauth-jeepay',
            'alipay_oauth_private_key' => 'unused-private-key',
            'alipay_oauth_public_key' => 'unused-public-key',
            'wx_mp_app_id' => 'wx-jeepay-app',
            'wx_mp_app_secret' => 'wx-mp-secret',
            'wx_mini_app_id' => 'wx-jeepay-mini',
            'wx_mini_app_secret' => 'wx-mini-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'enabled_products' => [
                'alipay_jsapi',
                'wxpay_mp',
                'wxpay_mini',
                'alipay_configured',
                'wxpay_configured',
                'bank_configured',
            ],
        ], $overrides);
    }

    /**
     * 构建 Jeepay 测试订单。
     *
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function jeepayOrder(string $payType, string $env, array $payment = [], array $overrides = []): array
    {
        return array_replace([
            'pay_no' => 'P-JEEPAY-UNIT',
            'amount' => 100,
            'client_ip' => '127.0.0.1',
            'subject' => 'Jeepay单元测试商品',
            'callback_url' => 'https://mpay.unit.test/pay/callback',
            'return_url' => 'https://mpay.unit.test/pay/return',
            'pay_type_code' => $payType,
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ], $overrides);
    }

    /**
     * 构建 Jeepay 退款订单。
     *
     * @return array<string, mixed>
     */
    private function jeepayRefundOrder(): array
    {
        return [
            'pay_no' => 'P-JEEPAY-REFUND-PAY',
            'refund_no' => 'R-JEEPAY-UNIT',
            'amount' => 500,
            'refund_amount' => 200,
            'refund_reason' => 'Jeepay单元测试退款',
            'chan_order_no' => 'JP-PAY-REFUND',
            'chan_trade_no' => 'ALI-PAY-TRADE',
            'refund_callback_url' => 'https://mpay.unit.test/refund/callback',
        ];
    }

    private function jeepayRepository(PayOrder $payOrder): PayOrderRepository
    {
        return new class($payOrder) extends PayOrderRepository {
            public function __construct(private readonly PayOrder $unitOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return hash_equals((string) $this->unitOrder->pay_no, $payNo) ? $this->unitOrder : null;
            }
        };
    }

    /**
     * 构建已签名的 Jeepay 表单请求。
     *
     * @param array<string, mixed> $payload
     */
    private function jeepaySignedFormRequest(array $payload, JeepayClient $client): Request
    {
        unset($payload['sign']);
        $payload['sign'] = $client->sign($payload);

        return $this->rawFormRequest($payload);
    }

    /**
     * 创建已注入测试客户端的易宝插件。
     */
    private function yeepayPlugin(YeepayUnitClient $client, array $overrides = []): YeepayApiPayment
    {
        $plugin = new YeepayApiPayment();
        $plugin->init($this->yeepayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造易宝插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function yeepayConfig(array $overrides = []): array
    {
        $keys = $this->yeepayFixedKeyPair();

        return array_replace([
            'app_key' => 'YOP-UNIT-APP',
            'merchant_private_key' => $keys['private_key'],
            'platform_public_key' => $keys['public_key'],
            'parent_merchant_no' => 'PARENT-UNIT',
            'merchant_no' => 'MCH-UNIT',
            'alipay_scene' => 'OFFLINE',
            'wechat_scene' => 'ONLINE',
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'alipay_app',
                'wxpay_scan',
                'wxpay_mp',
                'wxpay_mini',
                'wxpay_h5',
                'wxpay_app',
                'bank_scan',
            ],
            'wechat_mp_app_id' => 'wx-mp-unit',
            'wechat_mp_app_secret' => 'mp-secret-unit',
            'bindwxa' => true,
            'wechat_mini_app_id' => 'wx-mini-unit',
            'wechat_mini_app_secret' => 'mini-secret-unit',
            'wechat_mini_launch_path' => 'pages/payment/index',
            'wechat_jsapi_default_product' => 'mp',
            'alipay_oauth_app_id' => '2026000000000001',
            'alipay_oauth_private_key' => 'alipay-unit-private-key',
            'alipay_oauth_public_key' => 'alipay-unit-public-key',
            'api_base_url' => '',
        ], $overrides);
    }

    /**
     * 构造易宝标准下单参数。
     *
     * @return array<string, mixed>
     */
    private function yeepayOrder(
        string $payType,
        string $environment,
        array $payment = [],
        int $amount = 123
    ): array {
        $method = (string) ($payment['method'] ?? 'default');

        return [
            'pay_no' => sprintf('PAY-YEEPAY-%s-%s-%s', strtoupper($payType), strtoupper($environment), strtoupper($method)),
            'pay_type' => $payType,
            'pay_type_code' => $payType,
            'amount' => $amount,
            'pay_amount' => $amount,
            'environment' => $environment,
            '_env' => $environment,
            'subject' => '易宝单元测试订单',
            'client_ip' => '203.0.113.10',
            'callback_url' => 'https://merchant.unit.test/payment/notify/yeepay',
            'notify_url' => 'https://merchant.unit.test/payment/notify/yeepay',
            'return_url' => 'https://merchant.unit.test/payment/return',
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 获取易宝签名固定密钥对。
     *
     * @return array{private_key:string,public_key:string}
     */
    private function yeepayFixedKeyPair(): array
    {
        return [
            'private_key' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCvVgp2I6PHxHYg
abxcuxQMyblt1sav4UJIg9xjJCF9atwIWFIQmGjV8oau7MrXwfUHLTox8DhK0P5V
zcXayF5cVwm7rDA4ntmc2FYEMB6n7pWbkkosGvGGptAUFzoCMQHafeciwWqbjhsJ
woPUaGfbsSPPsqZu9i1keOGZxrWQmRx3APRS1EGms2F1MDpIRBTn1h0xM5HHDfNO
7FEncpEnJyWMS7bsRCvzlwWKmHJTj33VYnrzwTrdAJT2jqcz6ZpS5Zv8M8IDWIsd
moNo1tMT2/iGRGUrIIHtmX2NMSE8HcTKceGj2G8MxVclBRo+kxyM9s7xdQnS3tEK
CTD9J5g3AgMBAAECggEADssE7vAVgrvsoNIgS6KXunclPJiBAu3PwyvSOFB1aDjP
1P8T+Bp5Hd6XG8MWtMVvKuqB8nIAuISRhiO/rF8jYZ8Q+dM5Q6MDW9xUxYhZzcla
DgTLBUBGCG+ii/oPBt2jo7o4+hG025L4bQZgS6zTYRG897/BkwhqJAP0Oh6DIG6R
OUvaPa0/W+UnMsZ7WIxOSfdwcbznlo9z1r0/dxPbq6UNvE5ZtQXjktVV4uIQ7ors
QcdPnfhGXHnLdLVIroUmsaJSs5VaRwl+ZyprfZ+7gjbZF6l9UkzXUlSYeot4AH/w
vkb/zyEHPwktYtQqd/tkntaU1Ukk18N3T/koFbiMoQKBgQDxTMsrSyxdW28lSs09
j32RuxrjJIQmPgGshRH19Wiu6RDHS22OiE7eIzfV4zZMlxGtjuc+vO3eHDKh5f0g
JS6k+VkymQfTKGtZn0ePCv0KuPiHyJpGrozOi2/Zlud63FEbR/Ip4BzDUUnGBqHg
oxMzKCvZ2jzIH/xSV/c4GDjdywKBgQC6BIEOp2DqGwfXi/fI+VzSBiGHCSpzdjPb
1HtvTgjE0da4kK63c3iz0o1T8RfFCWpcroTM+gZ156BVSXNWm4Q1TEp3jx8xIvpG
g2jeopf8fVlRU6z5lLy9HRbOSbBwLO97mjc12FAGWd2jc+Ab+DIaLoGS8ahDI2gf
1yYn3LFhxQKBgDcQ+1yJ7znu39J225es408aj+w+LRo9FEy2oX6r3pPsBDQ29m2M
ldMD3n4lOAMKhrJA5mze2LnTXYqs3bM0SQzFCqINYkfB9Z2iR8ZRD6YeyDjUgsCW
nPOVxpS1Z2YWWTwMkysTRf0c0+UpJlAJZxxJkphIwY46Hm78PCLFBFU5AoGBAJbG
ydkX4J1BNbUIFdtIDG2MXKa4zjjyiYxZCYgppz/pmnLVi1jVdvPC6Z0toYerXxQq
vSfsTUpKahJXS+7adWpCIWYRk0XfxR9cqqczAaC99aTO/zj5z5Y2OuMQpbv3IFJ+
qNuzLwJG2zj+1pu1LN897Pcve6SX0XFlkd7jqr/FAoGAVfB4t7rVN8TVYKARtEdJ
+XS/UlaywKaBt7w/WPW9Z+BY/OZ/4gvwRMNgVZi8UBN5Rw6KGiUqajJurFiCJxCf
39esgLtHQV+LKAuBv/D5DNQ9RUsmQcrEJoaq7MLgY61V/lT2SFGIPFUd5Yd/vKWy
P7p46NjXnrUtSD4GNy8gFGA=
-----END PRIVATE KEY-----
PEM,
            'public_key' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr1YKdiOjx8R2IGm8XLsU
DMm5bdbGr+FCSIPcYyQhfWrcCFhSEJho1fKGruzK18H1By06MfA4StD+Vc3F2she
XFcJu6wwOJ7ZnNhWBDAep+6Vm5JKLBrxhqbQFBc6AjEB2n3nIsFqm44bCcKD1Ghn
27Ejz7KmbvYtZHjhmca1kJkcdwD0UtRBprNhdTA6SEQU59YdMTORxw3zTuxRJ3KR
JycljEu27EQr85cFiphyU4991WJ688E63QCU9o6nM+maUuWb/DPCA1iLHZqDaNbT
E9v4hkRlKyCB7Zl9jTEhPB3EynHho9hvDMVXJQUaPpMcjPbO8XUJ0t7RCgkw/SeY
NwIDAQAB
-----END PUBLIC KEY-----
PEM,
        ];
    }

    /**
     * 构造易宝通知签名测试向量。
     *
     * @return array{valid:string,bad_sign:string}
     */
    private function yeepayNotifyVectors(): array
    {
        return [
            'valid' => <<<'VECTOR'
p1xJMG2tq4EknE80jAiPjncq4FkwmLau5hhhWyiVtetBsonwtJ5gYFJ7LjlBPGyMmFFaUnOdXtpzXZr1jb3jtbda3N0zgL_jHaoypdnI4D-E65Sn1D-h9h-zckvlZGTeYrwYS1oLM6B2-pYE7BEpP_bRBI14e66C98iacVbrX1dHaY2U4More3ZUQMfXMQQKSpJyRUytxWbNO7vE5DH9eIRU7-_iHyla1vs765jsfkrRGt9uPndXIcjqoSRhqb6sKFkirO4XtYDV7vKjOt8YaWDK39AjJaPOEcoPsTRImRpeu2JvBXRDQOie6OOWNq8JQ3RDVTa-pJpi3sDm0oZ-rw$ZVet5ZTHFNQ93HguQTw68gmcD4PVr2_F-AL6hLpRF1waz1KvKQfmp5B4-BsCDeiGr848uf6nX5Wocl4Q6CZyqRw1oyUGrCk1Woyeg5NEg-fHNwEcQOlWf5Jv7n0GC3T7u-hsYx3Zck4dLaiRCOvg6M3GTKqjVEloqpI5-PY-bhldrW1sf3h5jXrSlTMyNa_YidZQzqI38bGZJ5h54hsdouS2FzFuGuIVyqMf3GftKMGuZeGWxst3-mbWqacD1Ut7oYzNM6y-UDyKq-TfKe9bvT3TPgynQ5-jsFXYbkux9vVTVj-il_bY4CUP9YmXNV0_282sVeIwlAqWFnfSVxgQxIkfggL700CumHGfsUB7QLgeR9vyiF7FUjimWg8DOvBB8b8JMj28AZV0OUjphAFVD6YARkpXZrr8lrptSkejOr2P52L5sZzUug6b3c8czP3aPsxz4yMPb69iUWJsQSJXJQpDmMp60KfRfKi0D9Nrh2obI0yjmnuCkU00zJXf0-4LRB0tErOhohqi12av006f6Wa5wYl8zT_Kexki5pOFzCkHx4hqZPvH6waOGBAg2K-9NpIAQdLUXwP_3vVvKgqR4x0ihYltRM6o2TsAOj7BOUpGGFrvx_FAjU-1q_ZRx1Gvl2-m-8x4BKEx9zUS4I1gk0ab42f33s_0wtzeQtXF7a2KWahdNcWvSXrwWw2Tth1D-5jQlNJMVqDgSGQHeEEvdmXkwrJfFR79cX35h9x8PyI$AES$SHA256
VECTOR,
            'bad_sign' => <<<'VECTOR'
ajAXqImVChSZRc9crdwZzJXG3g6An6AJgtQBIRjy6ksM-q_QHjN3XpCFxdJsGSMUYMS8HQCiFGvmENZcpcKMqHw4z3Brht30ihS6wiCo3bNvd5WkilCvigJOBU1RyXZ4sM5DVm0WTvQsI0h4sQbB0aheSbtYGbPSMyu5SW9myqE11_d8g7W5hQlUa1EsOX9vTekxwHS8XlWcgEbt_o5Jdbwgu0kxTqkGWbHIUg0iC4NA2nVlJNAZNZCqKSK05zCbQ8U17dkd4ySL0iZLHscrX7Sdco2CDAwEBnhvmK5BI6Xk3pB4Mb5ks-JDC7Kw9tfe2tL70-Q_QvGLEHrpXQZwCw$ZVet5ZTHFNQ93HguQTw68gmcD4PVr2_F-AL6hLpRF1waz1KvKQfmp5B4-BsCDeiGr848uf6nX5Wocl4Q6CZyqRw1oyUGrCk1Woyeg5NEg-fHNwEcQOlWf5Jv7n0GC3T7u-hsYx3Zck4dLaiRCOvg6M3GTKqjVEloqpI5-PY-bhldrW1sf3h5jXrSlTMyNa_YidZQzqI38bGZJ5h54hsdouS2FzFuGuIVyqMf3GftKMGuZeGWxst3-mbWqacD1Ut7oYzNM6y-UDyKq-TfKe9bvbH3x2VHXqssjuG54F22vrNTVj-il_bY4CUP9YmXNV0_282sVeIwlAqWFnfSVxgQxIkfggL700CumHGfsUB7QLgeR9vyiF7FUjimWg8DOvBB8b8JMj28AZV0OUjphAFVD6YARkpXZrr8lrptSkejOr2P52L5sZzUug6b3c8czP3aPsxz4yMPb69iUWJsQSJXJQpDmMp60KfRfKi0D9Nrh2obI0yjmnuCkU00zJXf0-4LRB0tErOhohqi12av006f6Wa5wYl8zT_Kexki5pOFzCkHx4hqZPvH6waOGBAg2K-9NpIAQdLUXwP_3vVvKgqR4x0ihYltRM6o2TsAOj7BOUpGGFrvx_FAjU-1q_ZRx1Gvl2-m-8x4BKEx9zUS4I1gk0ab42f33s_0wtzeQtXF7a2KWahdNcWvSXrwWw2Tth1D-5jQlNJMVqDgSGQHeEEvdmXkwrJfFR79cX35h9x8PyI$AES$SHA256
VECTOR,
        ];
    }

    /**
     * 创建不访问网络的银联商务测试客户端。
     */
    private function chinaumsClient(callable $responder, array $overrides = []): ChinaumsUnitClient
    {
        return new ChinaumsUnitClient($responder, array_replace([
            'app_id' => 'APP-TEST-001',
            'app_key' => 'chinaums-unit-app-key',
            'communication_key' => 'chinaums-unit-communication-key',
            'sandbox' => true,
        ], $overrides));
    }

    /**
     * 创建已注入测试客户端的银联商务插件。
     */
    private function chinaumsPlugin(
        ChinaumsUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): ChinaumsApiPayment {
        $plugin = new ChinaumsApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->chinaumsConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造银联商务插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function chinaumsConfig(array $overrides = []): array
    {
        return array_replace([
            'app_id' => 'APP-TEST-001',
            'app_key' => 'chinaums-unit-app-key',
            'merchant_no' => '898UNITMERCHANT',
            'terminal_no' => 'UNITTERM01',
            'communication_key' => 'chinaums-unit-communication-key',
            'msg_source_id' => '1017',
            'channel_id' => 12,
            'enabled_products' => [
                'alipay_scan',
                'alipay_h5',
                'wxpay_scan',
                'wxpay_h5',
                'wxpay_mini_h5',
                'bank_scan',
            ],
            'h5_app_name' => 'MPAY 单元测试商户',
            'h5_app_url' => 'https://merchant.unit.test',
            'sandbox' => true,
        ], $overrides);
    }

    /**
     * 构造银联商务标准下单参数。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function chinaumsOrder(string $payType, string $env, array $payment = []): array
    {
        $suffix = strtoupper(substr(hash('sha256', $payType . '|' . $env . '|' . json_encode($payment)), 0, 12));

        return [
            'pay_no' => 'P' . $suffix,
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '银联商务单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.unit.test/api/pay/chinaums/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造银联商务签名通知。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function chinaumsSignedNotify(ChinaumsClient $client, array $payload): array
    {
        $payload['sign'] = $client->notifySignature($payload);

        return $payload;
    }

    /**
     * 创建已注入测试客户端的富友插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function fuiouPlugin(
        FuiouUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): FuiouApiPayment {
        $plugin = new FuiouApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->fuiouConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造富友插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fuiouConfig(array $overrides = []): array
    {
        return array_replace([
            'institution_code' => 'INS-TEST',
            'merchant_no' => 'MCH-TEST',
            'merchant_private_key' => 'unit-private-key',
            'platform_public_key' => 'unit-public-key',
            'order_prefix' => 'FU',
            'terminal_serial_no' => 'FUIOU-SN-001',
            'operator_id' => 'OP-001',
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'wxpay_scan',
                'wxpay_mp',
                'wxpay_mini',
                'bank_scan',
                'barcode',
            ],
            'bindwxa' => true,
            'wechat_mp_app_id' => 'wx-fuiou-mp',
            'wechat_mp_app_secret' => 'wx-fuiou-mp-secret',
            'wechat_mini_app_id' => 'wx-fuiou-mini',
            'wechat_mini_app_secret' => 'wx-fuiou-mini-secret',
            'wechat_mini_launch_path' => 'pages/pay/index',
            'alipay_oauth_app_id' => '2026000000000001',
            'alipay_oauth_private_key' => 'alipay-oauth-private',
            'alipay_oauth_public_key' => 'alipay-oauth-public',
            'expire_minutes' => 5,
            'channel_id' => 12,
            'sandbox' => true,
        ], $overrides);
    }

    /**
     * 构造富友标准下单参数。
     *
     * @param array<string, mixed> $payment payment 扩展
     * @return array<string, mixed>
     */
    private function fuiouOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-FUIOU-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '富友单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.unit.test/api/pay/fuiou/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造富友通知请求。
     *
     * @param array<string, mixed> $payload
     */
    private function fuiouNotifyRequest(FuiouPayClient $client, array $payload): Request
    {
        return $this->rawFormRequest(['req' => urlencode($client->encodeXml($payload))]);
    }

    /**
     * 获取富友签名固定密钥对。
     *
     * @return array{private_key:string,public_key:string}
     */
    private function fuiouFixedKeyPair(): array
    {
        return [
            'private_key' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIICdwIBADANBgkqhkiG9w0BAQEFAASCAmEwggJdAgEAAoGBAOFuwgYRxmkcYdv3
Arm6kTCIZwEDD+FMB0gmHYTvXos5pM9gWqg+6GZa/2BsRrUJ3NG0hp8tdUK88zyF
63RBIe+5bLPZG+4gA7qOhUeHIncztrKT2gxiGa2NI9Po0d+Ea8iyI599Q4QLFwCx
LmNdjFLHysSpFCjU6Y6PBOm7IL0DAgMBAAECgYB4+TfTi/xecaWuJdrnkk/RrJEi
AOOnsmYB+LpEmTOyIOfphTqBKOkL7G847kHvavB99JN9niZb/wvEgdU9mKo9efve
nDeGFI9MpXx4wTrSwQ1LynvTxEJzUyGtlWpCLOY7mEzwJddtSM0/0Iqot4azQDdE
4x/IM4M/kJN73m0zWQJBAPIIKYDf6wjx86OuBJHyFA6fS6dUf2uMIFD4eKIZgNSF
ggeac+DDFytJ64p5j0NEzbWHzH9FTfwIPsBvBWQjA+UCQQDucVrKF/4jwvFE2Lcz
U6l/qHgY1SOPgCdhj/EWZwVt3hN9bFHi+zsPuRqrmR9LeAAgMoZqqQe01J+nMp+E
Jn7HAkEA8Fcol6BDrhtNnHE2epMQVcDbiGtBKNP6V02VxSpcIy38hH5cqYoxXLxH
2LeDiwIs4CHc8Vkp6qdpYQAeM2UN/QJBANQrsanap52SvbWRUZMugsjBU/xky/vJ
AUHjH5fbnA0jaxxT4pmjC+71uzGuUxaIdTQxQUJvnhfeiyHv/dlNl8kCQCv239Pf
/aXcWOTYwuJSPYghRwr+VMnk7C+tp94C6bAkvL9wri+lcmU1zaa8z2xndQc/YLae
e9lfMKSZwj+/u9c=
-----END PRIVATE KEY-----
PEM,
            'public_key' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDhbsIGEcZpHGHb9wK5upEwiGcB
Aw/hTAdIJh2E716LOaTPYFqoPuhmWv9gbEa1CdzRtIafLXVCvPM8het0QSHvuWyz
2RvuIAO6joVHhyJ3M7ayk9oMYhmtjSPT6NHfhGvIsiOffUOECxcAsS5jXYxSx8rE
qRQo1OmOjwTpuyC9AwIDAQAB
-----END PUBLIC KEY-----
PEM,
        ];
    }

    /**
     * 创建已注入测试客户端的付呗插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function fubeiPlugin(
        FubeiUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): FubeiApiPayment {
        $plugin = new FubeiApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->fubeiConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造付呗插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fubeiConfig(array $overrides = []): array
    {
        return array_replace([
            'vendor_sn' => 'VENDOR-TEST',
            'app_id' => '',
            'app_secret' => 'fubei-unit-secret',
            'fubei_merchant_id' => 'FUBEI-MERCHANT-001',
            'store_id' => 'FUBEI-STORE-001',
            'channel_id' => 12,
            'enabled_products' => ['wxpay_jsapi'],
            'wechat_app_id' => 'wx-fubei-mp-app',
            'wechat_app_secret' => 'wx-fubei-mp-secret',
            'api_gateway' => 'https://fubei.unit.test/gateway',
        ], $overrides);
    }

    /**
     * 构造付呗标准下单参数。
     *
     * @param array<string, mixed> $payment payment 扩展
     * @return array<string, mixed>
     */
    private function fubeiOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-FUBEI-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '付呗单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.unit.test/api/pay/P-FUBEI/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造付呗已签名回调表单。
     *
     * @param array<string, mixed> $data 回调 data
     * @return array<string, string>
     */
    private function fubeiSignedNotify(FubeiClient $client, array $data): array
    {
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($dataJson)) {
            throw new \RuntimeException('付呗测试回调 JSON 编码失败');
        }
        $payload = [
            'result_code' => '200',
            'result_message' => 'success',
            'data' => $dataJson,
        ];
        $payload['sign'] = $client->sign($payload);

        return $payload;
    }

    /**
     * 创建已注入测试客户端的汇付插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function huifuPlugin(HuifuUnitClient $client, array $overrides = []): HuifuApiPayment
    {
        $plugin = new HuifuApiPayment();
        $plugin->init($this->huifuConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造汇付插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function huifuConfig(array $overrides = []): array
    {
        return array_replace([
            'sys_id' => 'SYS-HUIFU',
            'product_id' => 'P-HUIFU',
            'sub_merchant_no' => 'M-HUIFU',
            'merchant_private_key' => 'unit-private-key',
            'huifu_public_key' => 'unit-public-key',
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'alipay_hosted',
                'wxpay_scan',
                'wxpay_jsapi',
                'wxpay_mini',
                'wxpay_hosted',
                'bank_scan',
                'quickpay_page',
                'bank_web',
                'ecny_scan',
                'barcode',
            ],
            'wx_mp_app_id' => 'wx-huifu-mp',
            'wx_mp_app_secret' => 'wx-huifu-mp-secret',
            'wx_mini_app_id' => 'wx-huifu-mini',
            'wx_mini_app_secret' => 'wx-huifu-mini-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'wx_goods_product_id' => '01001',
            'alipay_app_id' => '2026000000000001',
            'alipay_app_private_key' => 'alipay-private-key',
            'alipay_public_key' => 'alipay-public-key',
            'hosted_project_id' => 'PROJECT-HUIFU',
            'hosted_project_title' => 'MPAY 汇付收银台',
            'quickpay_biz_type' => '100099',
            'bank_biz_type' => '100099',
            'bank_gate_type' => '01',
            'bank_card_type' => 'D',
        ], $overrides);
    }

    /**
     * 构造汇付标准下单参数。
     *
     * @param array<string, mixed> $payment payment 扩展
     * @return array<string, mixed>
     */
    private function huifuOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => '20260716HUIFU' . strtoupper($payType) . strtoupper($env) . substr(md5(json_encode($payment) ?: ''), 0, 6),
            'pay_type_code' => $payType,
            'amount' => 123,
            'pay_created_at' => '2026-07-16 12:00:00',
            'subject' => '汇付单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.unit.test/api/pay/huifu/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造汇付通知请求。
     *
     * @param array<string, mixed> $payload
     */
    private function huifuNotifyRequest(array $payload): Request
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('汇付测试回调 JSON 编码失败');
        }

        return $this->rawFormRequest([
            'resp_code' => '00000000',
            'resp_desc' => 'success',
            'resp_data' => $json,
            'sign' => 'valid-sign',
        ]);
    }

    /**
     * 构造汇付状态操作参数。
     *
     * @return array<string, mixed>
     */
    private function huifuStateOrder(string $product): array
    {
        return [
            'pay_no' => '20260716HUIFUSTATE',
            'amount' => 123,
            'pay_amount' => 123,
            'chan_order_no' => '20260716HUIFUSTATE',
            'chan_trade_no' => 'HF-ORIGINAL',
            'pay_product' => $product,
            'pay_created_at' => '2026-07-16 12:00:00',
        ];
    }

    /**
     * 创建已注入测试客户端的掌易收插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function zhangyishouPlugin(
        ZhangyishouUnitClient $client,
        array $overrides = []
    ): ZhangyishouApiPayment {
        $plugin = new ZhangyishouApiPayment();
        $plugin->init($this->zhangyishouConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造掌易收插件配置。
     *
     * @param array<string, mixed> $overrides 配置覆盖
     * @return array<string, mixed>
     */
    private function zhangyishouConfig(array $overrides = []): array
    {
        return array_replace([
            'merchant_id' => 'LOGIN-UNIT',
            'merchant_no' => 'MNO-UNIT',
            'api_key' => 'secret-key',
            'pay_channel_id' => 'DEFAULT-UNIT',
            'wxpay_mobile_channel_id' => 'WX-MOBILE-UNIT',
            'enabled_products' => ['default_channel', 'wxpay_mobile'],
        ], $overrides);
    }

    /**
     * 构造掌易收标准下单参数。
     *
     * @return array<string, mixed>
     */
    private function zhangyishouOrder(string $payType, string $env): array
    {
        $payNo = 'P-ZYS-' . strtoupper($payType) . '-' . strtoupper($env);

        return [
            'pay_no' => $payNo,
            'pay_type_code' => $payType,
            'amount' => 123,
            'subject' => '掌易收单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.test/api/pay/' . $payNo . '/callback',
            'return_url' => 'https://mpay.test/payment/' . $payNo,
            '_env' => $env,
            'extra' => ['payment' => []],
        ];
    }

    /**
     * 计算掌易收通知测试签名。
     *
     * @param array<string, mixed> $payload 通知报文
     */
    private function zhangyishouNotifySignature(array $payload): string
    {
        return md5(
            (string) ($payload['MerchantId'] ?? '')
            . (string) ($payload['DownstreamOrderNo'] ?? '')
            . 'secret-key'
        );
    }

    /**
     * 创建已注入测试客户端的 XorPay 插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function xorpayPlugin(XorpayUnitClient $client, array $overrides = []): XorpayApiPayment
    {
        $plugin = new XorpayApiPayment();
        $plugin->init($this->xorpayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造 XorPay 插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function xorpayConfig(array $overrides = []): array
    {
        return array_replace([
            'app_id' => 'XORPAY-UNIT-AID',
            'app_secret' => 'xorpay-unit-secret',
            'enabled_products' => ['wechat_cashier', 'alipay', 'wx_native'],
        ], $overrides);
    }

    /**
     * 构造 XorPay 标准下单参数。
     *
     * @param array<string, mixed> $payment payment 扩展
     * @return array<string, mixed>
     */
    private function xorpayOrder(string $payType, string $env, array $payment = []): array
    {
        $suffix = substr(md5(json_encode($payment) ?: ''), 0, 6);
        $payNo = 'P-XORPAY-' . strtoupper($payType) . '-' . strtoupper($env) . '-' . $suffix;

        return [
            'pay_no' => $payNo,
            'pay_type_code' => $payType,
            'amount' => 123,
            'subject' => 'XorPay 单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.unit.test/api/pay/' . $payNo . '/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 创建已注入测试客户端的虎皮椒插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function xunhupayPlugin(
        XunhupayUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): XunhupayApiPayment {
        $plugin = new XunhupayApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->xunhupayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造虎皮椒插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function xunhupayConfig(array $overrides = []): array
    {
        return array_replace([
            'appid' => 'XUNHU-APP-001',
            'api_key' => 'xunhu-unit-secret',
            'api_url' => 'https://api.xunhupay.com/payment/do.html',
            'wap_name' => 'MPAY Unit Shop',
            'channel_id' => 81,
            'enabled_products' => ['alipay_h5', 'wechat_h5', 'alipay', 'wechat'],
        ], $overrides);
    }

    /**
     * 构造虎皮椒标准下单参数。
     *
     * @return array<string, mixed>
     */
    private function xunhupayOrder(string $payType, string $env): array
    {
        $payNo = 'P-XH-' . strtoupper($payType) . '-' . strtoupper($env);

        return [
            'pay_no' => $payNo,
            'pay_type_code' => $payType,
            'amount' => 123,
            'subject' => '虎皮椒单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.unit.test/api/pay/' . $payNo . '/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => []],
        ];
    }

    /**
     * 构造 XorPay 已签名成功通知。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    private function xorpaySignedNotify(array $overrides = [], string $secret = 'xorpay-unit-secret'): array
    {
        $payload = array_replace([
            'aoid' => 'XOR-AOID-NOTIFY-A',
            'order_id' => 'P-XORPAY-NOTIFY-A',
            'pay_price' => '1.23',
            'pay_time' => '2026-07-17 12:34:56',
            'detail' => json_encode([
                'transaction_id' => 'WX-TRANSACTION-NOTIFY-A',
                'bank_type' => 'CFT',
                'buyer' => 'xorpay-unit-buyer',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], $overrides);
        $payload['sign'] = md5(
            (string) $payload['aoid']
            . (string) $payload['order_id']
            . (string) $payload['pay_price']
            . (string) $payload['pay_time']
            . $secret
        );

        return array_map(static fn ($value): string => (string) $value, $payload);
    }

    /**
     * 通过核心回调服务验证 URL 支付单与 XorPay 通知的二次关联。
     *
     * @param array<string, string> $payload
     */
    private function xorpayCallbackAck(XorpayApiPayment $plugin, PayOrder $payOrder, array $payload): string
    {
        $repository = new class($payOrder) extends PayOrderRepository {
            public function __construct(private PayOrder $payOrder) {}

            public function findByPayNo(string $payNo, array $columns = ['*'])
            {
                return $payNo === (string) $this->payOrder->pay_no ? $this->payOrder : null;
            }
        };
        $manager = new class($plugin) extends PaymentPluginManager {
            public function __construct(private XorpayApiPayment $plugin) {}

            public function createByPayOrder(PayOrder $payOrder, bool $allowDisabled = true): \app\common\interface\PaymentInterface & \app\common\interface\PayPluginInterface
            {
                return $this->plugin;
            }
        };
        $notifyService = new class extends NotifyService {
            public function __construct() {}

            public function recordPayCallback(array $input): ?\app\model\admin\PayCallbackLog
            {
                return null;
            }
        };
        $service = new PayOrderCallbackService(
            $notifyService,
            $manager,
            new \app\repository\payment\config\PaymentChannelRepository(),
            $repository,
            (new ReflectionClass(\app\service\payment\order\PayOrderLifecycleService::class))->newInstanceWithoutConstructor()
        );

        return (string) $service->handlePluginCallback((string) $payOrder->pay_no, $this->rawFormRequest($payload));
    }

    /**
     * 构造表单请求。
     *
     * @param array<string, mixed> $payload
     */
    private function rawFormRequest(array $payload): Request
    {
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $raw = "POST /notify HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body;

        return new Request($raw);
    }

    /**
     * 创建已注入测试客户端的 AdaPay 插件。
     *
     * @param array<string, mixed> $overrides
     */
    private function adapayPlugin(
        AdapayUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): AdapayApiPayment {
        $plugin = new AdapayApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->adapayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构建 AdaPay 测试配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function adapayConfig(array $overrides = []): array
    {
        return array_replace([
            'app_id' => 'APP_ADAPAY_UNIT',
            'api_key' => 'adapay-unit-api-key',
            'merchant_private_key' => 'unit-merchant-private-key',
            'platform_public_key' => 'unit-platform-public-key',
            'channel_id' => 72,
            'enabled_products' => ['alipay_pub', 'wx_pub', 'alipay_qr', 'wx_lite', 'union_qr'],
            'wechat_mp_app_id' => 'wx-mp-adapay',
            'wechat_mp_app_secret' => 'wx-mp-adapay-secret',
            'wechat_mini_app_id' => 'wx-mini-adapay',
            'wechat_mini_app_secret' => 'wx-mini-adapay-secret',
            'wechat_mini_launch_path' => 'pages/pay/index',
            'alipay_oauth_app_id' => '2026000000000072',
            'alipay_oauth_private_key' => 'alipay-adapay-private-key',
            'alipay_oauth_public_key' => 'alipay-adapay-public-key',
        ], $overrides);
    }

    /**
     * 构造 AdaPay 测试订单。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function adapayOrder(string $payType, string $env, array $payment = [], string $payNo = ''): array
    {
        $payNo = $payNo !== '' ? $payNo : 'PAY_ADAPAY_' . strtoupper($payType) . '_' . strtoupper($env);

        return [
            'pay_no' => $payNo,
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => 'AdaPay单元测试订单',
            'client_ip' => '203.0.113.10',
            'callback_url' => 'https://mpay.unit.test/api/pay/' . $payNo . '/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造指定状态的 AdaPay 测试订单。
     *
     * @return array<string, mixed>
     */
    private function adapayStateOrder(): array
    {
        return [
            'pay_no' => 'PAY_ADAPAY_STATE',
            'pay_type_code' => 'alipay',
            'pay_product' => 'alipay_qr',
            'pay_action' => 'payments.create',
            'amount' => 100,
            'chan_order_no' => 'PAYMENT_ADAPAY_STATE',
            'chan_trade_no' => 'OUT_ADAPAY_STATE',
            'channel_context' => [
                'payment_id' => 'PAYMENT_ADAPAY_STATE',
                'pay_channel' => 'alipay_qr',
            ],
        ];
    }

    /**
     * 构造 AdaPay 通知请求。
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $event
     */
    private function adapayNotifyRequest(array $data, array $event = []): Request
    {
        $dataText = json_encode($data);
        if (!is_string($dataText)) {
            throw new \RuntimeException('AdaPay测试通知JSON编码失败');
        }

        return $this->rawFormRequest(array_replace([
            'created_time' => '1784262645',
            'data' => $dataText,
            'prod_mode' => 'true',
            'sign' => 'valid-sign',
            'id' => 'EVENT_ADAPAY_NOTIFY',
            'type' => 'payment.succeeded',
            'app_id' => 'APP_ADAPAY_UNIT',
            'object' => 'payment',
        ], $event));
    }

    /**
     * 获取 AdaPay 固定测试密钥对。
     *
     * @return array{private_key:string,public_key:string}
     */
    private function adapayFixedKeyPair(): array
    {
        return $this->fuiouFixedKeyPair();
    }

    /**
     * 构造 AdaPay 模拟 HTTP 响应。
     *
     * @param array<string, mixed> $data
     */
    private function adapayHttpResponse(array $data, string $privateKey, bool $valid = true): \GuzzleHttp\Psr7\Response
    {
        $dataText = json_encode($data);
        if (!is_string($dataText)) {
            throw new \RuntimeException('AdaPay测试响应JSON编码失败');
        }
        $signature = $valid
            ? $this->adapayTestSign($dataText, $privateKey)
            : base64_encode('invalid-adapay-signature');
        $body = json_encode(['data' => $dataText, 'signature' => $signature]);
        if (!is_string($body)) {
            throw new \RuntimeException('AdaPay测试响应封装失败');
        }

        return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $body);
    }

    private function adapayTestSign(string $message, string $privateKey): string
    {
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('生成AdaPay测试签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 创建已注入测试客户端的通联插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function allinpayPlugin(AllinpayUnitClient $client, array $overrides = []): AllinpayApiPayment
    {
        $plugin = new AllinpayApiPayment();
        $plugin->init($this->allinpayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造通联插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function allinpayConfig(array $overrides = []): array
    {
        return array_replace([
            'merchant_no' => 'CUS-ALLINPAY',
            'app_id' => 'APP-ALLINPAY',
            'platform_public_key' => 'unit-public-key',
            'merchant_private_key' => 'unit-private-key',
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'wxpay_scan',
                'wxpay_jsapi',
                'qqpay_scan',
                'bank_scan',
                'bank_jsapi',
                'cashier',
            ],
            'wx_mp_app_id' => 'wx-mp-app',
            'wx_mp_app_secret' => 'wx-mp-secret',
            'wx_mini_app_id' => 'wx-mini-app',
            'wx_mini_app_secret' => 'wx-mini-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'alipay_oauth_app_id' => '2026000000000001',
            'alipay_oauth_private_key' => 'alipay-private-key',
            'alipay_oauth_public_key' => 'alipay-public-key',
            'unionpay_identify' => 'MPAY-UNIT-UA',
            'sandbox' => true,
        ], $overrides);
    }

    /**
     * 构造通联标准下单参数。
     *
     * @param array<string, mixed> $payment payment 扩展
     * @return array<string, mixed>
     */
    private function allinpayOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-ALLINPAY-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '通联单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/allinpay/notify',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    private function allinpayTestSign(string $message, string $privateKey): string
    {
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('生成通联测试签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 创建已注入测试客户端的拉卡拉插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function lakalaPlugin(LakalaUnitClient $client, array $overrides = []): LakalaApiPayment
    {
        $plugin = new LakalaApiPayment();
        $plugin->init($this->lakalaConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造拉卡拉插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function lakalaConfig(array $overrides = []): array
    {
        return array_replace([
            'app_id' => 'LKL-APP',
            'merchant_no' => 'M-LAKALA',
            'terminal_no' => 'T-LAKALA',
            'terminal_ip' => '127.0.0.1',
            'payment_api_profile' => 'official_labs_v1',
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'wxpay_jsapi',
                'wxpay_mini',
                'bank_scan',
                'bank_jsapi',
                'micropay',
            ],
            'wx_mp_app_id' => 'wx-mp-app',
            'wx_mp_app_secret' => 'wx-mp-secret',
            'wx_mini_app_id' => 'wx-mini-app',
            'wx_mini_app_secret' => 'wx-mini-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'wx_default_jsapi_product' => 'mp',
            'alipay_app_id' => '2026000000000001',
            'alipay_private_key' => 'alipay-private-key',
            'alipay_public_key' => 'alipay-public-key',
            'org_code' => 'ORG-LAKALA',
            'onboarding_verify_enabled' => true,
        ], $overrides);
    }

    /**
     * 构造拉卡拉标准下单参数。
     *
     * @param array<string, mixed> $payment payment 扩展
     * @return array<string, mixed>
     */
    private function lakalaOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-LAKALA-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '拉卡拉单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/lakala/notify',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造带鉴权头的 JSON 请求。
     *
     * @param array<string, mixed> $payload
     */
    private function rawJsonRequestWithAuthorization(array $payload, string $authorization): Request
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \RuntimeException('测试 JSON 编码失败');
        }
        $raw = "POST /notify HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . 'Authorization: ' . $authorization . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body;

        return new Request($raw);
    }

    /**
     * 创建拉卡拉商户证书和平台证书测试夹具。
     *
     * @return array<string, string>
     */
    private function lakalaCertificateFixture(): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mpay-lakala-cert-' . bin2hex(random_bytes(5));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('创建拉卡拉证书测试目录失败');
        }

        try {
            $merchantPair = RsaKeyPairGenerator::generate(2048);
            $platformPair = RsaKeyPairGenerator::generate(2048);
            $opensslConfig = base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
            $options = ['digest_alg' => 'sha256', 'config' => $opensslConfig];
            $merchantKey = openssl_pkey_get_private($merchantPair['private_key']);
            $platformKey = openssl_pkey_get_private($platformPair['private_key']);
            $merchantCsr = openssl_csr_new(['commonName' => 'MPAY Lakala Merchant'], $merchantKey, $options);
            $platformCsr = openssl_csr_new(['commonName' => 'MPAY Lakala Platform'], $platformKey, $options);
            if ($merchantCsr === false || $platformCsr === false) {
                throw new \RuntimeException('生成拉卡拉测试证书请求失败');
            }
            $merchantCert = openssl_csr_sign($merchantCsr, null, $merchantKey, 3650, $options, 2001);
            $platformCert = openssl_csr_sign($platformCsr, null, $platformKey, 3650, $options, 2002);
            if ($merchantCert === false || $platformCert === false) {
                throw new \RuntimeException('签发拉卡拉测试证书失败');
            }
            $merchantPem = $platformPem = '';
            if (!openssl_x509_export($merchantCert, $merchantPem) || !openssl_x509_export($platformCert, $platformPem)) {
                throw new \RuntimeException('导出拉卡拉测试证书失败');
            }

            $merchantCertPath = $directory . DIRECTORY_SEPARATOR . 'merchant.cer';
            $merchantKeyPath = $directory . DIRECTORY_SEPARATOR . 'merchant.key';
            $platformCertPath = $directory . DIRECTORY_SEPARATOR . 'platform.cer';
            file_put_contents($merchantCertPath, $merchantPem);
            file_put_contents($merchantKeyPath, $merchantPair['private_key']);
            file_put_contents($platformCertPath, $platformPem);

            return [
                'directory' => $directory,
                'merchant_cert_path' => $merchantCertPath,
                'merchant_private_key_path' => $merchantKeyPath,
                'platform_cert_path' => $platformCertPath,
                'platform_private_key' => $platformPair['private_key'],
            ];
        } catch (Throwable $e) {
            $this->cleanupTestDirectory($directory);
            throw $e;
        }
    }

    private function rsaTestSign(string $message, string $privateKey): string
    {
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('生成拉卡拉测试签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 创建已注入测试客户端的杉德插件。
     *
     * @param array<string, mixed> $overrides
     */
    private function sandpayPlugin(
        SandpayUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): SandpayApiPayment {
        $plugin = new SandpayApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->sandpayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造杉德插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sandpayConfig(array $overrides = []): array
    {
        return array_replace([
            'merchant_no' => 'SAND-MERCHANT-001',
            'merchant_cert_no' => 'CERT-001',
            'private_cert_password' => 'unit-pass',
            'public_cert_path' => 'storage/private/certificate/sandpay-unit/platform.cer',
            'private_cert_path' => 'storage/private/certificate/sandpay-unit/merchant.pfx',
            'market_product' => 'QZF',
            'channel_id' => 66,
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'wxpay_scan',
                'wxpay_mp',
                'wxpay_mini',
                'bank_scan',
            ],
            'wechat_mp_app_id' => 'wx-sandpay-mp',
            'wechat_mp_app_secret' => 'wx-sandpay-mp-secret',
            'wechat_mini_app_id' => 'wx-sandpay-mini',
            'wechat_mini_app_secret' => 'wx-sandpay-mini-secret',
            'wechat_mini_launch_path' => 'pages/pay/index',
            'wechat_mini_env_version' => 'release',
            'alipay_oauth_app_id' => 'ali-sandpay-app',
            'alipay_oauth_private_key' => 'alipay-unit-private-key',
            'alipay_oauth_public_key' => 'alipay-unit-public-key',
            'sandbox' => true,
        ], $overrides);
    }

    /**
     * 构造杉德标准下单参数。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function sandpayOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-SANDPAY-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '杉德单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://mpay.unit.test/api/pay/P-SANDPAY/callback',
            'return_url' => 'https://merchant.unit.test/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造杉德通知请求。
     *
     * @param array<string, mixed> $payload
     */
    private function sandpayNotifyRequest(array $payload): Request
    {
        $bizData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($bizData)) {
            throw new \RuntimeException('杉德测试通知 JSON 编码失败');
        }

        return $this->rawFormRequest(['bizData' => $bizData, 'sign' => 'valid-sign']);
    }

    /**
     * 创建 2048 位平台 X.509 与商户 PFX 私有文件夹具。
     *
     * @return array<string, mixed>
     */
    private function sandpayCertificateFixture(): array
    {
        $name = 'sandpay-unit-' . bin2hex(random_bytes(5));
        $directory = runtime_path('storage/private/certificate/' . $name);
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('创建杉德私有证书测试目录失败');
        }

        try {
            $opensslConfig = base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
            $options = [
                'digest_alg' => 'sha256',
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => $opensslConfig,
            ];
            $platformPrivateKey = openssl_pkey_new($options);
            $merchantPrivateKey = openssl_pkey_new($options);
            if ($platformPrivateKey === false || $merchantPrivateKey === false) {
                throw new \RuntimeException('生成杉德测试 RSA 密钥失败');
            }
            $platformCsr = openssl_csr_new(['commonName' => 'MPAY Sandpay Platform Unit'], $platformPrivateKey, $options);
            $merchantCsr = openssl_csr_new(['commonName' => 'MPAY Sandpay Merchant Unit'], $merchantPrivateKey, $options);
            if ($platformCsr === false || $merchantCsr === false) {
                throw new \RuntimeException('生成杉德测试证书请求失败');
            }
            $platformCertificate = openssl_csr_sign($platformCsr, null, $platformPrivateKey, 30, $options, random_int(1000, 999999));
            $merchantCertificate = openssl_csr_sign($merchantCsr, null, $merchantPrivateKey, 30, $options, random_int(1000, 999999));
            if ($platformCertificate === false || $merchantCertificate === false) {
                throw new \RuntimeException('签发杉德测试证书失败');
            }
            $platformPem = '';
            $merchantPfx = '';
            if (!openssl_x509_export($platformCertificate, $platformPem)
                || !openssl_pkcs12_export($merchantCertificate, $merchantPfx, $merchantPrivateKey, 'unit-pass')) {
                throw new \RuntimeException('导出杉德测试证书失败');
            }
            $merchantPublicKey = openssl_pkey_get_public($merchantCertificate);
            if ($merchantPublicKey === false) {
                throw new \RuntimeException('读取杉德商户测试证书公钥失败');
            }

            $platformCertPath = $directory . DIRECTORY_SEPARATOR . 'platform.cer';
            $merchantPfxPath = $directory . DIRECTORY_SEPARATOR . 'merchant.pfx';
            $malformedCertPath = $directory . DIRECTORY_SEPARATOR . 'malformed.cer';
            if (file_put_contents($platformCertPath, $platformPem) === false
                || file_put_contents($merchantPfxPath, $merchantPfx) === false
                || file_put_contents($malformedCertPath, 'not-a-certificate') === false) {
                throw new \RuntimeException('写入杉德测试证书失败');
            }

            return [
                'directory' => $directory,
                'platform_cert_path' => $platformCertPath,
                'merchant_pfx_path' => $merchantPfxPath,
                'malformed_cert_path' => $malformedCertPath,
                'merchant_pfx_object_key' => 'storage/private/certificate/' . $name . '/merchant.pfx',
                'platform_private_key' => $platformPrivateKey,
                'merchant_public_key' => $merchantPublicKey,
            ];
        } catch (Throwable $e) {
            $this->cleanupTestDirectory($directory);
            throw $e;
        }
    }

    /**
     * 清理杉德证书测试夹具。
     *
     * @param array<string, mixed> $fixture
     */
    private function cleanupSandpayCertificateFixture(array $fixture): void
    {
        $this->cleanupTestDirectory((string) ($fixture['directory'] ?? ''));
    }

    /**
     * 创建已注入测试客户端的天阙插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function tianquePlugin(
        TianqueTechUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): TianqueTechApiPayment {
        $plugin = new TianqueTechApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->tianqueConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造天阙插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function tianqueConfig(array $overrides = []): array
    {
        return array_replace([
            'api_profile' => 'current',
            'org_id' => 'ORG-TEST',
            'merchant_no' => 'MNO-TEST',
            'platform_public_key' => 'unit-public-key',
            'merchant_private_key' => 'unit-private-key',
            'channel_id' => 12,
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'wxpay_scan',
                'wxpay_mp',
                'wxpay_mini',
                'wxpay_applet_plugin',
                'wxpay_applet_cashier',
                'bank_scan',
            ],
            'wechat_mp_app_id' => 'wx-mp-app',
            'wechat_mp_app_secret' => 'wx-mp-secret',
            'wechat_mini_app_id' => 'wx-mini-app',
            'wechat_mini_app_secret' => 'wx-mini-secret',
            'wechat_mini_env_version' => 'release',
            'tianque_applet_plugin_app_id' => 'wx-tianque-plugin',
            'alipay_app_id' => '2026000000000001',
            'alipay_app_private_key' => 'alipay-private-key',
            'alipay_public_key' => 'alipay-public-key',
        ], $overrides);
    }

    /**
     * 构造天阙标准下单参数。
     *
     * @param array<string, mixed> $payment payment 扩展
     * @return array<string, mixed>
     */
    private function tianqueOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-TIANQUE-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '天阙单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/notify',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 创建已注入独立测试客户端的随行付插件。
     *
     * @param array<string, mixed> $overrides 通道配置覆盖
     */
    private function suixingpayPlugin(
        SuixingpayUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): SuixingpayApiPayment {
        $plugin = new SuixingpayApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->suixingpayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造随行付插件配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function suixingpayConfig(array $overrides = []): array
    {
        return array_replace([
            'suixingpay_api_profile' => 'current',
            'suixingpay_org_id' => 'SXP-ORG-TEST',
            'suixingpay_merchant_no' => 'SXP-MNO-TEST',
            'suixingpay_platform_public_key' => 'suixingpay-unit-public-key',
            'suixingpay_merchant_private_key' => 'suixingpay-unit-private-key',
            'channel_id' => 29,
            'enabled_products' => [
                'alipay_scan',
                'alipay_jsapi',
                'wxpay_scan',
                'wxpay_mp',
                'wxpay_mini',
                'wxpay_applet_plugin',
                'wxpay_applet_cashier',
                'bank_scan',
            ],
            'suixingpay_wechat_mp_app_id' => 'wx-sxp-mp-app',
            'suixingpay_wechat_mp_app_secret' => 'wx-sxp-mp-secret',
            'suixingpay_wechat_mini_app_id' => 'wx-sxp-mini-app',
            'suixingpay_wechat_mini_app_secret' => 'wx-sxp-mini-secret',
            'suixingpay_wechat_mini_env_version' => 'release',
            'suixingpay_applet_plugin_app_id' => 'wx-sxp-plugin',
            'suixingpay_alipay_app_id' => '2026000000000029',
            'suixingpay_alipay_app_private_key' => 'sxp-alipay-private-key',
            'suixingpay_alipay_public_key' => 'sxp-alipay-public-key',
        ], $overrides);
    }

    /**
     * 构造随行付标准下单参数。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function suixingpayOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-SXP-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '随行付单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/notify/suixingpay',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造哆啦宝测试插件。
     *
     * @param array<string, mixed> $overrides
     */
    private function duolabaoPlugin(
        DuolabaoClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): DuolabaoApiPayment {
        $plugin = new DuolabaoApiPayment($repository);
        $plugin->init(array_replace([
            'channel_id' => 77,
            'contract_profile' => 'rainbow_legacy',
            'agent_num' => 'DLB-AGENT',
            'customer_num' => 'DLB-CUSTOMER',
            'shop_num' => 'DLB-SHOP',
            'access_key' => 'duolabao-unit-access',
            'secret_key' => 'duolabao-unit-secret',
            'enabled_products' => ['ALIPAY_JSAPI', 'WX_JSAPI', 'QRCODE_TRAD'],
            'wx_platform_app_id' => 'wx-platform',
            'wx_platform_app_secret' => 'wx-platform-secret',
            'wx_mp_app_id' => 'wx-mp-child',
            'wx_mp_app_secret' => 'wx-mp-secret',
            'wx_mini_app_id' => 'wx-mini-child',
            'wx_mini_app_secret' => 'wx-mini-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'alipay_oauth_app_id' => '2026000000000030',
            'alipay_oauth_private_key' => 'alipay-private-key',
            'alipay_oauth_public_key' => 'alipay-public-key',
        ], $overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构造哆啦宝标准下单参数。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function duolabaoOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-DLB-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 123,
            'subject' => '哆啦宝单元测试订单',
            'client_ip' => '127.0.0.1',
            'callback_url' => 'https://merchant.unit.test/api/pay/P-DLB/callback',
            'return_url' => 'https://merchant.unit.test/pay/return',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构造带原始 JSON 和哆啦宝签名头的通知请求。
     *
     * @param array<string, mixed> $payload
     */
    private function duolabaoNotifyRequest(
        array $payload,
        DuolabaoClient $client,
        string $timestamp = '1700000100',
        ?string $token = null
    ): Request {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \RuntimeException('哆啦宝测试通知 JSON 编码失败');
        }
        $token ??= $client->createToken($timestamp, '', $body);
        $raw = "POST /api/pay/P-DLB-NOTIFY/callback HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . 'timestamp: ' . $timestamp . "\r\n"
            . 'token: ' . $token . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body;

        return new Request($raw);
    }

    /**
     * 构造 JSON 请求。
     *
     * @param array<string, mixed> $payload
     */
    private function rawJsonRequest(array $payload): Request
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \RuntimeException('测试 JSON 编码失败');
        }
        $raw = "POST /notify HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body;

        return new Request($raw);
    }

    /**
     * 创建已注入测试客户端的易生插件。
     *
     * @param array<string, mixed> $overrides
     */
    private function easypayPlugin(
        EasypayUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): EasypayApiPayment {
        $plugin = new EasypayApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->easypayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构建易生支付测试配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function easypayConfig(array $overrides = []): array
    {
        return array_replace([
            'channel_id' => 88,
            'req_type' => '2',
            'req_id' => 'INST-EASYPAY',
            'sub_merchant_no' => 'MCH-EASYPAY',
            'certificate_id' => 'CERT-EASYPAY',
            'platform_public_key_path' => 'storage/private/certificate/easypay-platform.cer',
            'merchant_private_key_path' => 'storage/private/certificate/easypay-merchant.key',
            'wx_mp_app_id' => 'wx-easypay-mp',
            'wx_mp_app_secret' => 'wx-easypay-secret',
            'alipay_oauth_app_id' => '',
            'alipay_oauth_private_key_path' => '',
            'alipay_oauth_public_key_path' => '',
            'unionpay_app_up_identifier' => 'UNION-APP-UP-UNIT',
            'enabled_products' => [
                'AliPayJsapi',
                'WeChatJsapi',
                'UnionPayJsapi',
                'AliPayNative',
                'WeChatNative',
                'UnionPayNative',
            ],
            'sandbox' => true,
        ], $overrides);
    }

    /**
     * 构建易生支付测试订单。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function easypayOrder(string $payType, string $env, array $payment = []): array
    {
        return [
            'pay_no' => 'P-EASYPAY-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '易生单元测试订单',
            'client_ip' => '203.0.113.10',
            'callback_url' => 'https://merchant.unit.test/payment/notify/easypay',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构建易生银联支付参数。
     *
     * @return array<string, mixed>
     */
    private function easypayUnionPayment(): array
    {
        return [
            'method' => 'jump',
            'unionpay_auth_code' => 'UNION-AUTH-UNIT',
            'unionpay_user_id' => 'UNION-USER-UNIT',
            'unionpay_qr_code' => 'https://unionpay.unit.test/qr',
            'unionpay_qr_code_type' => '1',
            'unionpay_payment_valid_time' => 300,
            'unionpay_trans_type' => '01',
            'unionpay_area_info' => '1100000',
        ];
    }

    /**
     * 构建易生支付交易响应。
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function easypayTradeResponse(array $request): array
    {
        $product = (string) ($request['payInfo']['payType'] ?? '');
        $orderInfo = [
            'orgTrace' => (string) ($request['reqOrderInfo']['orgTrace'] ?? ''),
            'outTrace' => 'OUT-' . (string) ($request['reqOrderInfo']['orgTrace'] ?? ''),
            'transAmount' => (int) ($request['reqOrderInfo']['transAmount'] ?? 0),
        ];
        $response = [
            'respStateInfo' => ['respCode' => '000000', 'transState' => '9', 'transStatusDesc' => '已受理'],
            'respOrderInfo' => $orderInfo,
        ];
        if (str_ends_with($product, 'Native')) {
            $response['respOrderInfo']['qrCode'] = 'https://cashier.unit.test/' . rawurlencode($product);
        } elseif ($product === 'AliPayJsapi') {
            $response['aliRespParamInfo'] = ['tradeNo' => 'ALI-TRADE-EASYPAY'];
        } elseif ($product === 'WeChatJsapi') {
            $response['wxRespParamInfo'] = ['wcPayData' => json_encode([
                'appId' => 'wx-easypay-mp',
                'timeStamp' => '1784262645',
                'nonceStr' => 'easypay-unit',
                'package' => 'prepay_id=WX-EASYPAY',
                'signType' => 'RSA',
                'paySign' => 'unit-sign',
            ], JSON_UNESCAPED_SLASHES)];
        } elseif ($product === 'UnionPayJsapi') {
            $response['qrRespParamInfo'] = ['qrRedirectUrl' => 'https://unionpay.unit.test/cashier'];
        }

        return $response;
    }

    /**
     * 构建易生支付操作订单。
     *
     * @return array<string, mixed>
     */
    private function easypayOperationOrder(): array
    {
        return [
            'pay_no' => 'P-EASYPAY-STATE',
            'amount' => 100,
            'pay_amount' => 100,
            'chan_order_no' => 'P-EASYPAY-STATE',
            'chan_trade_no' => 'OUT-EASYPAY-STATE',
            'pay_product' => 'AliPayNative',
            'channel_context' => [
                'trans_date' => '20260717',
                'req_id' => 'INST-EASYPAY',
                'req_type' => '2',
                'mcht_code' => 'MCH-EASYPAY',
                'pay_product' => 'AliPayNative',
                'out_trace' => 'OUT-EASYPAY-STATE',
            ],
        ];
    }

    /**
     * 构建易生支付通知载荷。
     *
     * @return array<string, mixed>
     */
    private function easypayNotifyPayload(): array
    {
        return [
            'reqHeader' => [
                'reqId' => 'INST-EASYPAY',
                'reqType' => '2',
                'transTime' => '20260717123456',
            ],
            'reqBody' => [
                'respStateInfo' => [
                    'respCode' => '000000',
                    'transState' => '0',
                    'transStatusDesc' => '支付成功',
                ],
                'respOrderInfo' => [
                    'mchtCode' => 'MCH-EASYPAY',
                    'orgTrace' => 'P-EASYPAY-NOTIFY',
                    'outTrace' => 'OUT-EASYPAY-NOTIFY',
                    'transAmount' => 100,
                ],
            ],
            'reqSign' => 'valid-sign',
        ];
    }

    /**
     * 创建已注入测试客户端的海科插件。
     *
     * @param array<string, mixed> $overrides
     */
    private function haipayPlugin(
        HaipayUnitClient $client,
        array $overrides = [],
        ?PayOrderRepository $repository = null
    ): HaipayApiPayment {
        $plugin = new HaipayApiPayment($repository ?? new PayOrderRepository());
        $plugin->init($this->haipayConfig($overrides));
        $this->setObjectProperty($plugin, 'client', $client);

        return $plugin;
    }

    /**
     * 构建海科支付测试配置。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function haipayConfig(array $overrides = []): array
    {
        return array_replace([
            'channel_id' => 77,
            'access_id' => 'HAIPAY-UNIT-ACCESS',
            'access_key' => 'haipay-unit-secret',
            'agent_no' => 'AGENT-HAIPAY',
            'merchant_no' => 'MCH-HAIPAY',
            'pn' => 'PN-HAIPAY',
            'wx_mp_app_id' => 'wx-haipay-mp',
            'wx_mp_app_secret' => 'wx-haipay-mp-secret',
            'wx_mini_app_id' => 'wx-haipay-mini',
            'wx_mini_app_secret' => 'wx-haipay-mini-secret',
            'wx_mini_launch_path' => 'pages/pay/index',
            'alipay_oauth_app_id' => 'ali-haipay-app',
            'alipay_oauth_private_key' => 'ali-haipay-private',
            'alipay_oauth_public_key' => 'ali-haipay-public',
            'enabled_products' => ['ALI_JSAPI', 'WX_JSAPI', 'ALI', 'WX', 'UNIONQR', 'passive_pay'],
            'sandbox' => true,
            'sandbox_gateway' => 'http://39.106.187.68:8080',
        ], $overrides);
    }

    /**
     * 构建海科支付测试订单。
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function haipayOrder(
        string $payType,
        string $env,
        array $payment = [],
        string $payNo = ''
    ): array {
        return [
            'pay_no' => $payNo !== '' ? $payNo : 'P-HAIPAY-' . strtoupper($payType) . '-' . strtoupper($env),
            'pay_type_code' => $payType,
            'amount' => 100,
            'subject' => '海科融通单元测试订单',
            'client_ip' => '203.0.113.10',
            'callback_url' => 'https://merchant.unit.test/payment/notify/haipay',
            '_env' => $env,
            'extra' => ['payment' => $payment],
        ];
    }

    /**
     * 构建海科支付响应。
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function haipayPaymentResponse(array $request, array $extra = []): array
    {
        $payNo = (string) ($request['out_trade_no'] ?? 'P-HAIPAY-UNIT');

        return array_replace([
            'agent_no' => (string) ($request['agent_no'] ?? 'AGENT-HAIPAY'),
            'merch_no' => (string) ($request['merch_no'] ?? 'MCH-HAIPAY'),
            'pay_type' => (string) ($request['pay_type'] ?? 'ALI'),
            'pay_mode' => (string) ($request['pay_mode'] ?? 'BARPAY'),
            'out_trade_no' => $payNo,
            'trade_no' => 'H-' . $payNo,
        ], $extra);
    }

    /**
     * 构建指定状态的海科支付订单。
     *
     * @return array<string, mixed>
     */
    private function haipayStateOrder(): array
    {
        return [
            'pay_no' => 'P-HAIPAY-STATE',
            'pay_type_code' => 'alipay',
            'amount' => 100,
            'chan_order_no' => 'P-HAIPAY-STATE',
            'chan_trade_no' => 'H-HAIPAY-STATE',
            'pay_product' => 'passive_pay',
            'pay_action' => 'passive-pay',
            'channel_context' => [],
        ];
    }

    /**
     * 构建海科支付通知载荷。
     *
     * @return array<string, mixed>
     */
    private function haipayNotifyPayload(): array
    {
        return [
            'agent_no' => 'AGENT-HAIPAY',
            'merch_no' => 'MCH-HAIPAY',
            'total_amount' => '1.00',
            'order_amount' => '1.00',
            'pay_type' => 'ALI',
            'pay_mode' => 'NATIVE',
            'out_trade_no' => 'P-HAIPAY-NOTIFY',
            'trade_no' => 'H-HAIPAY-NOTIFY',
            'trade_status' => '1',
            'pn' => 'PN-HAIPAY',
            'bank_trade_no' => 'BANK-HAIPAY-NOTIFY',
            'sub_mch_id' => 'SUB-MCH-HAIPAY',
            'clear_status' => '1',
            'end_time' => '2026-07-17 12:34:56',
            'sign' => 'FAKE-UNIT-SIGN',
        ];
    }

    /**
     * 构造天阙签名原文。
     *
     * @param array<string, mixed> $payload
     */
    private function tianqueSignContent(array $payload): string
    {
        unset($payload['sign']);
        ksort($payload);

        return implode('&', array_map(
            static fn (string $key, mixed $value): string => $key . '=' . (is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value),
            array_keys($payload),
            $payload
        ));
    }

    /**
     * 构造随行付签名原文。
     *
     * @param array<string, mixed> $payload
     */
    private function suixingpaySignContent(array $payload): string
    {
        unset($payload['sign']);
        $payload = array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '');
        ksort($payload);

        return implode('&', array_map(
            static fn (string $key, mixed $value): string => $key . '=' . (is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value),
            array_keys($payload),
            $payload
        ));
    }

    /**
     * 构造通道模型。
     *
     * @param array<string, mixed> $attributes 属性
     * @return PaymentChannel
     */
    private function channel(array $attributes): PaymentChannel
    {
        $channel = new PaymentChannel();
        $channel->forceFill($attributes + [
            'id' => 1,
            'status' => CommonConstant::STATUS_ENABLED,
            'pay_type_id' => 1,
            'plugin_code' => 'mock',
            'daily_limit_amount' => 0,
            'daily_limit_count' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
        ]);
        $channel->id = (int) ($attributes['id'] ?? 1);

        return $channel;
    }

    /**
     * 构造路由候选。
     *
     * @param int $channelId 通道ID
     * @param int $isDefault 是否默认
     * @param int $sortNo 排序号
     * @param int $weight 权重
     * @return array<string, mixed>
     */
    private function candidate(int $channelId, int $isDefault, int $sortNo, int $weight): array
    {
        return [
            'channel' => $this->channel(['id' => $channelId]),
            'is_default' => $isDefault,
            'sort_no' => $sortNo,
            'weight' => $weight,
        ];
    }

    /**
     * 获取可调用私有方法。
     *
     * @param string $class 类名
     * @param string $method 方法名
     * @return \ReflectionMethod
     */
    private function privateMethod(string $class, string $method): \ReflectionMethod
    {
        $reflection = new ReflectionClass($class);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection;
    }

    /**
     * 设置对象属性。
     *
     * @param object $object 对象
     * @param string $property 属性名
     * @param mixed $value 属性值
     * @return void
     */
    private function setObjectProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        while (!$reflection->hasProperty($property) && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        $propertyReflection = $reflection->getProperty($property);
        $propertyReflection->setAccessible(true);
        $propertyReflection->setValue($object, $value);
    }

    /**
     * 断言相等。
     *
     * @param mixed $expected 期望值
     * @param mixed $actual 实际值
     * @param string $message 错误消息
     * @return void
     */
    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . sprintf('，期望 %s，实际 %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    /**
     * 断言为真。
     *
     * @param bool $actual 实际值
     * @param string $message 错误消息
     * @return void
     */
    private function assertTrue(bool $actual, string $message): void
    {
        if (!$actual) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * 断言为假。
     *
     * @param bool $actual 实际值
     * @param string $message 错误消息
     * @return void
     */
    private function assertFalse(bool $actual, string $message): void
    {
        if ($actual) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * 断言包含。
     *
     * @param mixed $needle 期望元素
     * @param array $haystack 实际集合
     * @param string $message 错误消息
     * @return void
     */
    private function assertContains(mixed $needle, array $haystack, string $message): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * 断言时间延迟在区间内。
     *
     * @param string $datetime 目标时间
     * @param int $min 最小秒数
     * @param int $max 最大秒数
     * @param string $message 错误消息
     * @return void
     */
    private function assertDelayBetween(string $datetime, int $min, int $max, string $message): void
    {
        $delay = strtotime($datetime) - time();
        if ($delay < $min || $delay > $max) {
            throw new \RuntimeException($message . sprintf('，实际延迟 %d 秒', $delay));
        }
    }

    /**
     * 断言会抛出异常。
     *
     * @param callable $callback 待执行逻辑
     * @param string $message 错误消息
     * @return void
     */
    private function assertThrows(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            return;
        }

        throw new \RuntimeException($message);
    }

    /**
     * 断言会抛出指定类型异常。
     *
     * @param class-string<Throwable> $exceptionClass 异常类型
     */
    private function assertThrowsClass(callable $callback, string $exceptionClass, string $message): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if ($e instanceof $exceptionClass) {
                return;
            }

            throw new \RuntimeException($message . '，实际异常：' . $e::class, 0, $e);
        }

        throw new \RuntimeException($message);
    }
}
