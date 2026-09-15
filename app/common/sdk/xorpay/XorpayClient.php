<?php

declare(strict_types=1);

namespace app\common\sdk\xorpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * XorPay 轻量客户端。
 */
class XorpayClient
{
    private const BASE_URL = 'https://xorpay.com';

    private Client $httpClient;

    /**
     * 构造 XorPay API 客户端。
     *
     * @param array<string, mixed> $config SDK 配置
     */
    public function __construct(private array $config)
    {
        if ($this->configText('app_id') === '') {
            throw new XorpaySdkException('XorPay AppId 不能为空');
        }
        if ($this->configText('app_secret') === '') {
            throw new XorpaySdkException('XorPay AppSecret 不能为空');
        }

        $this->httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 扫码下单。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function pay(array $params): array
    {
        $params['sign'] = $this->paymentSign($params);

        return $this->post(
            $this->endpoint('/api/pay/', $this->configText('app_id')),
            $params,
            [
                'no_contract',
                'no_alipay_contract',
                'missing_argument',
                'app_off',
                'aid_not_exist',
                'pay_type_error',
                'sign_error',
                'order_expire',
                'alipay_api_error',
                'wechat_api_error',
                'fee_error',
            ]
        );
    }

    /**
     * 微信收银台 HTML 表单参数。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function cashierPayload(array $params): array
    {
        $params['sign'] = $this->paymentSign($params);

        return $params;
    }

    /**
     * 获取微信托管收银台固定地址。
     */
    public function cashierUrl(): string
    {
        return $this->endpoint('/api/cashier/', $this->configText('app_id'));
    }

    /**
     * 按 XorPay 平台订单号查询支付状态。
     *
     * @return array<string, mixed>
     */
    public function query(string $xorpayOrderId): array
    {
        if (trim($xorpayOrderId) === '') {
            throw new XorpaySdkException('XorPay 平台订单号不能为空');
        }

        return $this->get($this->endpoint('/api/query/', $xorpayOrderId));
    }

    /**
     * 申请退款。
     *
     * @param string $xorpayOrderId XorPay 平台订单号 aoid
     * @param string $amount 退款金额，单位元
     * @return array<string, mixed>
     */
    public function refund(string $xorpayOrderId, string $amount): array
    {
        if (trim($xorpayOrderId) === '') {
            throw new XorpaySdkException('XorPay 平台订单号不能为空');
        }

        return $this->post(
            $this->endpoint('/api/refund/', $xorpayOrderId),
            [
                'price' => $amount,
                'sign' => md5($amount . $this->configText('app_secret')),
            ],
            ['order_error', 'price_error', 'sign_error', 'alipay_api_error']
        );
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        foreach (['aoid', 'order_id', 'pay_price', 'pay_time', 'sign'] as $field) {
            if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                return false;
            }
        }
        if (preg_match('/^[a-f0-9]{32}$/D', (string) $payload['sign']) !== 1) {
            return false;
        }

        $sign = md5(
            (string) $payload['aoid']
            . (string) $payload['order_id']
            . (string) $payload['pay_price']
            . (string) $payload['pay_time']
            . $this->configText('app_secret')
        );

        return hash_equals((string) $payload['sign'], $sign);
    }

    /**
     * 按官方固定字段顺序生成小写 MD5 签名。
     *
     * return_url、more 等扩展字段不进入签名原文。
     *
     * @param array<string, mixed> $params 支付或收银台参数
     */
    private function paymentSign(array $params): string
    {
        foreach (['name', 'pay_type', 'price', 'order_id', 'notify_url'] as $field) {
            if (!array_key_exists($field, $params)) {
                throw new XorpaySdkException('XorPay 签名缺少字段：' . $field);
            }
        }

        return md5(
            (string) $params['name']
            . (string) $params['pay_type']
            . (string) $params['price']
            . (string) $params['order_id']
            . (string) $params['notify_url']
            . $this->configText('app_secret')
        );
    }

    /**
     * 提交表单请求。
     *
     * @param array<string, mixed> $params 表单参数
     * @return array<string, mixed>
     */
    private function post(string $url, array $params, array $definitiveStatuses): array
    {
        try {
            $response = $this->httpClient->post($url, [
                'form_params' => $params,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException) {
            throw new XorpaySdkException('XorPay 请求通信失败', true);
        }

        $decoded = $this->decodeJsonResponse($response);
        $status = $this->responseStatus($decoded, '请求');
        if ($status !== 'ok') {
            $message = 'XorPay 返回状态：' . $status;
            throw new XorpaySdkException(
                $message,
                !in_array($status, $definitiveStatuses, true),
                $status
            );
        }

        return $decoded;
    }

    /**
     * 发起只读 GET 请求并解析 JSON。
     *
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        try {
            $response = $this->httpClient->get($url, [
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException) {
            throw new XorpaySdkException('XorPay 查询通信失败', true);
        }

        $decoded = $this->decodeJsonResponse($response);
        $this->responseStatus($decoded, '查询');

        return $decoded;
    }

    /**
     * 解析 XorPay JSON 响应。
     *
     * 变更请求在 HTTP 状态或响应正文无法确认时保持结果不确定。
     *
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new XorpaySdkException('XorPay HTTP 状态异常：' . $statusCode, true);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new XorpaySdkException('XorPay 响应不是合法 JSON', true);
        }

        return $decoded;
    }

    /**
     * 读取不含敏感内容的受控正文状态码。
     *
     * @param array<string, mixed> $decoded JSON 响应
     */
    private function responseStatus(array $decoded, string $scene): string
    {
        $value = $decoded['status'] ?? null;
        if (!is_string($value)) {
            throw new XorpaySdkException('XorPay ' . $scene . '响应缺少状态', true);
        }

        $status = trim($value);
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $status) !== 1) {
            throw new XorpaySdkException('XorPay ' . $scene . '响应状态格式无效', true);
        }

        return $status;
    }

    /**
     * 生成固定官网地址并编码单个路径标识。
     */
    private function endpoint(string $path, string $identifier): string
    {
        return self::BASE_URL . $path . rawurlencode(trim($identifier));
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
