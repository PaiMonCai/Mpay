<?php

declare(strict_types=1);

namespace app\common\sdk\allinpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 通联收银宝轻量客户端。
 *
 * 协议事实源：通联收银宝公用接口规范。请求与成功响应都使用
 * RSA(SHA1WithRSA)，对 sign 之外的全部非空字段按字段名 ASCII 升序拼接。
 */
class AllinpayClient
{
    public const SIGN_TYPE = 'RSA';
    public const VERSION = '11';
    public const CASHIER_VERSION = '12';

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
     * 发起 application/x-www-form-urlencoded 请求并严格验证成功响应。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function submit(string $url, array $params, string $version = self::VERSION): array
    {
        $payload = $this->buildSignedPayload($params, $version);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ],
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new AllinpaySdkException('通联网关请求失败：' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new AllinpaySdkException('通联网关 HTTP 状态异常：' . $response->getStatusCode());
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new AllinpaySdkException('通联响应不是合法 JSON');
        }
        $retcode = $data['retcode'] ?? null;
        if (!is_scalar($retcode) || (string) $retcode !== 'SUCCESS') {
            $message = $data['retmsg'] ?? '通联请求失败';
            throw new AllinpaySdkException(is_scalar($message) ? (string) $message : '通联请求失败');
        }
        if (!$this->verify($data)) {
            throw new AllinpaySdkException('通联响应验签失败');
        }

        $this->assertResponseIdentity($data);

        return $data;
    }

    /**
     * 构造 H5 收银台 version=12 的 POST 参数。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function cashierPayload(array $params): array
    {
        return $this->buildSignedPayload($params, self::CASHIER_VERSION);
    }

    /**
     * 构造公共字段齐全的签名请求。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function buildSignedPayload(array $params, string $version = self::VERSION): array
    {
        $payload = array_merge($params, [
            'appid' => $this->configText('app_id'),
            'cusid' => $this->configText('merchant_no'),
            'version' => $version,
            'randomstr' => bin2hex(random_bytes(8)),
            'signtype' => self::SIGN_TYPE,
        ]);
        $payload['sign'] = $this->sign($this->signingContent($payload));

        return $payload;
    }

    /**
     * 验证通联接口响应或回调签名。
     *
     * @param array<string, mixed> $payload 完整响应或通知参数
     */
    public function verify(array $payload): bool
    {
        $signValue = $payload['sign'] ?? null;
        if (!is_scalar($signValue)) {
            return false;
        }
        $sign = trim((string) $signValue);
        if ($sign === '') {
            return false;
        }

        $signTypeValue = $payload['signtype'] ?? self::SIGN_TYPE;
        if (!is_scalar($signTypeValue)) {
            return false;
        }
        $signType = strtoupper(trim((string) $signTypeValue));
        if ($signType !== self::SIGN_TYPE) {
            return false;
        }

        $signature = base64_decode($sign, true);
        if ($signature === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->pemKey($this->configText('platform_public_key'), 'public'));
        if ($publicKey === false) {
            throw new AllinpaySdkException('通联平台公钥不正确');
        }

        return openssl_verify(
            $this->signingContent($payload),
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA1
        ) === 1;
    }

    /**
     * 生成通联 RSA 待签名原文，供固定向量测试与问题定位使用。
     *
     * @param array<string, mixed> $payload 请求、响应或回调参数
     */
    public function signingContent(array $payload): string
    {
        unset($payload['sign']);
        ksort($payload, SORT_STRING);

        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new AllinpaySdkException('通联签名字段必须是标量：' . (string) $key);
            }
            $pieces[] = (string) $key . '=' . (string) $value;
        }

        return implode('&', $pieces);
    }

    /**
     * 商户私钥签名。
     */
    private function sign(string $content): string
    {
        $privateKey = openssl_pkey_get_private($this->pemKey($this->configText('merchant_private_key'), 'private'));
        if ($privateKey === false) {
            throw new AllinpaySdkException('通联商户私钥不正确');
        }

        $signature = '';
        if (!openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new AllinpaySdkException('通联请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 校验渠道响应身份。
     *
     * @param array<string, mixed> $data
     */
    private function assertResponseIdentity(array $data): void
    {
        $cusid = $data['cusid'] ?? null;
        if (!is_scalar($cusid) || trim((string) $cusid) !== $this->configText('merchant_no')) {
            throw new AllinpaySdkException('通联响应商户号不匹配');
        }
        $appid = $data['appid'] ?? null;
        if (!is_scalar($appid) || trim((string) $appid) !== $this->configText('app_id')) {
            throw new AllinpaySdkException('通联响应应用ID不匹配');
        }
    }

    /**
     * 规范化未携带 PEM 头的密钥配置。
     */
    private function pemKey(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $header = $type === 'private' ? 'RSA PRIVATE KEY' : 'PUBLIC KEY';

        return "-----BEGIN {$header}-----\n"
            . wordwrap(str_replace(["\r", "\n"], '', $key), 64, "\n", true)
            . "\n-----END {$header}-----";
    }

    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
