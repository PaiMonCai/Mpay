<?php

declare(strict_types=1);

namespace app\common\sdk\yeepay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * 易宝 YOP RSA 轻量客户端。
 *
 * 当前开放平台无 SDK 接入规范使用 yop-auth-v3。POST 表单的内容摘要基于
 * 一次 RFC3986 编码后的有序参数串，实际表单再编码一次；GET 的规范查询串
 * 同样使用一次编码值，实际 URL 发送二次编码值。
 */
class YeepayYopClient
{
    private const SDK_VERSION = 'mpay-yop-auth-v3-1.0';
    private const SERVER_ROOT = 'https://openapi.yeepay.com/yop-center';
    private const AUTH_ALGORITHM = 'YOP-RSA2048-SHA256';
    private const AUTH_VERSION = 'yop-auth-v3';
    private const AUTH_EXPIRE_SECONDS = 1800;
    private const DIGEST_ALGORITHM = 'SHA256';
    private const HEADER_APP_KEY = 'x-yop-appkey';
    private const HEADER_CONTENT_SHA256 = 'x-yop-content-sha256';
    private const HEADER_REQUEST_ID = 'x-yop-request-id';

    /**
     * @var array<string, mixed>
     */
    private array $config;

    private Client $httpClient;

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
     * 发送 POST 请求。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function post(string $path, array $params): array
    {
        return $this->request('POST', $path, $params);
    }

    /**
     * 发送 GET 请求。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params): array
    {
        return $this->request('GET', $path, $params);
    }

    /**
     * 解密并验证通知 response。
     *
     * 协议顺序固定为：商户私钥解密随机密钥 -> AES-128-ECB 解密业务数据 ->
     * 易宝平台公钥验证业务签名。任一步失败都抛异常，不返回部分明文。
     *
     * @return array<string, mixed>
     */
    public function notifyDecrypt(string $source): array
    {
        $args = explode('$', trim($source));
        if (count($args) !== 4 || in_array('', $args, true)) {
            throw new YeepaySdkException('易宝通知 response 格式错误');
        }

        [$encryptedRandomKey, $encryptedData, $symmetricAlgorithm, $digestAlgorithm] = $args;
        if (!in_array(strtoupper($symmetricAlgorithm), ['AES', 'AES-128-ECB'], true)) {
            throw new YeepaySdkException('易宝通知对称加密算法不受支持');
        }
        if (strtoupper($digestAlgorithm) !== self::DIGEST_ALGORITHM) {
            throw new YeepaySdkException('易宝通知摘要算法不受支持');
        }

        $randomKey = $this->rsaPrivateDecrypt($encryptedRandomKey);
        if (strlen($randomKey) !== 16) {
            throw new YeepaySdkException('易宝通知随机密钥长度错误');
        }

        $ciphertext = $this->base64UrlDecode($encryptedData);
        $plain = openssl_decrypt($ciphertext, 'AES-128-ECB', $randomKey, OPENSSL_RAW_DATA);
        if (!is_string($plain) || $plain === '') {
            throw new YeepaySdkException('易宝通知数据解密失败');
        }

        $separator = strrpos($plain, '$');
        if ($separator === false || $separator === 0 || $separator === strlen($plain) - 1) {
            throw new YeepaySdkException('易宝通知明文格式错误');
        }
        $sourceData = substr($plain, 0, $separator);
        $signature = substr($plain, $separator + 1);
        if (!$this->rsaPublicVerify($sourceData, $signature, self::DIGEST_ALGORITHM)) {
            throw new YeepaySdkException('易宝通知验签失败');
        }

        $data = json_decode($sourceData, true);
        if (!is_array($data) || array_is_list($data)) {
            throw new YeepaySdkException('易宝通知数据不是合法 JSON 对象');
        }

        return $data;
    }

