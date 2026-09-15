<?php

declare(strict_types=1);

namespace app\common\sdk\easypay;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * 易生易企通 RSA2 客户端。
 */
class EasypayClient
{
    private const PROD_GATEWAY = 'https://phoenix.eycard.cn/yqt';
    private const TEST_GATEWAY = 'https://d-phoenix-gap.easypay.com.cn:24443/yqt';

    /** @var array<string, mixed> */
    private array $config;

    private ClientInterface $httpClient;

    /**
     * 构造易生 API 客户端。
     *
     * @param array<string, mixed> $config SDK 配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->assertConfiguration();
        $configuredClient = $config['http_client'] ?? null;
        $this->httpClient = $configuredClient instanceof ClientInterface ? $configuredClient : new Client([
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 发起易企通接口请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $body 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $path, array $body): array
    {
        $requestJson = $this->encodeJson($this->requestPayload($body));
        try {
            $response = $this->httpClient->post(
                ($this->config['sandbox'] ? self::TEST_GATEWAY : self::PROD_GATEWAY) . $path,
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json; charset=UTF-8',
                    ],
                    'body' => $requestJson,
                ]
            );
        } catch (GuzzleException $e) {
            throw new EasypaySdkException('易生网关请求失败：' . $e->getMessage(), true, 0, $e);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new EasypaySdkException('易生网关 HTTP 状态异常：' . $response->getStatusCode(), true);
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EasypaySdkException('易生响应不是合法 JSON', true, 0, $e);
        }
        if (!is_array($data)
            || !is_array($data['rspHeader'] ?? null)
            || !is_array($data['rspBody'] ?? null)
            || trim((string) ($data['rspSign'] ?? '')) === '') {
            throw new EasypaySdkException('易生响应缺少可验签的应答头、应答体或签名', true);
        }

        $rspHeader = $data['rspHeader'];
        $rspBody = $data['rspBody'];
        if (!$this->verify($rspHeader, $rspBody, (string) $data['rspSign'])) {
            throw new EasypaySdkException('易生响应验签失败', true);
        }

        $responseCode = trim((string) ($rspHeader['rspCode'] ?? ''));
        if ($responseCode !== '000000') {
            $message = '[' . $responseCode . ']' . trim((string) ($rspHeader['rspInfo'] ?? '易生请求失败'));
            throw new EasypaySdkException($message, $this->isUncertainResponseCode($responseCode));
        }

        return $rspBody;
    }

    /**
     * 构造可直接发送的签名请求包。
     *
     * @param array<string, mixed> $body 业务参数
     * @return array{reqBody:array<string,mixed>,reqHeader:array<string,string>,reqSign:string}
     */
    public function requestPayload(array $body, ?string $transTime = null): array
    {
        $header = [
            'transTime' => $transTime ?? date('YmdHis'),
            'reqId' => (string) $this->config['req_id'],
            'reqType' => (string) $this->config['req_type'],
        ];
        $certificateId = trim((string) ($this->config['certificate_id'] ?? ''));
        if ($certificateId !== '') {
            $header['certificateId'] = $certificateId;
        }

        return [
            'reqBody' => $body,
            'reqHeader' => $header,
            'reqSign' => $this->sign($header, $body),
        ];
    }

