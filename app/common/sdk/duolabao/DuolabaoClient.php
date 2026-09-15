<?php

declare(strict_types=1);

namespace app\common\sdk\duolabao;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * 哆啦宝开放平台轻量客户端。
 *
 * 封装多啦宝 JSON 请求、SHA1 令牌和回调验签协议。
 */
class DuolabaoClient
{
    private const GATEWAY = 'https://openapi.duolabao.com';
    private const RAINBOW_LEGACY_PATHS = [
        '/api/generateQRCodeUrl',
        '/api/createPayWithCheck',
        '/api/refundByRequestNum',
    ];

    /**
     * SDK 配置。
     *
     * @var array<string, string>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private ClientInterface $httpClient;

    private Closure $timestampFactory;

    /**
     * 构造方法。
     *
     * @param array<string, string> $config SDK 配置
     */
    public function __construct(
        array $config,
        ?ClientInterface $httpClient = null,
        ?Closure $timestampFactory = null
    )
    {
        $this->config = $config;
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
        $this->timestampFactory = $timestampFactory ?? static fn (): string => (string) time();
    }

    /**
     * 发起开放平台 JSON 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        if (!in_array($path, self::RAINBOW_LEGACY_PATHS, true)) {
            throw new DuolabaoSdkException('哆啦宝 rainbow_legacy 请求路径未核验');
        }
        try {
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new DuolabaoSdkException('哆啦宝请求参数编码失败', false, '', $e);
        }

        $timestamp = (string) ($this->timestampFactory)();
        if (preg_match('/^\d{1,20}$/', $timestamp) !== 1) {
            throw new DuolabaoSdkException('哆啦宝请求时间戳无效');
        }
        try {
            $response = $this->httpClient->request('POST', self::GATEWAY . $path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'accessKey' => (string) ($this->config['access_key'] ?? ''),
                    'timestamp' => $timestamp,
                    'token' => $this->createToken($timestamp, $path, $body),
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new DuolabaoSdkException('哆啦宝网关通信失败', true, '', $e);
        }

        $statusCode = $response->getStatusCode();
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DuolabaoSdkException(
                '哆啦宝响应不是合法 JSON',
                $statusCode < 400 || $statusCode >= 500,
                '',
                $e
            );
        }
        if (!is_array($data)) {
            throw new DuolabaoSdkException('哆啦宝响应结构无效', $statusCode < 400 || $statusCode >= 500);
        }
        if ($statusCode >= 500) {
            throw new DuolabaoSdkException('哆啦宝网关服务异常', true, (string) $statusCode);
        }
        if ($statusCode >= 300) {
            throw new DuolabaoSdkException(
                $this->responseMessage($data, '哆啦宝请求被拒绝'),
                false,
                $this->responseErrorCode($data, (string) $statusCode)
            );
        }
        if (($data['success'] ?? null) !== true && ($data['result'] ?? null) !== true) {
            throw new DuolabaoSdkException(
                $this->responseMessage($data, '哆啦宝请求失败'),
                false,
                $this->responseErrorCode($data)
            );
        }

        return $data;
    }

    /**
     * 校验哆啦宝异步通知签名。
     */
    public function verifyNotify(string $body, string $timestamp, string $token): bool
    {
        if ($body === '' || preg_match('/^\d{1,20}$/', $timestamp) !== 1
            || preg_match('/^[A-F0-9]{40}$/', $token) !== 1) {
            return false;
        }

        return hash_equals($this->createToken($timestamp, '', $body), $token);
    }

    /**
     * 生成请求令牌。
     */
    public function createToken(string $timestamp, string $path = '', string $body = ''): string
    {
        $pieces = [
            'secretKey=' . (string) ($this->config['secret_key'] ?? ''),
            'timestamp=' . $timestamp,
        ];
        if ($path !== '') {
            $pieces[] = 'path=' . $path;
        }
        if ($body !== '') {
            $pieces[] = 'body=' . $body;
        }

        return strtoupper(sha1(implode('&', $pieces)));
    }

    /**
     * 提取 rainbow_legacy 合同的公开错误信息。
     *
     * @param array<string, mixed> $data
     */
    private function responseMessage(array $data, string $fallback): string
    {
        $message = isset($data['errorCode'])
            ? (string) ($data['errorMsg'] ?? $fallback)
            : (string) ($data['msg'] ?? $data['message'] ?? $fallback);
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($message)) ?: $fallback;

        return mb_strcut($message, 0, 160, 'UTF-8');
    }

    /**
     * 读取上游响应错误码。
     *
     * @param array<string, mixed> $data
     */
    private function responseErrorCode(array $data, string $fallback = ''): string
    {
        $code = isset($data['errorCode']) ? (string) $data['errorCode'] : (string) ($data['code'] ?? $fallback);

        return mb_strcut(trim($code), 0, 64, 'UTF-8');
    }
}
