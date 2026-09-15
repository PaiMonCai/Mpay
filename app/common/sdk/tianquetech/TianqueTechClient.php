<?php

declare(strict_types=1);

namespace app\common\sdk\tianquetech;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 天阙科技 OpenAPI 轻量客户端。
 *
 * 封装公共参数、RSA 签名、响应验签和 JSON 请求。
 */
class TianqueTechClient
{
    public const PATH_ACTIVE_SCAN_CURRENT = '/order/activePlusScan';
    public const PATH_ACTIVE_SCAN_LEGACY = '/order/activeScan';
    public const PATH_JSAPI_SCAN = '/order/jsapiScan';
    public const PATH_APPLET_SCAN_PRE = '/order/appletScanPre';
    public const PATH_REFUND = '/order/refund';
    public const PATH_REFUND_QUERY = '/query/refundQuery';
    public const PATH_TRADE_QUERY = '/query/tradeQuery';
    public const PATH_CLOSE_CURRENT = '/query/close';
    public const PATH_CANCEL_LEGACY = '/query/cancel';

    private const SIGN_TYPE = 'RSA';
    private const VERSION = '1.0';

    /**
     * SDK 配置。
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private Client $httpClient;

    /**
     * 最近一次请求报文。
     */
    private string $lastRequestBody = '';

    /**
     * 最近一次响应报文。
     */
    private string $lastResponseBody = '';

    /**
     * 最近一次请求号，只用于非敏感诊断和响应关联。
     */
    private string $lastRequestId = '';

    /**
     * 构造方法。
     *
     * @param array<string, mixed> $config SDK 配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 提交 OpenAPI 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $data reqData 业务参数
     * @return array<string, mixed> respData 响应参数
     */
    public function submit(string $path, array $data): array
    {
        $payload = [
            'orgId' => $this->configText('org_id'),
            'reqId' => bin2hex(random_bytes(16)),
            'reqData' => $data,
            'timestamp' => date('YmdHis'),
            'version' => self::VERSION,
            'signType' => self::SIGN_TYPE,
        ];
        $payload['sign'] = $this->sign($payload);
        $this->lastRequestId = (string) $payload['reqId'];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new TianqueTechSdkException('天阙请求报文编码失败');
        }

        $this->lastRequestBody = $json;

        try {
            $response = $this->httpClient->post($this->gatewayUrl($path), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            throw new TianqueTechSdkException('天阙网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $this->lastResponseBody = (string) $response->getBody();
        $decoded = json_decode($this->lastResponseBody, true);
        if (!is_array($decoded)) {
            throw new TianqueTechSdkException('天阙响应不是合法 JSON');
        }

        if (isset($decoded['sign']) && !$this->verify($decoded)) {
            throw new TianqueTechSdkException('天阙响应验签失败');
        }

        if ((string) ($decoded['code'] ?? '') === '0000') {
            if (!isset($decoded['sign'])) {
                throw new TianqueTechSdkException('天阙成功响应缺少签名');
            }
            if (!hash_equals($this->configText('org_id'), trim((string) ($decoded['orgId'] ?? '')))) {
                throw new TianqueTechSdkException('天阙响应机构编号不匹配');
            }
            if (!hash_equals($this->lastRequestId, trim((string) ($decoded['reqId'] ?? '')))) {
                throw new TianqueTechSdkException('天阙响应请求号不匹配');
            }

            $data = $decoded['respData'] ?? [];
            return is_array($data) ? $data : [];
        }

        throw new TianqueTechSdkException((string) ($decoded['msg'] ?? '天阙请求失败'));
    }

    /**
     * 校验通知或响应签名。
     *
     * @param array<string, mixed> $payload 待验签报文
     * @return bool 是否通过
     */
    public function verify(array $payload): bool
    {
        $signature = (string) ($payload['sign'] ?? '');
        if ($signature === '') {
            return false;
        }

        return openssl_verify(
            $this->signContent($payload),
            base64_decode($signature, true) ?: '',
            $this->publicKey(),
            OPENSSL_ALGO_SHA1
        ) === 1;
    }

    /**
     * 获取最近一次请求报文。
     */
    public function lastRequestBody(): string
    {
        return $this->lastRequestBody;
    }

    /**
     * 获取最近一次响应报文。
     */
    public function lastResponseBody(): string
    {
        return $this->lastResponseBody;
    }

    /**
     * 获取最近一次请求号。不会暴露 reqData、签名或身份参数。
     */
    public function lastRequestId(): string
    {
        return $this->lastRequestId;
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $payload 待签名报文
     */
    private function sign(array $payload): string
    {
        $ok = openssl_sign($this->signContent($payload), $signature, $this->privateKey(), OPENSSL_ALGO_SHA1);
        if (!$ok) {
            throw new TianqueTechSdkException('天阙请求加签失败');
        }

        return base64_encode($signature);
    }

    /**
     * 构造待签名字符串。
     *
     * @param array<string, mixed> $payload 待签名报文
     */
    private function signContent(array $payload): string
    {
        unset($payload['sign']);
        ksort($payload);

        return implode('&', array_map(
            static fn (string $key, mixed $value): string => $key . '=' . (is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value),
            array_keys($payload),
            $payload
        ));
    }

    /**
     * 商户私钥。
     */
    private function privateKey(): mixed
    {
        $value = $this->configText('merchant_private_key');
        foreach ($this->privateKeyCandidates($value) as $key) {
            $resource = openssl_pkey_get_private($key);
            if ($resource) {
                return $resource;
            }
        }

        throw new TianqueTechSdkException('天阙商户私钥错误');
    }

    /**
     * 平台公钥。
     */
    private function publicKey(): mixed
    {
        $key = $this->pem((string) $this->configText('platform_public_key'), 'PUBLIC KEY');
        $resource = openssl_pkey_get_public($key);
        if (!$resource) {
            throw new TianqueTechSdkException('天阙平台公钥错误');
        }

        return $resource;
    }

    /**
     * 补全 PEM 格式。
     */
    private function pem(string $value, string $label): string
    {
        $value = trim($value);
        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        return "-----BEGIN {$label}-----\n" . wordwrap($value, 64, "\n", true) . "\n-----END {$label}-----";
    }

    /**
     * 同时兼容官方推荐的 PKCS#8 裸正文和历史配置中的 PKCS#1 裸正文。
     *
     * @return array<int, string>
     */
    private function privateKeyCandidates(string $value): array
    {
        $value = trim($value);
        if (str_contains($value, '-----BEGIN')) {
            return [$value];
        }

        return [
            $this->pem($value, 'PRIVATE KEY'),
            $this->pem($value, 'RSA PRIVATE KEY'),
        ];
    }

    /**
     * 拼接网关地址。
     */
    private function gatewayUrl(string $path): string
    {
        $custom = $this->configText('api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/') . '/' . ltrim($path, '/');
        }

        $base = $this->configBool('sandbox')
            ? 'https://openapi-test.tianquetech.com'
            : 'https://openapi.tianquetech.com';

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }

    /**
     * 获取布尔配置。
     */
    private function configBool(string $key): bool
    {
        return in_array($this->config[$key] ?? false, [true, 1, '1', 'true', 'on'], true);
    }
}
