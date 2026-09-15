<?php

declare(strict_types=1);

namespace app\common\sdk\ysepay;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Closure;

/**
 * 银盛支付 RSA 开放网关客户端。
 *
 * rainbow_legacy 档案固定使用 RSA-SHA1 排序及空值过滤规则；
 * 接口版本与生产 HTTPS 网关按银盛当前公开接口资料逐接口固定。
 */
class YsepayClient
{
    public const QRCODE_GATEWAY = 'https://qrcode.ysepay.com/gateway.do';
    public const OPENAPI_GATEWAY = 'https://openapi.ysepay.com/gateway.do';

    /**
     * @var array<string, array{gateway: string, version: string, page: bool}>
     */
    private const METHOD_PROFILES = [
        'ysepay.online.qrcodepay' => [
            'gateway' => self::QRCODE_GATEWAY,
            'version' => '3.5',
            'page' => false,
        ],
        'ysepay.online.alijsapi.pay' => [
            'gateway' => self::QRCODE_GATEWAY,
            'version' => '3.5',
            'page' => false,
        ],
        'ysepay.online.weixin.pay' => [
            'gateway' => self::QRCODE_GATEWAY,
            'version' => '6.4',
            'page' => false,
        ],
        'ysepay.online.cupmulapp.qrcodepay' => [
            'gateway' => self::QRCODE_GATEWAY,
            'version' => '3.4',
            'page' => false,
        ],
    // 当前公开资料确认行业码支付需要先换取 userId；精确换取方法名由 rainbow_legacy 档案固定。
        'ysepay.online.cupgetmulapp.userid' => [
            'gateway' => self::QRCODE_GATEWAY,
            'version' => '3.5',
            'page' => false,
        ],
        'ysepay.online.wap.directpay.createbyuser' => [
            'gateway' => self::OPENAPI_GATEWAY,
            'version' => '3.0',
            'page' => true,
        ],
        'ysepay.online.trade.refund' => [
            'gateway' => self::OPENAPI_GATEWAY,
            'version' => '3.0',
            'page' => false,
        ],
    ];

    /**
     * @var array<string, string>
     */
    private array $config;

    private ClientInterface $httpClient;

    /** @var mixed */
    private $platformPublicKey;

    /** @var mixed */
    private $merchantPrivateKey;

    private Closure $clock;

