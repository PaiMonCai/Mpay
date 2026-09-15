<?php

declare(strict_types=1);

namespace app\common\sdk\adapay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * AdaPay 轻量客户端。
 */
class AdapayClient
{
    public const API_GATEWAY = 'https://api.adapay.tech';
    public const PAGE_GATEWAY = 'https://page.adapay.tech';

    /**
     * @var array<string, string>
     */
    private array $config;

    private Client $httpClient;

    /**
     * 构造 AdaPay API 客户端。
     *
     * @param array<string, string> $config SDK 配置
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
     * 创建上游支付订单。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function createPayment(array $payload): array
    {
        return $this->request(self::API_GATEWAY, 'POST', '/v1/payments', array_replace($payload, [
            'app_id' => $this->config['app_id'] ?? '',
            'sign_type' => 'RSA2',
        ]));
    }

    /**
     * 页面预下单是独立产品能力，不能与 `wx_lite` 小程序支付混用。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function pageRequest(string $funcCode, array $payload): array
    {
        return $this->request(self::PAGE_GATEWAY, 'POST', '/v1/' . str_replace('.', '/', $funcCode), array_replace($payload, [
            'app_id' => $this->config['app_id'] ?? '',
            'adapay_func_code' => $funcCode,
        ]));
    }

    /**
     * 查询上游支付订单。
     *
     * @return array<string, mixed>
     */
    public function queryPayment(string $paymentId): array
    {
        return $this->request(
            self::API_GATEWAY,
            'GET',
            '/v1/payments/' . rawurlencode($paymentId),
            ['payment_id' => $paymentId]
        );
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function refund(string $paymentId, array $payload): array
    {
        return $this->request(
            self::API_GATEWAY,
            'POST',
            '/v1/payments/' . rawurlencode($paymentId) . '/refunds',
            ['payment_id' => $paymentId] + $payload
        );
    }

    /**
     * 查询退款订单。
     *
     * @return array<string, mixed>
     */
    public function queryRefund(string $refundId): array
    {
        return $this->request(
            self::API_GATEWAY,
            'GET',
            '/v1/payments/refunds',
            ['refund_id' => $refundId]
        );
    }

    /**
     * 校验异步通知中原始 data 字符串的签名。
     */
    public function verifyNotify(string $sign, string $data): bool
    {
        if ($sign === '' || $data === '') {
            return false;
        }
        $signature = base64_decode($sign, true);
        if ($signature === false) {
            return false;
        }

        return openssl_verify($data, $signature, $this->platformPublicKey(), OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * 发送上游 API 请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    private function request(string $gateway, string $method, string $path, array $payload): array
    {
        if (!str_starts_with(strtolower($gateway), 'https://')) {
            throw new AdapaySdkException('AdaPay网关必须使用HTTPS');
        }

        $method = strtoupper($method);
        $url = $gateway . $path;
        $requestUrl = $url;
        $body = '';
        if ($method === 'GET') {
            ksort($payload);
            $query = http_build_query($payload, '', '&', PHP_QUERY_RFC1738);
            $signatureContent = $url . $query;
            if ($query !== '') {
                $requestUrl .= '?' . $query;
            }
        } else {
            $body = $this->encodeJson($payload);
            $signatureContent = $url . $body;
        }

        $options = [
            'headers' => [
                'Authorization' => (string) ($this->config['api_key'] ?? ''),
                'Signature' => $this->signature($signatureContent),
                'sdk_version' => 'v1.4.4',
                'Content-Type' => $method === 'GET' ? 'text/html' : 'application/json',
            ],
        ];
        if ($method !== 'GET') {
            $options['body'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $requestUrl, $options);
        } catch (GuzzleException $e) {
            throw new AdapaySdkException('AdaPay网关通信失败', true, '', $e);
        }

        return $this->parseResponse($response);
    }

    /**
     * 解析并校验上游响应。
     *
     * @return array<string, mixed>
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        try {
            $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AdapaySdkException('AdaPay响应不是合法JSON', $statusCode >= 500 || $statusCode < 400, '', $e);
        }
        if (!is_array($envelope)) {
            throw new AdapaySdkException('AdaPay响应结构无效', true);
        }

        $dataText = $envelope['data'] ?? null;
        $signatureText = $envelope['signature'] ?? null;
        if (!is_string($dataText) || $dataText === '' || !is_string($signatureText) || $signatureText === '') {
            $code = 'HTTP_' . $statusCode;
            $message = $this->safeErrorMessage($envelope, 'AdaPay响应缺少可验签业务数据');
            throw new AdapaySdkException($message, $statusCode < 400 || $statusCode >= 500, $code);
        }
        if (!$this->verifyResponseSignature($signatureText, $dataText)) {
            throw new AdapaySdkException('AdaPay同步响应验签失败', true, 'RESPONSE_SIGNATURE_INVALID');
        }

        try {
            $data = json_decode($dataText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AdapaySdkException('AdaPay已验签业务数据不是合法JSON', true, 'RESPONSE_DATA_INVALID', $e);
        }
        if (!is_array($data)) {
            throw new AdapaySdkException('AdaPay已验签业务数据结构无效', true, 'RESPONSE_DATA_INVALID');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            $code = trim((string) ($data['error_code'] ?? $data['failure_code'] ?? ('HTTP_' . $statusCode)));
            throw new AdapaySdkException(
                $this->safeErrorMessage($data, 'AdaPay明确拒绝请求'),
                false,
                $code
            );
        }

        return $data;
    }

    private function signature(string $content): string
    {
        $signature = '';
        if (!openssl_sign($content, $signature, $this->merchantPrivateKey(), OPENSSL_ALGO_SHA1)) {
            throw new AdapaySdkException('AdaPay请求签名失败');
        }

        return base64_encode($signature);
    }

    private function verifyResponseSignature(string $signatureText, string $dataText): bool
    {
        $signature = base64_decode($signatureText, true);
        return $signature !== false
            && openssl_verify($dataText, $signature, $this->platformPublicKey(), OPENSSL_ALGO_SHA1) === 1;
    }

    private function merchantPrivateKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($this->pemKey((string) ($this->config['merchant_private_key'] ?? ''), 'private'));
        if ($key === false) {
            throw new AdapaySdkException('AdaPay商户私钥不正确');
        }

        return $key;
    }

    private function platformPublicKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public($this->pemKey((string) ($this->config['platform_public_key'] ?? ''), 'public'));
        if ($key === false) {
            throw new AdapaySdkException('AdaPay平台公钥不正确');
        }

        return $key;
    }

    /**
     * 编码 JSON 请求体。
     *
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AdapaySdkException('AdaPay请求JSON编码失败', false, '', $e);
        }
    }

    /**
     * 生成可安全记录的错误摘要。
     *
     * @param array<string, mixed> $payload
     */
    private function safeErrorMessage(array $payload, string $fallback): string
    {
        foreach (['error_msg', 'failure_msg', 'message'] as $field) {
            $message = trim((string) ($payload[$field] ?? ''));
            if ($message !== '') {
                return mb_strcut(preg_replace('/\s+/', ' ', $message) ?? '', 0, 180, 'UTF-8');
            }
        }

        return $fallback;
    }

    private function pemKey(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $label = $type === 'private' ? 'PRIVATE KEY' : 'PUBLIC KEY';

        return "-----BEGIN {$label}-----\n"
            . wordwrap(str_replace(["\r", "\n"], '', $key), 64, "\n", true)
            . "\n-----END {$label}-----";
    }
}
