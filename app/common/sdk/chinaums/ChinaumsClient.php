<?php

declare(strict_types=1);

namespace app\common\sdk\chinaums;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 银联商务开放平台轻量客户端。
 *
 * 仅负责 OPEN-BODY-SIG、OPEN-FORM-PARAM、通知签名与 HTTPS 通讯；
 * 业务状态、订单归属和金额校验由支付插件负责。
 */
class ChinaumsClient
{
    public const PROD_GATEWAY = 'https://api-mop.chinaums.com';
    public const TEST_GATEWAY = 'https://test-api-open.chinaums.com';

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
     * 发起 OPEN-BODY-SIG JSON 请求。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $path, array $params): array
    {
        $body = $this->encodeJson($params, '银联商务请求报文编码失败');

        try {
            $response = $this->httpClient->post($this->endpoint($path), [
                'headers' => [
                    'Accept' => '*/*',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Authorization' => $this->bodyAuthorization($body),
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new ChinaumsSdkException('银联商务网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $statusCode = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new ChinaumsSdkException('银联商务响应不是合法 JSON（HTTP ' . $statusCode . '）');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ChinaumsSdkException('银联商务网关返回 HTTP ' . $statusCode);
        }

        return $data;
    }

    /**
     * 构造 OPEN-FORM-PARAM 的原始表单字段。
     *
     * content 必须保持生成签名时的 JSON 原文；调用方不得拆解、重编码后再签名。
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function formParameters(
        array $params,
        ?string $timestamp = null,
        ?string $nonce = null
    ): array {
        $content = $this->encodeJson($params, '银联商务跳转参数编码失败');
        $timestamp = $timestamp ?? date('YmdHis');
        $nonce = $nonce ?? $this->nonce();
        $this->assertTimestampAndNonce($timestamp, $nonce);

        return [
            'authorization' => 'OPEN-FORM-PARAM',
            'appId' => $this->configText('app_id'),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'content' => $content,
            'signature' => $this->signature($timestamp, $nonce, $content),
        ];
    }

    /**
     * 获取包含网关的接口地址。
     */
    public function endpoint(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new ChinaumsSdkException('银联商务接口路径必须以 / 开头');
        }

        return $this->gatewayUrl() . $path;
    }

    /**
     * 生成可固定测试的 OPEN-BODY-SIG 请求头。
     */
    public function bodyAuthorization(
        string $body,
        ?string $timestamp = null,
        ?string $nonce = null
    ): string {
        $timestamp = $timestamp ?? date('YmdHis');
        $nonce = $nonce ?? $this->nonce();
        $this->assertTimestampAndNonce($timestamp, $nonce);

        return sprintf(
            'OPEN-BODY-SIG AppId="%s", Timestamp="%s", Nonce="%s", Signature="%s"',
            $this->configText('app_id'),
            $timestamp,
            $nonce,
            $this->signature($timestamp, $nonce, $body)
        );
    }

    /**
     * 计算通知签名。仅接受官方声明的 MD5/SHA256。
     *
     * @param array<string, mixed> $payload
     */
    public function notifySignature(array $payload, ?string $algorithm = null): string
    {
        $algorithm = strtoupper(trim($algorithm ?? (string) ($payload['signType'] ?? 'SHA256')));
        if (!in_array($algorithm, ['MD5', 'SHA256'], true)) {
            throw new ChinaumsSdkException('银联商务通知 signType 仅支持 MD5 或 SHA256');
        }

        unset($payload['sign']);
        ksort($payload, SORT_STRING);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new ChinaumsSdkException('银联商务通知签名字段必须为标量：' . (string) $key);
            }
            $pieces[] = (string) $key . '=' . (string) $value;
        }

        $content = implode('&', $pieces) . $this->configText('communication_key');

        return strtoupper($algorithm === 'SHA256' ? hash('sha256', $content) : md5($content));
    }

    /**
     * 校验渠道通知签名。
     *
     * @param array<string, mixed> $payload
     */
    public function verifyNotify(array $payload): bool
    {
        $sign = strtoupper(trim((string) ($payload['sign'] ?? '')));
        if ($sign === '') {
            return false;
        }

        try {
            return hash_equals($this->notifySignature($payload), $sign);
        } catch (ChinaumsSdkException) {
            return false;
        }
    }

    /**
     * 生成开放平台 HMAC-SHA256 签名。
     */
    public function signature(string $timestamp, string $nonce, string $body): string
    {
        $content = $this->configText('app_id') . $timestamp . $nonce . hash('sha256', $body);

        return base64_encode(hash_hmac('sha256', $content, $this->configText('app_key'), true));
    }

    /**
     * 获取当前网关。
     */
    public function gatewayUrl(): string
    {
        $custom = $this->configText('api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return (bool) ($this->config['sandbox'] ?? false) ? self::TEST_GATEWAY : self::PROD_GATEWAY;
    }

    /**
     * 编码 JSON 数据。
     *
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value, string $error): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new ChinaumsSdkException($error);
        }

        return $json;
    }

    private function nonce(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new ChinaumsSdkException('银联商务随机数生成失败', 0, $e);
        }
    }

    private function assertTimestampAndNonce(string $timestamp, string $nonce): void
    {
        if (preg_match('/^\d{14}$/', $timestamp) !== 1 || trim($nonce) === '') {
            throw new ChinaumsSdkException('银联商务签名时间戳或随机数格式无效');
        }
    }

    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