    /**
     * 构造银盛支付 API 客户端。
     *
     * @param array<string, string> $config SDK 配置；证书字段必须为文件内容，不接受路径
     */
    public function __construct(
        array $config,
        ?ClientInterface $httpClient = null,
        ?callable $clock = null
    )
    {
        $this->config = $config;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
        $this->assertRequiredConfiguration();
        $this->platformPublicKey = $this->loadPlatformPublicKey($config['platform_cert']);
        $this->merchantPrivateKey = $this->loadMerchantPrivateKey(
            $config['private_cert'],
            $config['private_cert_password']
        );
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 发起后端接口。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, string> $urls 回调地址
     * @return array<string, mixed>
     */
    public function execute(string $method, array $bizContent, array $urls = []): array
    {
        $profile = $this->methodProfile($method);
        if ($profile['page']) {
            throw new YsepaySdkException('银盛页面接口不能按后端接口执行');
        }
        $payload = $this->buildPayload($method, $bizContent, $urls);

        try {
            $response = $this->httpClient->request('POST', $profile['gateway'], [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new YsepaySdkException('银盛网关通信失败', true, 0, $e);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new YsepaySdkException('银盛网关 HTTP 状态异常', true);
        }

        return $this->parseResponse((string) $response->getBody(), $method);
    }

    /**
     * 构造支付宝 H5 自动表单参数。
     *
     * WAP 接口的业务参数位于表单顶层，不使用 biz_content。
     *
     * @param array<string, mixed> $bizParams 业务参数
     * @param array<string, string> $urls 回调地址
     * @return array{action: string, payload: array<string, string>}
     */
    public function pageRequest(string $method, array $bizParams, array $urls = []): array
    {
        $profile = $this->methodProfile($method);
        if (!$profile['page']) {
            throw new YsepaySdkException('银盛后端接口不能按页面接口执行');
        }

        $payload = $this->commonPayload($method, $urls);
        foreach ($bizParams as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new YsepaySdkException('银盛页面接口参数类型无效');
            }
            if (array_key_exists((string) $key, $payload)) {
                throw new YsepaySdkException('银盛页面接口业务参数覆盖公共参数');
            }
            $payload[(string) $key] = $value === null ? '' : (string) $value;
        }
        $payload['sign'] = $this->sign($this->signingContent($payload));

        return ['action' => $profile['gateway'], 'payload' => $payload];
    }

    /**
     * 构造后端接口签名参数；可传固定时间用于定向签名向量测试。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, string> $urls 回调地址
     * @return array<string, string>
     */
    public function buildPayload(
        string $method,
        array $bizContent,
        array $urls = [],
        ?string $timestamp = null
    ): array {
        $profile = $this->methodProfile($method);
        if ($profile['page']) {
            throw new YsepaySdkException('银盛页面接口必须使用顶层表单参数');
        }
        try {
            $bizJson = json_encode(
                $bizContent,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new YsepaySdkException('银盛业务参数无法编码为 JSON', false, 0, $e);
        }

        $payload = $this->commonPayload($method, $urls, $timestamp);
        $payload['biz_content'] = $bizJson;
        $payload['sign'] = $this->sign($this->signingContent($payload));

        return $payload;
    }

    /**
     * 校验异步通知签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = trim((string) ($payload['sign'] ?? ''));

        return $sign !== '' && $this->verifyContent($this->signingContent($payload), $sign);
    }

    /**
     * rainbow_legacy 签名原文：按参数名升序，剔除 sign、空值和 @ 开头值后以 & 连接。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function signingContent(array $payload): string
    {
        unset($payload['sign']);
        ksort($payload, SORT_STRING);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new YsepaySdkException('银盛签名参数类型无效');
            }
            $text = (string) $value;
            if (str_starts_with($text, '@')) {
                continue;
            }
            $pieces[] = (string) $key . '=' . $text;
        }

        return implode('&', $pieces);
    }

    /**
     * 获取指定接口方法的网关地址。
     */
    public function gatewayForMethod(string $method): string
    {
        return $this->methodProfile($method)['gateway'];
    }

    /**
     * 获取指定接口方法的协议版本。
     */
    public function versionForMethod(string $method): string
    {
        return $this->methodProfile($method)['version'];
    }

    /**
     * 解析并校验上游响应。
     *
     * @return array<string, mixed>
     */
    private function parseResponse(string $raw, string $method): array
    {
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new YsepaySdkException('银盛响应不是合法 JSON', true, 0, $e);
        }
        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new YsepaySdkException('银盛响应结构无效', true);
        }

        $nodeName = str_replace('.', '_', $method) . '_response';
        $data = $parsed[$nodeName] ?? null;
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new YsepaySdkException('银盛响应数据节点不存在', true);
        }
        $sign = trim((string) ($parsed['sign'] ?? ''));
        if ($sign === '') {
            throw new YsepaySdkException('银盛响应缺少签名', true);
        }
        $signData = $this->responseSignData($raw, $nodeName);
        if ($signData === '' || !$this->verifyContent($signData, $sign)) {
            throw new YsepaySdkException('银盛响应验签失败', true);
        }

        if (trim((string) ($data['code'] ?? '')) !== '10000') {
            $subCode = trim((string) ($data['sub_code'] ?? ''));
            $message = trim((string) ($data['sub_msg'] ?? $data['msg'] ?? '银盛请求失败'));
            throw new YsepaySdkException(
                ($subCode === '' ? '' : '[' . $subCode . ']') . $message,
                in_array($subCode, ['ACQ.SYSTEM_ERROR', 'ACQ.QUERY_NO_RECORD'], true)
            );
        }

        return $data;
    }

    /**
     * 精确截取 JSON 中业务响应节点的原始字节，避免重编码改变验签原文。
     */
    private function responseSignData(string $raw, string $nodeName): string
    {
        $needle = '"' . $nodeName . '"';
        $nameAt = strpos($raw, $needle);
        if ($nameAt === false || strpos($raw, $needle, $nameAt + strlen($needle)) !== false) {
            return '';
        }
        $colonAt = strpos($raw, ':', $nameAt + strlen($needle));
        if ($colonAt === false) {
            return '';
        }
        $length = strlen($raw);
        $start = $colonAt + 1;
        while ($start < $length && ctype_space($raw[$start])) {
            $start++;
        }
        if ($start >= $length || $raw[$start] !== '{') {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($index = $start; $index < $length; $index++) {
            $char = $raw[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($raw, $start, $index - $start + 1);
                }
            }
        }

        return '';
    }

