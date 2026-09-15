<?php

declare(strict_types=1);

namespace app\common\sdk\zhangyishou;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * 掌易收支付轻量客户端。
 */
class ZhangyishouClient
{
    private const ADD_ORDER_URL = 'https://apipay.zhangyishou.com/api/Order/AddOrder';
    private const REFUND_URL = 'https://apipay.zhangyishou.com/api/OrderRefund/Refund';
    private const SUCCESS_CODE = '1009';

    private Client $httpClient;

    /**
     * 构造掌易收 API 客户端。
     *
     * @param array<string, mixed> $config SDK 配置
     */
    public function __construct(private array $config)
    {
        $this->httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 创建支付订单。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function addOrder(array $params): array
    {
        $result = $this->post(self::ADD_ORDER_URL, $this->buildAddOrderPayload($params));
        $info = $result['Info'] ?? null;
        if (!is_string($info) || trim($info) === '') {
            throw new ZhangyishouSdkException('掌易收下单成功响应缺少字符串 Info', true);
        }

        $result['Info'] = trim($info);

        return $result;
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function refund(array $params): array
    {
        $result = $this->post(self::REFUND_URL, $this->buildRefundPayload($params));
        if (array_key_exists('RefundNo', $result) && !is_scalar($result['RefundNo'])) {
            throw new ZhangyishouSdkException('掌易收退款响应 RefundNo 类型无效', true);
        }

        return $result;
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $signature = $payload['Signature'] ?? null;
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        try {
            return hash_equals($this->notifySignature($payload), $signature);
        } catch (ZhangyishouSdkException) {
            return false;
        }
    }

    /**
     * 按 `rainbow_legacy` 顺序构造 AddOrder 报文。
     *
     * `MerchantNo`、`Mproductdesc` 和 `ReturnUrl` 在 rainbow_legacy 档案中均不参与签名。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, string>
     */
    private function buildAddOrderPayload(array $params): array
    {
        $signed = [
            'MerchantId' => $this->requiredText($params, 'MerchantId'),
            'DownstreamOrderNo' => $this->requiredText($params, 'DownstreamOrderNo'),
            'OrderTime' => $this->requiredText($params, 'OrderTime'),
            'PayChannelId' => $this->requiredText($params, 'PayChannelId'),
            'AsynPath' => $this->requiredText($params, 'AsynPath'),
            'OrderMoney' => $this->requiredText($params, 'OrderMoney'),
            'IPPath' => $this->requiredText($params, 'IPPath'),
        ];

        $payload = [
            ...$signed,
            'MD5Sign' => $this->signAddOrder($signed),
            'MerchantNo' => $this->configTextRequired('merchant_no'),
            'Mproductdesc' => $this->optionalText($params, 'Mproductdesc'),
        ];
        $returnUrl = $this->optionalText($params, 'ReturnUrl');
        if ($returnUrl !== '') {
            $payload['ReturnUrl'] = $returnUrl;
        }

        return $payload;
    }

    /**
     * 按 `rainbow_legacy` 顺序构造退款报文。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, string>
     */
    private function buildRefundPayload(array $params): array
    {
        $signed = [
            'MerchantId' => $this->requiredText($params, 'MerchantId'),
            'MerchantOrder' => $this->requiredText($params, 'MerchantOrder'),
            'RefundAmount' => $this->requiredText($params, 'RefundAmount'),
        ];

        return [
            ...$signed,
            'MD5Sign' => $this->signRefund($signed),
        ];
    }

    /**
     * 提交 JSON 请求。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    private function post(string $url, array $params): array
    {
        try {
            $body = json_encode(
                $params,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new ZhangyishouSdkException('掌易收请求 JSON 编码失败', false, $e);
        }

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new ZhangyishouSdkException('掌易收请求通信失败', true, $e);
        }

        $statusCode = $response->getStatusCode();
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ZhangyishouSdkException(
                '掌易收响应不是合法 JSON',
                $this->isAmbiguousHttpStatus($statusCode),
                $e
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ZhangyishouSdkException(
                '掌易收响应不是 JSON object',
                $this->isAmbiguousHttpStatus($statusCode)
            );
        }

        $code = isset($decoded['Code']) && is_scalar($decoded['Code'])
            ? trim((string) $decoded['Code'])
            : '';
        if ($code === '') {
            throw new ZhangyishouSdkException(
                '掌易收响应缺少业务 Code',
                $this->isAmbiguousHttpStatus($statusCode)
            );
        }
        if ($code !== self::SUCCESS_CODE) {
            throw new ZhangyishouSdkException(
                sprintf('掌易收业务拒绝[%s]：%s', $code, $this->responseMessage($decoded))
            );
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ZhangyishouSdkException('掌易收业务成功码与 HTTP 状态冲突', true);
        }

        return $decoded;
    }

    /**
     * 生成 AddOrder 固定顺序签名。
     *
     * @param array<string, mixed> $params 待签名参数
     */
    private function signAddOrder(array $params): string
    {
        return md5(
            $this->requiredText($params, 'MerchantId')
            . $this->requiredText($params, 'DownstreamOrderNo')
            . $this->requiredText($params, 'OrderTime')
            . $this->requiredText($params, 'PayChannelId')
            . $this->requiredText($params, 'AsynPath')
            . $this->requiredText($params, 'OrderMoney')
            . $this->requiredText($params, 'IPPath')
            . $this->configTextRequired('api_key')
        );
    }

    /**
     * 生成退款固定顺序签名。
     *
     * @param array<string, mixed> $params 待签名参数
     */
    private function signRefund(array $params): string
    {
        return md5(
            $this->requiredText($params, 'MerchantId')
            . $this->requiredText($params, 'MerchantOrder')
            . $this->requiredText($params, 'RefundAmount')
            . $this->configTextRequired('api_key')
        );
    }

    /**
     * 生成支付通知固定顺序签名。
     *
     * @param array<string, mixed> $payload 通知参数
     */
    private function notifySignature(array $payload): string
    {
        return md5(
            $this->requiredText($payload, 'MerchantId')
            . $this->requiredText($payload, 'DownstreamOrderNo')
            . $this->configTextRequired('api_key')
        );
    }

    /**
     * 读取必填业务字段。
     *
     * @param array<string, mixed> $params 业务参数
     */
    private function requiredText(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new ZhangyishouSdkException('掌易收请求缺少字段：' . $key);
        }

        return trim((string) $value);
    }

    /**
     * 读取可选业务字段。
     *
     * @param array<string, mixed> $params 业务参数
     */
    private function optionalText(array $params, string $key): string
    {
        $value = $params[$key] ?? '';
        if (!is_scalar($value)) {
            throw new ZhangyishouSdkException('掌易收请求字段类型无效：' . $key);
        }

        return trim((string) $value);
    }

    /**
     * 判断异常 HTTP 状态下是否可能已受理请求。
     */
    private function isAmbiguousHttpStatus(int $statusCode): bool
    {
        if ($statusCode === 408 || $statusCode === 429 || $statusCode >= 500) {
            return true;
        }

        return $statusCode < 400 || $statusCode >= 500;
    }

    /**
     * 读取不含完整响应的业务错误摘要。
     *
     * @param array<string, mixed> $decoded 响应对象
     */
    private function responseMessage(array $decoded): string
    {
        $message = $decoded['Message'] ?? '';
        if (!is_scalar($message) || trim((string) $message) === '') {
            return '请求被拒绝';
        }

        return mb_strcut(trim((string) $message), 0, 200, 'UTF-8');
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }

    /**
     * 读取必填 SDK 配置。
     */
    private function configTextRequired(string $key): string
    {
        $value = $this->configText($key);
        if ($value === '') {
            throw new ZhangyishouSdkException('掌易收 SDK 缺少配置：' . $key);
        }

        return $value;
    }
}
