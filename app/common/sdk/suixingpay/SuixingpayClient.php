<?php

declare(strict_types=1);

namespace app\common\sdk\suixingpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 随行付聚合支付 OpenAPI 轻量客户端。
 *
 * 封装统一报文、RSA 签名、响应验签和 JSON 请求。
 */
class SuixingpayClient
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
     * @var array<string, mixed>
     */
    private array $config;

    private Client $httpClient;

    private string $lastRequestId = '';

    /**
     * 构造方法。
     *
     * @param array<string, mixed> $config
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
     * 提交渠道请求。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function submit(string $path, array $data): array
    {
        $payload = [
            'orgId' => $this->configText('suixingpay_org_id'),
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
            throw new SuixingpaySdkException('随行付请求报文编码失败');
        }

        try {
            $response = $this->httpClient->post($this->gatewayUrl($path), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            throw new SuixingpaySdkException('随行付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new SuixingpaySdkException('随行付响应不是合法 JSON');
        }
        if (isset($decoded['sign']) && !$this->verify($decoded)) {
            throw new SuixingpaySdkException('随行付响应验签失败');
        }

        if ((string) ($decoded['code'] ?? '') === '0000') {
            if (!isset($decoded['sign'])) {
                throw new SuixingpaySdkException('随行付成功响应缺少签名');
            }
            if (!hash_equals($this->configText('suixingpay_org_id'), trim((string) ($decoded['orgId'] ?? '')))) {
                throw new SuixingpaySdkException('随行付响应机构编号不匹配');
            }
            if (!hash_equals($this->lastRequestId, trim((string) ($decoded['reqId'] ?? '')))) {
                throw new SuixingpaySdkException('随行付响应请求号不匹配');
            }

            $responseData = $decoded['respData'] ?? [];
            return is_array($responseData) ? $responseData : [];
        }

        $code = trim((string) ($decoded['code'] ?? ''));
        $message = trim((string) ($decoded['msg'] ?? '随行付请求失败'));
        throw new SuixingpaySdkException($code === '' ? $message : $message . '（' . $code . '）');
    }

    /**
     * 校验渠道签名。
     *
     * @param array<string, mixed> $payload
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
     * 最近一次请求号仅用于脱敏诊断和响应关联。
     */
    public function lastRequestId(): string
    {
        return $this->lastRequestId;
    }

    /**
     * 生成渠道签名。
     *
     * @param array<string, mixed> $payload
     */
    private function sign(array $payload): string
    {
        if (!openssl_sign($this->signContent($payload), $signature, $this->privateKey(), OPENSSL_ALGO_SHA1)) {
            throw new SuixingpaySdkException('随行付请求加签失败');
        }

        return base64_encode($signature);
    }

    /**
     * 顶层字段按 ASCII 排序，空值和 sign 不参与签名；reqData 使用紧凑 JSON。
     *
     * @param array<string, mixed> $payload
     */
    private function signContent(array $payload): string
    {
        unset($payload['sign']);
        $payload = array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
        ksort($payload);

        $parts = [];
        foreach ($payload as $key => $value) {
            $encoded = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value;
            if (!is_string($encoded)) {
                throw new SuixingpaySdkException('随行付签名数据编码失败');
            }
            $parts[] = $key . '=' . $encoded;
        }

        return implode('&', $parts);
    }

    private function privateKey(): mixed
    {
        $value = $this->configText('suixingpay_merchant_private_key');
        foreach ($this->privateKeyCandidates($value) as $candidate) {
            $resource = openssl_pkey_get_private($candidate);
            if ($resource) {
                return $resource;
            }
        }

        throw new SuixingpaySdkException('随行付商户私钥错误');
    }

    private function publicKey(): mixed
    {
        $key = $this->pem($this->configText('suixingpay_platform_public_key'), 'PUBLIC KEY');
        $resource = openssl_pkey_get_public($key);
        if (!$resource) {
            throw new SuixingpaySdkException('随行付平台公钥错误');
        }

        return $resource;
    }

    private function pem(string $value, string $label): string
    {
        $value = trim($value);
        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        $value = preg_replace('/\s+/', '', $value) ?? '';

        return "-----BEGIN {$label}-----\n" . wordwrap($value, 64, "\n", true) . "\n-----END {$label}-----";
    }

    /**
     * 构建私钥候选列表。
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

    private function gatewayUrl(string $path): string
    {
        $custom = $this->configText('suixingpay_api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/') . '/' . ltrim($path, '/');
        }

        $base = $this->configBool('suixingpay_sandbox')
            ? 'https://openapi-test.tianquetech.com'
            : 'https://openapi.tianquetech.com';

        return $base . '/' . ltrim($path, '/');
    }

    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }

    private function configBool(string $key): bool
    {
        return in_array($this->config[$key] ?? false, [true, 1, '1', 'true', 'on'], true);
    }
}
