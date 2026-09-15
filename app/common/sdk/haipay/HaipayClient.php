<?php

declare(strict_types=1);

namespace app\common\sdk\haipay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * 海科融通 SaaS V2 支付客户端。
 *
 * 生产网关固定使用 TLS。机构文档中的 HTTP 地址只允许在显式 sandbox 配置下使用，
 * 避免测试地址或旧文档入口被误当成生产回退网关。
 */
class HaipayClient
{
    private const PROD_GATEWAY = 'https://saas-front.hkrt.cn';

    private const PATHS = [
        '/api/v2/pay/pre-pay',
        '/api/v2/pay/passive-pay',
        '/api/v2/pay/order-query',
        '/api/v2/pay/close-order',
        '/api/v2/pay/refund',
    ];

    /** @var array<string, mixed> */
    private array $config;

    private Client $httpClient;

    private string $gateway;

    /**
     * 构造海科融通 API 客户端。
     *
     * @param array<string, mixed> $config SDK 配置
     */
    public function __construct(array $config)
    {
        $accessId = trim((string) ($config['access_id'] ?? ''));
        $accessKey = trim((string) ($config['access_key'] ?? ''));
        if ($accessId === '' || $accessKey === '') {
            throw new HaipaySdkException('海科融通 SDK 凭证配置不完整', false, 'INVALID_CONFIG');
        }

        $sandbox = filter_var($config['sandbox'] ?? false, FILTER_VALIDATE_BOOL);
        $gateway = self::PROD_GATEWAY;
        if ($sandbox) {
            $gateway = trim((string) ($config['sandbox_gateway'] ?? ''));
            if ($gateway === '') {
                throw new HaipaySdkException(
                    '海科融通 sandbox 必须显式配置测试网关',
                    false,
                    'INVALID_SANDBOX_GATEWAY'
                );
            }
            $this->assertGateway($gateway, true);
        } else {
            $this->assertGateway($gateway, false);
        }

        $this->config = [
            'access_id' => $accessId,
            'access_key' => $accessKey,
            'sandbox' => $sandbox,
        ];
        $this->gateway = rtrim($gateway, '/');
        $this->httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 发起已知支付接口请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        if (!in_array($path, self::PATHS, true)) {
            throw new HaipaySdkException('海科融通接口路径不在允许列表', false, 'INVALID_PATH');
        }

        $payload['accessid'] = $this->config['access_id'];
        $payload['req_id'] = date('YmdHis') . strtoupper(bin2hex(random_bytes(8)));
        $payload['sign'] = $this->sign($payload);

        try {
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new HaipaySdkException('海科融通请求 JSON 编码失败', false, 'INVALID_JSON', $e);
        }

        try {
            $response = $this->httpClient->post($this->gateway . $path, [
                'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
                'body' => $body,
            ]);
        } catch (GuzzleException) {
            // Guzzle 异常可能持有包含签名或付款码的请求对象，不能挂入公开异常链。
            throw new HaipaySdkException('海科融通网关通信结果不确定', true, 'TRANSPORT_ERROR');
        }

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HaipaySdkException('海科融通响应 JSON 无法确认', true, 'INVALID_RESPONSE_JSON', $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new HaipaySdkException('海科融通响应结构无法确认', true, 'INVALID_RESPONSE');
        }
        if (!$this->verify($data)) {
            throw new HaipaySdkException('海科融通响应验签失败', true, 'INVALID_RESPONSE_SIGN');
        }

        $resultCode = trim((string) ($data['result_code'] ?? ''));
        if ($resultCode !== '10000') {
            throw new HaipaySdkException(
                '海科融通明确拒绝本次请求',
                false,
                $resultCode !== '' ? $resultCode : 'MISSING_RESULT_CODE'
            );
        }

        return $data;
    }

    /**
     * 校验请求、响应或通知签名。
     *
     * @param array<string, mixed> $payload 协议参数
     */
    public function verify(array $payload): bool
    {
        $sign = trim((string) ($payload['sign'] ?? ''));
        if (preg_match('/^[A-F0-9]{32}$/D', $sign) !== 1) {
            return false;
        }

        return hash_equals($this->sign($payload), $sign);
    }

    /**
     * 生成 32 位大写 MD5 签名。
     *
     * @param array<string, mixed> $payload 协议参数
     */
    public function sign(array $payload): string
    {
        return strtoupper(md5($this->signatureContent($payload) . $this->config['access_key']));
    }

    /**
     * 构造递归签名原文。
     *
     * 对象键递归排序；列表保留原顺序且不引入 0、1 等索引名；sign、null、空字符串
     * 和空数组不参与签名。这与机构文档给出的嵌套对象、对象列表和标量列表示例一致。
     *
     * @param array<string, mixed>|mixed $value 协议值
     */
    public function signatureContent(mixed $value): string
    {
        if (!is_array($value)) {
            return (string) $value;
        }
        if ($value === []) {
            return '';
        }
        if (array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                if ($this->isEmptyValue($item)) {
                    continue;
                }
                $items[] = $this->signatureContent($item);
            }

            return implode('&', $items);
        }

        ksort($value, SORT_STRING);
        $pieces = [];
        foreach ($value as $key => $item) {
            if ((string) $key === 'sign' || $this->isEmptyValue($item)) {
                continue;
            }
            $pieces[] = (string) $key . '=' . $this->signatureContent($item);
        }

        return implode('&', $pieces);
    }

    /**
     * 获取本客户端实际使用的网关，供环境边界测试与诊断使用。
     */
    public function gateway(): string
    {
        return $this->gateway;
    }

    private function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private function assertGateway(string $gateway, bool $sandbox): void
    {
        $parts = parse_url($gateway);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            throw new HaipaySdkException('海科融通网关地址无效', false, 'INVALID_GATEWAY');
        }
        if (!$sandbox && strtolower((string) $parts['scheme']) !== 'https') {
            throw new HaipaySdkException('海科融通生产网关必须使用 HTTPS', false, 'INSECURE_GATEWAY');
        }
    }
}
