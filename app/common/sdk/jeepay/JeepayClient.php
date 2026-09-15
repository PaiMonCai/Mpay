<?php

declare(strict_types=1);

namespace app\common\sdk\jeepay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Jeepay 聚合支付轻量客户端。
 */
class JeepayClient
{
    /**
     * SDK 配置。
     *
     * @var array<string, string>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private Client $httpClient;

    /**
     * 构造方法。
     *
     * @param array<string, string> $config SDK 配置
     */
    public function __construct(array $config, ?Client $httpClient = null)
    {
        $apiUrl = rtrim(trim((string) ($config['api_url'] ?? '')), '/');
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $parts = parse_url($apiUrl);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new JeepaySdkException('Jeepay接口地址必须是无凭证、无查询参数的 HTTPS 地址');
        }
        if ($apiKey === '') {
            throw new JeepaySdkException('Jeepay接口密钥不能为空');
        }

        $this->config = ['api_url' => $apiUrl, 'api_key' => $apiKey];
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 发起 Jeepay JSON 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post(rtrim($this->config['api_url'], '/') . '/' . ltrim($path, '/'), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new JeepaySdkException('Jeepay网关请求失败：' . $e->getMessage(), true, '', $e);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new JeepaySdkException('Jeepay网关返回非成功 HTTP 状态', true);
        }
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new JeepaySdkException('Jeepay响应不是合法 JSON', true);
        }
        if ((string) ($data['code'] ?? '') !== '0') {
            throw new JeepaySdkException(
                trim((string) ($data['msg'] ?? '')) ?: 'Jeepay请求失败',
                false,
                trim((string) ($data['code'] ?? ''))
            );
        }

        $businessData = $data['data'] ?? null;
        if (!is_array($businessData)) {
            throw new JeepaySdkException('Jeepay成功响应缺少 data 对象', true);
        }
        $responseSign = trim((string) ($data['sign'] ?? ''));
        $signaturePayload = $businessData;
        $signaturePayload['sign'] = $responseSign;
        if ($responseSign === '' || !$this->verify($signaturePayload)) {
            throw new JeepaySdkException('Jeepay成功响应验签失败', true);
        }

        $errorCode = trim((string) ($businessData['errCode'] ?? ''));
        $errorMessage = trim((string) ($businessData['errMsg'] ?? ''));
        if ($errorCode !== '' || $errorMessage !== '') {
            throw new JeepaySdkException(
                $errorMessage !== '' ? $errorMessage : 'Jeepay渠道业务处理失败',
                false,
                $errorCode
            );
        }

        return $businessData;
    }

    /**
     * 校验 Jeepay 回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = strtoupper(trim((string) ($payload['sign'] ?? '')));
        if ($sign === '') {
            return false;
        }

        try {
            return hash_equals($this->sign($payload), $sign);
        } catch (JeepaySdkException) {
            return false;
        }
    }

    /**
     * 生成 MD5 签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function sign(array $payload): string
    {
        ksort($payload, SORT_STRING);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key === 'sign' || $value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new JeepaySdkException('Jeepay签名字段必须是标量：' . $key);
            }
            $pieces[] = $key . '=' . (string) $value;
        }

        return strtoupper(md5(implode('&', $pieces) . '&key=' . $this->config['api_key']));
    }
}