    /**
     * 发送渠道请求。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $params): array
    {
        $method = strtoupper(trim($method));
        $path = $this->canonicalPath($path);
        $signature = $this->signedRequest($method, $path, $params);
        $options = [
            'headers' => $signature['headers'] + [
                'x-yop-sdk-langs' => 'php',
                'x-yop-sdk-version' => self::SDK_VERSION,
                'Accept' => 'application/json',
            ],
        ];

        if ($method === 'POST') {
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
            $options['body'] = $signature['wire_parameters'];
        } elseif ($method === 'GET') {
            $options['query'] = $signature['encoded_parameters'];
        } else {
            throw new YeepaySdkException('易宝客户端不支持该 HTTP 方法');
        }

        try {
            $response = $this->httpClient->request($method, $this->serverRoot() . $path, $options);
        } catch (GuzzleException $e) {
            throw new YeepaySdkException('易宝网关请求失败', 0, $e);
        }

        return $this->parseResponse($response);
    }

    /**
     * 解析渠道响应。
     *
     * @return array<string, mixed>
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $responseSign = trim($response->getHeaderLine('x-yop-sign'));
        if ($responseSign !== '' && !$this->verifyResponseSignature($body, $responseSign)) {
            throw new YeepaySdkException('易宝响应验签失败');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            throw new YeepaySdkException('易宝响应解析失败');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new YeepaySdkException($this->responseErrorMessage($payload, '易宝网关响应异常'));
        }

        if (array_key_exists('result', $payload)) {
            $result = $payload['result'];
            if (is_string($result)) {
                $decoded = json_decode($result, true);
                $result = is_array($decoded) ? $decoded : null;
            }
            if (is_array($result)) {
                return $result;
            }
        }

        if (isset($payload['subMessage']) || isset($payload['message']) || isset($payload['error'])) {
            throw new YeepaySdkException($this->responseErrorMessage($payload, '易宝业务请求失败'));
        }

        throw new YeepaySdkException('易宝响应缺少 result');
    }

    /**
     * 构造 yop-auth-v3 签名材料。
     *
     * timestamp/requestId 仅允许测试固定向量时传入；生产请求均由本方法生成。
     *
     * @param array<string, mixed> $params
     * @return array{headers:array<string,string>,canonical_request:string,canonical_parameters:string,encoded_parameters:array<string,string>,wire_parameters:string}
     */
    private function signedRequest(
        string $method,
        string $path,
        array $params,
        ?string $timestamp = null,
        ?string $requestId = null
    ): array {
        $method = strtoupper($method);
        $path = $this->canonicalPath($path);
        $timestamp ??= gmdate('Y-m-d\TH:i:s\Z');
        $requestId ??= bin2hex(random_bytes(16));
        $canonicalParameters = $this->canonicalParameters($params);
        $encodedParameters = $this->encodedParameters($params);
        $contentHashSource = $method === 'POST' ? $canonicalParameters : '';
        $headers = [
            self::HEADER_APP_KEY => $this->requiredConfigText('app_key'),
            self::HEADER_CONTENT_SHA256 => hash('sha256', $contentHashSource),
            self::HEADER_REQUEST_ID => $requestId,
        ];
        ksort($headers, SORT_STRING);

        $canonicalHeaders = implode("\n", array_map(
            static fn (string $name, string $value): string => strtolower($name) . ':' . trim($value),
            array_keys($headers),
            $headers
        ));
        $signedHeaders = implode(';', array_keys($headers));
        $authString = self::AUTH_VERSION . '/' . $this->requiredConfigText('app_key') . '/' . $timestamp . '/' . self::AUTH_EXPIRE_SECONDS;
        $canonicalQuery = $method === 'GET' ? $canonicalParameters : '';
        $canonicalRequest = $authString . "\n"
            . $method . "\n"
            . $path . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders;
        $headers['Authorization'] = self::AUTH_ALGORITHM . ' '
            . $authString . '/' . $signedHeaders . '/' . $this->rsaPrivateSign($canonicalRequest);

        return [
            'headers' => $headers,
            'canonical_request' => $canonicalRequest,
            'canonical_parameters' => $canonicalParameters,
            'encoded_parameters' => $encodedParameters,
            'wire_parameters' => http_build_query($encodedParameters, '', '&', PHP_QUERY_RFC3986),
        ];
    }