    /**
     * 校验易生应答或通知签名。
     *
     * @param array<string, mixed> $header 应答头
     * @param array<string, mixed> $body 应答体
     */
    public function verify(array $header, array $body, string $sign): bool
    {
        $signature = base64_decode(trim($sign), true);
        if ($signature === false || $signature === '') {
            return false;
        }

        $publicKey = $this->publicKey((string) $this->config['platform_public_key']);
        if ($publicKey === false) {
            throw new EasypaySdkException('易生公钥或公钥证书不正确');
        }

        return openssl_verify($this->signContent($header, $body), $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 使用商户私钥生成 RSA-SHA256 签名。
     *
     * @param array<string, mixed> $header 请求头
     * @param array<string, mixed> $body 请求体
     */
    public function sign(array $header, array $body): string
    {
        $privateKey = $this->privateKey((string) $this->config['merchant_private_key']);
        if ($privateKey === false) {
            throw new EasypaySdkException('易生商户私钥不正确');
        }

        $signature = '';
        if (!openssl_sign($this->signContent($header, $body), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new EasypaySdkException('易生请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 构造待签名字符串。
     *
     * @param array<string, mixed> $header 请求头
     * @param array<string, mixed> $body 请求体
     */
    public function signContent(array $header, array $body): string
    {
        return $this->canonicalJson($header) . strtoupper(md5($this->canonicalJson($body)));
    }

    /**
     * 对象键递归按 ASCII 升序，列表保持原顺序。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function canonicalJson(array $payload): string
    {
        $sorted = $this->sortRecursive($payload);

        return $this->encodeJson($sorted === [] ? (object) [] : $sorted);
    }

    /**
     * 递归排序签名字段。
     *
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function sortRecursive(array $payload): array
    {
        if (!array_is_list($payload)) {
            ksort($payload, SORT_STRING);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortRecursive($value);
            }
        }

        return $payload;
    }

    /**
     * 私钥文件允许标准 PEM，也兼容仅含 Base64 正文的 PKCS#8/PKCS#1 文件。
     *
     * @return \OpenSSLAsymmetricKey|false
     */
    private function privateKey(string $key): \OpenSSLAsymmetricKey|false
    {
        $key = trim($key);
        if (str_contains($key, 'BEGIN')) {
            return openssl_pkey_get_private($key);
        }

        $body = $this->compactBase64($key);
        if ($body === '') {
            return false;
        }
        foreach (['PRIVATE KEY', 'RSA PRIVATE KEY'] as $label) {
            $resource = openssl_pkey_get_private($this->pem($body, $label));
            if ($resource !== false) {
                return $resource;
            }
        }

        return false;
    }

    /**
     * 公钥文件允许 PUBLIC KEY PEM、X.509 PEM/DER 证书及 Base64 公钥正文。
     *
     * @return \OpenSSLAsymmetricKey|false
     */
    private function publicKey(string $key): \OpenSSLAsymmetricKey|false
    {
        $trimmed = trim($key);
        if (str_contains($trimmed, 'BEGIN')) {
            return openssl_pkey_get_public($trimmed);
        }

        $body = $this->compactBase64($trimmed);
        if ($body !== '') {
            foreach (['PUBLIC KEY', 'CERTIFICATE'] as $label) {
                $resource = openssl_pkey_get_public($this->pem($body, $label));
                if ($resource !== false) {
                    return $resource;
                }
            }
        }

        if ($key !== '') {
            return openssl_pkey_get_public($this->pem(base64_encode($key), 'CERTIFICATE'));
        }

        return false;
    }

    private function compactBase64(string $value): string
    {
        $body = preg_replace('/\s+/', '', $value);
        if (!is_string($body) || $body === '' || base64_decode($body, true) === false) {
            return '';
        }

        return $body;
    }

    private function pem(string $body, string $label): string
    {
        return "-----BEGIN {$label}-----\n"
            . wordwrap($body, 64, "\n", true)
            . "\n-----END {$label}-----";
    }

    private function assertConfiguration(): void
    {
        $reqId = trim((string) ($this->config['req_id'] ?? ''));
        $reqType = trim((string) ($this->config['req_type'] ?? ''));
        if ($reqId === '' || !in_array($reqType, ['1', '2'], true)) {
            throw new EasypaySdkException('易生 req_id/req_type 配置无效');
        }
        if (trim((string) ($this->config['platform_public_key'] ?? '')) === ''
            || trim((string) ($this->config['merchant_private_key'] ?? '')) === '') {
            throw new EasypaySdkException('易生 RSA 公私钥配置不完整');
        }
    }

    private function isUncertainResponseCode(string $responseCode): bool
    {
        return in_array($responseCode, ['QT0099', '999999'], true)
            || str_starts_with($responseCode, 'QTC0');
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new EasypaySdkException('易生 JSON 编码失败：' . $e->getMessage(), false, 0, $e);
        }
    }
}
