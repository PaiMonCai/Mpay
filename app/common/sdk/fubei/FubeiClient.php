<?php

declare(strict_types=1);

namespace app\common\sdk\fubei;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * 付呗开放接口轻量客户端。
 *
 * 封装公共参数、MD5 签名、JSON 请求和回调验签。
 */
class FubeiClient
{
    private const VERSION = '1.0';
    private const FORMAT = 'json';
    private const SIGN_METHOD = 'md5';

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
     * 发起接口请求。
     *
     * @param string $method 付呗接口方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $method, array $bizContent): array
    {
        $method = trim($method);
        if ($method === '') {
            throw new FubeiSdkException('付呗接口方法名不能为空');
        }

        $credential = $this->credential();
        try {
            $bizJson = json_encode(
                $bizContent,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new FubeiSdkException('付呗业务参数编码失败', 0, $e, ['method' => $method]);
        }

        $payload = [
            $credential['field'] => $credential['value'],
            'method' => $method,
            'format' => self::FORMAT,
            'sign_method' => self::SIGN_METHOD,
            'nonce' => bin2hex(random_bytes(6)),
            'version' => self::VERSION,
            'biz_content' => $bizJson,
        ];
        $payload['sign'] = $this->sign($payload);

        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new FubeiSdkException('付呗请求报文编码失败', 0, $e, ['method' => $method]);
        }

        try {
            $response = $this->httpClient->post($this->gatewayUrl(), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            throw new FubeiSdkException('付呗网关请求失败：' . $e->getMessage(), 0, $e, [
                'method' => $method,
            ]);
        }

        $statusCode = $response->getStatusCode();
        $responseText = (string) $response->getBody();
        $decoded = json_decode($responseText, true);
        if (!is_array($decoded)) {
            throw new FubeiSdkException('付呗响应不是合法 JSON', 0, null, [
                'method' => $method,
                'http_status' => $statusCode,
                'response_length' => strlen($responseText),
            ]);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new FubeiSdkException('付呗网关 HTTP 状态异常', $statusCode, null, [
                'method' => $method,
                'http_status' => $statusCode,
                'result_code' => (string) ($decoded['result_code'] ?? ''),
            ]);
        }

        if ((int) ($decoded['result_code'] ?? 0) === 200) {
            $data = $decoded['data'] ?? [];
            if (!is_array($data)) {
                throw new FubeiSdkException('付呗成功响应 data 不是对象', 0, null, [
                    'method' => $method,
                    'result_code' => '200',
                    'data_type' => get_debug_type($data),
                ]);
            }

            return $data;
        }

        throw new FubeiSdkException(
            trim((string) ($decoded['result_message'] ?? '')) ?: '付呗请求失败',
            0,
            null,
            [
                'method' => $method,
                'http_status' => $statusCode,
                'result_code' => (string) ($decoded['result_code'] ?? ''),
                'sub_code' => (string) ($decoded['sub_code'] ?? ''),
            ]
        );
    }

    /**
     * 验证回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = strtoupper(trim((string) ($payload['sign'] ?? '')));

        return $sign !== '' && hash_equals($this->sign($payload), $sign);
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function sign(array $payload): string
    {
        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key === 'sign' || $value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new FubeiSdkException('付呗签名参数必须是标量', 0, null, [
                    'field' => (string) $key,
                    'type' => get_debug_type($value),
                ]);
            }
            $pieces[] = $key . '=' . (string) $value;
        }

        $secret = $this->configText('app_secret');
        if ($secret === '') {
            throw new FubeiSdkException('付呗接口密钥不能为空');
        }

        return strtoupper(md5(implode('&', $pieces) . $secret));
    }

    /**
     * 解析当前接入级别使用的公共凭据。
     *
     * 当前官方协议规定服务商使用 vendor_sn，商户直连使用 app_id，二者不能同时发送。
     *
     * @return array{field:string,value:string}
     */
    private function credential(): array
    {
        $vendorSn = $this->configText('vendor_sn');
        if ($vendorSn !== '') {
            return ['field' => 'vendor_sn', 'value' => $vendorSn];
        }

        $appId = $this->configText('app_id');
        if ($appId !== '') {
            return ['field' => 'app_id', 'value' => $appId];
        }

        throw new FubeiSdkException('付呗 vendor_sn 与 app_id 至少配置一项');
    }

    /**
     * 获取网关地址。
     */
    private function gatewayUrl(): string
    {
        $gateway = $this->configText('api_gateway');
        if ($gateway === '') {
            throw new FubeiSdkException('付呗网关地址不能为空，请使用付呗当前分配的 HTTPS 网关');
        }
        if (!str_starts_with(strtolower($gateway), 'https://')) {
            throw new FubeiSdkException('付呗网关地址必须使用 HTTPS');
        }

        return $gateway;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