    /**
     * 构建规范化请求参数。
     *
     * @param array<string, mixed> $params
     */
    private function canonicalParameters(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($value)) {
                    throw new YeepaySdkException('易宝请求参数 JSON 编码失败');
                }
            } elseif (!is_scalar($value)) {
                throw new YeepaySdkException('易宝请求包含不支持的参数类型');
            }
            $pairs[$this->rfc3986((string) $key)] = $this->rfc3986((string) $value);
        }
        ksort($pairs, SORT_STRING);

        return implode('&', array_map(
            static fn (string $key, string $value): string => $key . '=' . $value,
            array_keys($pairs),
            $pairs
        ));
    }

    /**
     * 编码请求参数。
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function encodedParameters(array $params): array
    {
        $encoded = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($value)) {
                    throw new YeepaySdkException('易宝请求参数 JSON 编码失败');
                }
            }
            $encoded[(string) $key] = $this->rfc3986((string) $value);
        }
        ksort($encoded, SORT_STRING);

        return $encoded;
    }

    private function canonicalPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new YeepaySdkException('易宝接口路径格式错误');
        }

        return implode('/', array_map(
            fn (string $segment): string => $this->rfc3986(rawurldecode($segment)),
            explode('/', $path)
        ));
    }

    private function rfc3986(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    private function rsaPrivateSign(string $data): string
    {
        $privateKey = openssl_pkey_get_private($this->privateKeyPem());
        if ($privateKey === false) {
            throw new YeepaySdkException('易宝商户私钥错误');
        }
        $signature = '';
        if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new YeepaySdkException('易宝请求签名失败');
        }

        return $this->base64UrlEncode($signature) . '$' . self::DIGEST_ALGORITHM;
    }

    private function rsaPublicVerify(string $data, string $signature, string $digestAlgorithm): bool
    {
        $publicKey = openssl_pkey_get_public($this->publicKeyPem());
        if ($publicKey === false) {
            throw new YeepaySdkException('易宝平台公钥错误');
        }
        $signature = preg_replace('/\$[A-Za-z0-9_-]+$/', '', trim($signature)) ?? '';

        return openssl_verify($data, $this->base64UrlDecode($signature), $publicKey, $digestAlgorithm) === 1;
    }

    private function rsaPrivateDecrypt(string $data): string
    {
        $privateKey = openssl_pkey_get_private($this->privateKeyPem());
        $ciphertext = $this->base64UrlDecode($data);
        if ($privateKey === false || !openssl_private_decrypt($ciphertext, $plain, $privateKey, OPENSSL_PKCS1_PADDING)) {
            throw new YeepaySdkException('易宝通知随机密钥解密失败');
        }

        return $plain;
    }

    private function verifyResponseSignature(string $body, string $signature): bool
    {
        $canonicalBody = preg_replace('/[\r\n\t ]+/', '', $body);
        if (!is_string($canonicalBody)) {
            return false;
        }

        return $this->rsaPublicVerify($canonicalBody, $signature, self::DIGEST_ALGORITHM);
    }

    private function privateKeyPem(): string
    {
        $key = $this->requiredConfigText('merchant_private_key');
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key) ?? '';
        foreach (['RSA PRIVATE KEY', 'PRIVATE KEY'] as $label) {
            $candidate = "-----BEGIN {$label}-----\n"
                . wordwrap($body, 64, "\n", true)
                . "\n-----END {$label}-----";
            if (openssl_pkey_get_private($candidate) !== false) {
                return $candidate;
            }
        }

        throw new YeepaySdkException('易宝商户私钥错误');
    }

    private function publicKeyPem(): string
    {
        $key = $this->requiredConfigText('platform_public_key');
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key) ?? '';
        foreach (['PUBLIC KEY', 'RSA PUBLIC KEY'] as $label) {
            $candidate = "-----BEGIN {$label}-----\n"
                . wordwrap($body, 64, "\n", true)
                . "\n-----END {$label}-----";
            if (openssl_pkey_get_public($candidate) !== false) {
                return $candidate;
            }
        }

        throw new YeepaySdkException('易宝平台公钥错误');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $data = trim($data);
        if ($data === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $data) !== 1) {
            throw new YeepaySdkException('易宝 Base64URL 数据格式错误');
        }
        $unpadded = rtrim($data, '=');
        $unpadded .= str_repeat('=', (4 - strlen($unpadded) % 4) % 4);
        $decoded = base64_decode(strtr($unpadded, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new YeepaySdkException('易宝 Base64URL 数据解码失败');
        }

        return $decoded;
    }

    /**
     * 提取渠道错误信息。
     *
     * @param array<string, mixed> $payload
     */
    private function responseErrorMessage(array $payload, string $fallback): string
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $code = trim((string) ($payload['subCode'] ?? $payload['code'] ?? $error['code'] ?? ''));
        $message = trim((string) ($payload['subMessage'] ?? $payload['message'] ?? $error['message'] ?? $fallback));
        $message = preg_replace('/\s+/', ' ', $message) ?: $fallback;
        $message = mb_strcut($message, 0, 300, 'UTF-8');

        return $code !== '' ? '[' . $code . ']' . $message : $message;
    }

    private function serverRoot(): string
    {
        $custom = trim((string) ($this->config['api_base_url'] ?? ''));

        return $custom !== '' ? rtrim($custom, '/') : self::SERVER_ROOT;
    }

    private function requiredConfigText(string $key): string
    {
        $value = trim((string) ($this->config[$key] ?? ''));
        if ($value === '') {
            throw new YeepaySdkException('易宝 SDK 缺少配置：' . $key);
        }

        return $value;
    }
}
