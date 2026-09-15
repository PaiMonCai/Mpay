<?php

declare(strict_types=1);

namespace app\common\sdk\sandpay;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Throwable;

/**
 * 杉德开放平台 V4 客户端。
 *
 * 实现 UTF-8 JSON、AES-128-ECB/PKCS#7、RSA/PKCS#1 v1.5 密钥封装与
 * RSA-SHA256 签名。客户端只返回解密后的业务对象，不暴露请求/响应密文或明文。
 */
class SandpayClient
{
    public const PATH_ORDER_CREATE = '/v4/sd-receipts/api/trans/trans.order.create';
    public const PATH_ORDER_QUERY = '/v4/sd-receipts/api/trans/trans.order.query';
    public const PATH_ORDER_REFUND = '/v4/sd-receipts/api/trans/trans.order.refund';

    private const VERSION = '4.0.0';
    private const SIGN_TYPE = 'RSA';
    private const ENCRYPT_TYPE = 'AES';
    private const PROD_GATEWAY = 'https://openapi.sandpay.com.cn';
    private const TEST_GATEWAY = 'https://openapi-uat01.sand.com.cn';
    private const MAX_CERTIFICATE_BYTES = 2 * 1024 * 1024;
    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    private const AES_KEY_BYTES = 16;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    private ClientInterface $httpClient;

    private OpenSSLAsymmetricKey $platformPublicKey;

    private OpenSSLAsymmetricKey $merchantPrivateKey;

    /**
     * 构造方法。
     *
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        $this->config = $config;
        $this->assertRequiredConfig();
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
        $this->platformPublicKey = $this->loadPlatformPublicKey();
        $this->merchantPrivateKey = $this->loadMerchantPrivateKey();
    }

    /**
     * 执行杉德接口请求。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed> 已验签、解密并校验 resultStatus 的业务响应
     */
    public function execute(string $path, array $params): array
    {
        if (!in_array($path, [self::PATH_ORDER_CREATE, self::PATH_ORDER_QUERY, self::PATH_ORDER_REFUND], true)) {
            throw new SandpaySdkException('杉德接口路径不在允许列表中');
        }

        $payload = $this->buildEncryptedPayload($params);
        try {
            $response = $this->httpClient->request('POST', $this->gatewayUrl() . $path, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new SandpaySdkException('杉德网关请求失败', 0, $e);
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new SandpaySdkException('杉德网关 HTTP 状态异常');
        }

        $responseBody = (string) $response->getBody();
        if ($responseBody === '' || strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            throw new SandpaySdkException('杉德响应正文大小无效');
        }
        try {
            $result = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SandpaySdkException('杉德响应不是合法 JSON', 0, $e);
        }
        if (!is_array($result) || array_is_list($result)) {
            throw new SandpaySdkException('杉德响应必须是 JSON 对象');
        }

        return $this->decryptResponse($result);
    }

    /**
     * 构建杉德外层加密请求，供协议固定向量测试复用。
     *
     * 返回值仅包含密文与签名，调用方不得记录。生产调用不传测试参数。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, string>
     */
    public function buildEncryptedPayload(array $params, ?string $aesKey = null, ?string $timestamp = null): array
    {
        ksort($params, SORT_STRING);
        try {
            $plain = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SandpaySdkException('杉德请求报文编码失败', 0, $e);
        }

        $timestamp ??= date('Y-m-d H:i:s');
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $timestamp) !== 1) {
            throw new SandpaySdkException('杉德请求 timestamp 格式无效');
        }
        $aesKey ??= $this->randomAesKey();
        $bizData = $this->aesEncrypt($plain, $aesKey);
        $payload = [
            'accessMid' => $this->configText('merchant_no'),
            'timestamp' => $timestamp,
            'version' => self::VERSION,
            'signType' => self::SIGN_TYPE,
            'encryptType' => self::ENCRYPT_TYPE,
            'encryptKey' => $this->rsaPublicEncrypt($aesKey),
            'bizData' => $bizData,
            'sign' => $this->rsaPrivateSign($bizData),
        ];
        $certNo = $this->configText('merchant_cert_no');
        if ($certNo !== '') {
            $payload['certNo'] = $certNo;
        }