    /**
     * 构建银盛公共请求参数。
     *
     * @param array<string, string> $urls
     * @return array<string, string>
     */
    private function commonPayload(string $method, array $urls, ?string $timestamp = null): array
    {
        $profile = $this->methodProfile($method);
        $payload = [
            'method' => $method,
            'partner_id' => $this->config['partner_id'],
            'timestamp' => $timestamp ?? date('Y-m-d H:i:s'),
            'charset' => 'UTF-8',
            'sign_type' => 'RSA',
            'version' => $profile['version'],
        ];
        foreach (['notify_url', 'return_url'] as $field) {
            $value = trim((string) ($urls[$field] ?? ''));
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    /**
     * 获取接口方法档案。
     *
     * @return array{gateway: string, version: string, page: bool}
     */
    private function methodProfile(string $method): array
    {
        $profile = self::METHOD_PROFILES[$method] ?? null;
        if (!is_array($profile)) {
            throw new YsepaySdkException('银盛接口未纳入已确认方法白名单');
        }

        return $profile;
    }

    /**
     * rainbow_legacy 使用 RSA-SHA1；显式指定算法避免依赖 OpenSSL 默认值。
     */
    private function sign(string $content): string
    {
        $signature = '';
        if (!openssl_sign($content, $signature, $this->merchantPrivateKey, OPENSSL_ALGO_SHA1)) {
            throw new YsepaySdkException('银盛请求签名失败');
        }

        return base64_encode($signature);
    }

    private function verifyContent(string $content, string $sign): bool
    {
        $decoded = base64_decode($sign, true);
        if (!is_string($decoded) || $decoded === '') {
            return false;
        }

        return openssl_verify($content, $decoded, $this->platformPublicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * 加载平台公钥。
     *
     * @return mixed
     */
    private function loadPlatformPublicKey(string $certificate)
    {
        $certificate = $this->certificatePem($certificate);
        $this->assertCertificateValidity($certificate, '银盛平台证书');
        $key = openssl_pkey_get_public($certificate);
        if ($key === false) {
            throw new YsepaySdkException('银盛平台证书公钥解析失败');
        }
        $this->assertRsaKey($key, '银盛平台证书');

        return $key;
    }

    /**
     * 加载商户私钥。
     *
     * @return mixed
     */
    private function loadMerchantPrivateKey(string $pfx, string $password)
    {
        $certificates = [];
        if (!@openssl_pkcs12_read($pfx, $certificates, $password)) {
            throw new YsepaySdkException('银盛商户 PFX 解析失败，请检查证书或密码');
        }
        $privateKey = openssl_pkey_get_private((string) ($certificates['pkey'] ?? ''));
        $merchantCertificate = (string) ($certificates['cert'] ?? '');
        if ($privateKey === false || $merchantCertificate === '') {
            throw new YsepaySdkException('银盛商户 PFX 缺少私钥或证书');
        }
        $this->assertCertificateValidity($merchantCertificate, '银盛商户证书');
        if (!openssl_x509_check_private_key($merchantCertificate, $privateKey)) {
            throw new YsepaySdkException('银盛商户证书与私钥不匹配');
        }
        $this->assertRsaKey($privateKey, '银盛商户证书');
        foreach ((array) ($certificates['extracerts'] ?? []) as $index => $extraCertificate) {
            $this->assertCertificateValidity((string) $extraCertificate, '银盛商户证书链 #' . ($index + 1));
        }

        return $privateKey;
    }

    private function certificatePem(string $certificate): string
    {
        $trimmed = trim($certificate);
        if ($trimmed === '') {
            throw new YsepaySdkException('银盛平台证书为空');
        }
        if (str_contains($trimmed, '-----BEGIN CERTIFICATE-----')) {
            return $trimmed;
        }

        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($certificate), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    private function assertCertificateValidity(string $certificate, string $label): void
    {
        $parsed = openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            throw new YsepaySdkException($label . '解析失败');
        }
        $now = (int) ($this->clock)();
        $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
        if ($validFrom <= 0 || $validTo <= 0 || $now < $validFrom || $now > $validTo) {
            throw new YsepaySdkException($label . '不在有效期内');
        }
    }

    /**
     * 校验 RSA 密钥。
     *
     * @param mixed $key
     */
    private function assertRsaKey($key, string $label): void
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)
            || (int) ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA
            || (int) ($details['bits'] ?? 0) < 2048) {
            throw new YsepaySdkException($label . '必须使用至少 2048 位 RSA 密钥');
        }
    }

    private function assertRequiredConfiguration(): void
    {
        foreach ([
            'partner_id' => '银盛服务商商户号',
            'platform_cert' => '银盛平台证书',
            'private_cert' => '银盛商户 PFX',
            'private_cert_password' => '银盛商户 PFX 密码',
        ] as $field => $label) {
            if (trim((string) ($this->config[$field] ?? '')) === '') {
                throw new YsepaySdkException($label . '不能为空');
            }
        }
    }
}