        return $payload;
    }

    /**
     * 验证杉德以平台私钥产生的 RSA-SHA256 签名。
     */
    public function verify(string $bizData, string $sign): bool
    {
        if ($bizData === '' || $sign === '') {
            return false;
        }

        try {
            $signature = $this->strictBase64Decode($sign, '签名');
        } catch (SandpaySdkException) {
            return false;
        }

        return openssl_verify($bizData, $signature, $this->platformPublicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 解密渠道响应。
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function decryptResponse(array $result): array
    {
        $respCode = $this->scalarText($result['respCode'] ?? '');
        if ($respCode !== 'success') {
            $description = $this->safeGatewayDescription($result['respDesc'] ?? '杉德请求失败');
            throw new SandpaySdkException($description);
        }
        $this->assertEnvelopeField($result, 'accessMid', $this->configText('merchant_no'));
        $this->assertEnvelopeField($result, 'version', self::VERSION);
        $this->assertEnvelopeField($result, 'signType', self::SIGN_TYPE);
        $this->assertEnvelopeField($result, 'encryptType', self::ENCRYPT_TYPE);

        $bizData = $this->requiredEnvelopeText($result, 'bizData');
        $sign = $this->requiredEnvelopeText($result, 'sign');
        $encryptKey = $this->requiredEnvelopeText($result, 'encryptKey');
        if (!$this->verify($bizData, $sign)) {
            throw new SandpaySdkException('杉德响应验签失败');
        }

        $aesKey = $this->rsaPrivateDecrypt($encryptKey);
        $plainText = $this->aesDecrypt($bizData, $aesKey);
        try {
            $data = json_decode($plainText, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SandpaySdkException('杉德业务响应不是合法 JSON', 0, $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new SandpaySdkException('杉德业务响应必须是 JSON 对象');
        }

        $resultStatus = strtolower($this->scalarText($data['resultStatus'] ?? ''));
        if (!in_array($resultStatus, ['success', 'fail', 'process', 'accept'], true)) {
            throw new SandpaySdkException('杉德业务响应 resultStatus 无效');
        }
        if ($resultStatus === 'fail') {
            $code = preg_replace('/[^A-Za-z0-9_-]/', '', $this->scalarText($data['errorCode'] ?? '')) ?: '';
            $description = $this->safeGatewayDescription($data['errorDesc'] ?? '杉德业务失败');
            throw new SandpaySdkException(($code !== '' ? '[' . $code . ']' : '') . $description);
        }

        return $data;
    }

    private function aesEncrypt(string $data, string $key): string
    {
        $this->assertAesKey($key);
        $encrypted = openssl_encrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
        if (!is_string($encrypted)) {
            throw new SandpaySdkException('杉德 AES 加密失败');
        }

        return base64_encode($encrypted);
    }

    private function aesDecrypt(string $data, string $key): string
    {
        $this->assertAesKey($key);
        $ciphertext = $this->strictBase64Decode($data, '业务密文');
        $decrypted = openssl_decrypt($ciphertext, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
        if (!is_string($decrypted)) {
            throw new SandpaySdkException('杉德 AES 解密失败');
        }

        return $decrypted;
    }

    private function rsaPrivateSign(string $data): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->merchantPrivateKey, OPENSSL_ALGO_SHA256)) {
            throw new SandpaySdkException('杉德请求签名失败');
        }

        return base64_encode($signature);
    }

    private function rsaPublicEncrypt(string $data): string
    {
        $encrypted = '';
        if (!openssl_public_encrypt($data, $encrypted, $this->platformPublicKey, OPENSSL_PKCS1_PADDING)) {
            throw new SandpaySdkException('杉德 AES 密钥加密失败');
        }

        return base64_encode($encrypted);
    }

    private function rsaPrivateDecrypt(string $data): string
    {
        $encrypted = $this->strictBase64Decode($data, '加密密钥');
        $decrypted = '';
        if (!openssl_private_decrypt($encrypted, $decrypted, $this->merchantPrivateKey, OPENSSL_PKCS1_PADDING)) {
            throw new SandpaySdkException('杉德 AES 密钥解密失败');
        }
        $this->assertAesKey($decrypted);

        return $decrypted;
    }

    private function loadPlatformPublicKey(): OpenSSLAsymmetricKey
    {
        $content = $this->readCertificateFile($this->configText('public_cert_path'), '杉德公钥证书');
        $certificate = $this->parseCertificate($content, '杉德公钥证书');
        $this->assertCertificateValidity($certificate, '杉德公钥证书');
        $publicKey = openssl_pkey_get_public($certificate);
        if (!$publicKey instanceof OpenSSLAsymmetricKey) {
            throw new SandpaySdkException('杉德公钥证书未包含有效公钥');
        }
        $this->assertRsaKey($publicKey, '杉德公钥证书');

        return $publicKey;
    }

    private function loadMerchantPrivateKey(): OpenSSLAsymmetricKey
    {
        $content = $this->readCertificateFile($this->configText('private_cert_path'), '杉德商户私钥证书');
        $certs = [];
        if (!openssl_pkcs12_read($content, $certs, $this->configText('private_cert_password'))) {
            throw new SandpaySdkException('杉德商户私钥证书解析失败');
        }
        $privateKey = openssl_pkey_get_private($this->scalarText($certs['pkey'] ?? ''));
        $merchantCertificate = openssl_x509_read($this->scalarText($certs['cert'] ?? ''));
        if (!$privateKey instanceof OpenSSLAsymmetricKey || !$merchantCertificate instanceof OpenSSLCertificate) {
            throw new SandpaySdkException('杉德商户 PFX 必须同时包含私钥和证书');
        }
        $this->assertRsaKey($privateKey, '杉德商户私钥证书');
        $this->assertCertificateValidity($merchantCertificate, '杉德商户私钥证书');
        if (!openssl_x509_check_private_key($merchantCertificate, $privateKey)) {
            throw new SandpaySdkException('杉德商户证书与私钥不匹配');
        }

        return $privateKey;
    }

    private function parseCertificate(string $content, string $label): OpenSSLCertificate
    {
        $certificate = openssl_x509_read($content);
        if (!$certificate instanceof OpenSSLCertificate && !str_contains($content, 'BEGIN CERTIFICATE')) {
            $pem = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($content), 64, "\n")
                . "-----END CERTIFICATE-----\n";
            $certificate = openssl_x509_read($pem);
        }
        if (!$certificate instanceof OpenSSLCertificate) {
            throw new SandpaySdkException($label . '不是有效的 X.509 证书');
        }

        return $certificate;
    }

    private function assertCertificateValidity(OpenSSLCertificate $certificate, string $label): void
    {
        $details = openssl_x509_parse($certificate, false);
        $now = time();
        if (!is_array($details)
            || !isset($details['validFrom_time_t'], $details['validTo_time_t'])
            || (int) $details['validFrom_time_t'] > $now
            || (int) $details['validTo_time_t'] < $now) {
            throw new SandpaySdkException($label . '不在有效期内');
        }
    }

    private function assertRsaKey(OpenSSLAsymmetricKey $key, string $label): void
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)
            || (int) ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA
            || (int) ($details['bits'] ?? 0) < 2048) {
            throw new SandpaySdkException($label . '必须包含至少 2048 位 RSA 密钥');
        }
    }

    private function readCertificateFile(string $path, string $label): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new SandpaySdkException($label . '读取失败');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_CERTIFICATE_BYTES) {
            throw new SandpaySdkException($label . '大小无效');
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new SandpaySdkException($label . '读取失败');
        }

        return $content;
    }

    private function strictBase64Decode(string $value, string $label): string
    {
        if ($value === ''
            || strlen($value) % 4 !== 0
            || preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $value) !== 1) {
            throw new SandpaySdkException('杉德' . $label . '不是规范 Base64');
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || base64_encode($decoded) !== $value) {
            throw new SandpaySdkException('杉德' . $label . '不是规范 Base64');
        }

        return $decoded;
    }

    private function assertAesKey(string $key): void
    {
        if (strlen($key) !== self::AES_KEY_BYTES || preg_match('/^[A-Za-z0-9]{16}$/D', $key) !== 1) {
            throw new SandpaySdkException('杉德 AES 密钥必须是 16 位 ASCII 字母数字');
        }
    }

    private function randomAesKey(): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $key = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < self::AES_KEY_BYTES; $i++) {
            $key .= $alphabet[random_int(0, $max)];
        }

        return $key;
    }

    private function assertRequiredConfig(): void
    {
        if ($this->configText('merchant_no') === '') {
            throw new SandpaySdkException('杉德商户编号不能为空');
        }
        if ($this->configText('public_cert_path') === '' || $this->configText('private_cert_path') === '') {
            throw new SandpaySdkException('杉德证书配置不完整');
        }
        $certNo = $this->configText('merchant_cert_no');
        if ($certNo !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $certNo) !== 1) {
            throw new SandpaySdkException('杉德商户证书序列号格式无效');
        }
    }

    /**
     * 校验响应信封字段。
     *
     * @param array<string, mixed> $result
     */
    private function assertEnvelopeField(array $result, string $field, string $expected): void
    {
        if (!array_key_exists($field, $result)) {
            throw new SandpaySdkException('杉德响应缺少外层字段 ' . $field);
        }
        $actual = $this->scalarText($result[$field]);
        if ($actual === '' || !hash_equals($expected, $actual)) {
            throw new SandpaySdkException('杉德响应外层字段 ' . $field . ' 不匹配');
        }
    }

    /**
     * 读取响应信封必填字段。
     *
     * @param array<string, mixed> $result
     */
    private function requiredEnvelopeText(array $result, string $field): string
    {
        $value = $this->scalarText($result[$field] ?? '');
        if ($value === '') {
            throw new SandpaySdkException('杉德响应缺少 ' . $field);
        }

        return $value;
    }

    private function gatewayUrl(): string
    {
        return (bool) ($this->config['sandbox'] ?? false) ? self::TEST_GATEWAY : self::PROD_GATEWAY;
    }

    private function safeGatewayDescription(mixed $value): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $this->scalarText($value)) ?: '';
        $text = mb_strcut(trim($text), 0, 200, 'UTF-8');

        return $text !== '' ? $text : '杉德请求失败';
    }

    private function scalarText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function configText(string $key): string
    {
        return $this->scalarText($this->config[$key] ?? '');
    }
}
