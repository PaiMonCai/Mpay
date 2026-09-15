<?php

declare(strict_types=1);

namespace app\common\sdk\kuaiqian;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * 快钱人民币网关、H5 和公众号表单客户端。
 */
class KuaiqianClient
{
    public const BANK_GATEWAY = 'https://www.99bill.com/gateway/recvMerchantInfoAction.htm';
    public const MOBILE_GATEWAY = 'https://www.99bill.com/mobilegateway/recvMerchantInfoAction.htm';

    private const MAX_CERTIFICATE_BYTES = 2 * 1024 * 1024;

    /**
     * @var array<int, string>
     */
    private const NOTIFY_FIELDS = [
        'merchantAcctId', 'version', 'language', 'signType', 'payType', 'bankId',
        'orderId', 'orderTime', 'orderAmount', 'bindCard', 'bindMobile', 'dealId',
        'bankDealId', 'dealTime', 'payAmount', 'fee', 'ext1', 'ext2', 'payResult',
        'aggregatePay', 'errCode', 'period',
    ];

    /**
     * @var array<string, string>
     */
    private array $config;

    private OpenSSLAsymmetricKey $merchantPrivateKey;

    private OpenSSLAsymmetricKey $platformPublicKey;

    /**
     * 构造快钱 API 客户端。
     *
     * @param array<string, string> $config SDK 配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->assertRequiredConfig();
        $this->platformPublicKey = $this->loadPlatformPublicKey();
        $this->merchantPrivateKey = $this->loadMerchantPrivateKey();
    }

    /**
     * 构造仅向快钱固定 HTTPS 网关提交的自动表单。
     *
     * @param array<string, mixed> $signedParams 参与签名的字段
     * @param array<string, mixed> $unsignedParams 官方明确不参与签名的字段
     */
    public function formHtml(string $url, array $signedParams, array $unsignedParams = []): string
    {
        if (!in_array($url, [self::BANK_GATEWAY, self::MOBILE_GATEWAY], true)) {
            throw new KuaiqianSdkException('快钱表单目标不在允许列表中');
        }
        if (array_key_exists('signMsg', $signedParams)
            || array_key_exists('signMsg', $unsignedParams)
            || array_intersect_key($signedParams, $unsignedParams) !== []) {
            throw new KuaiqianSdkException('快钱表单字段重复或包含保留签名字段');
        }

        $this->requestSigningContent($signedParams);
        $this->assertScalarFields($unsignedParams, '表单');
        $params = $signedParams + $unsignedParams + ['signMsg' => $this->sign($signedParams)];
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );

        $html = '<form action="' . $escape($url) . '" method="post" id="kuaiqian-pay-form">';
        foreach ($params as $key => $value) {
            $html .= '<input type="hidden" name="' . $escape((string) $key)
                . '" value="' . $escape((string) $value) . '">' . "\n";
        }
        $html .= '</form><script>document.getElementById("kuaiqian-pay-form").submit();</script>';

        return $html;
    }

    /**
     * 生成表单签名原文。
     *
     * @param array<string, mixed> $payload 已按快钱协议顺序排列的字段
     */
    public function requestSigningContent(array $payload): string
    {
        $this->assertScalarFields($payload, '表单');
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'signMsg' && (string) $value !== '') {
                $pieces[] = $key . '=' . (string) $value;
            }
        }

        return implode('&', $pieces);
    }

    /**
     * 生成异步通知验签原文。
     *
     * @param array<string, mixed> $payload 通知参数
     */
    public function notifySigningContent(array $payload): string
    {
        $this->assertScalarFields($payload, '通知');
        $allowed = array_fill_keys(array_merge(self::NOTIFY_FIELDS, ['signMsg']), true);
        foreach ($payload as $key => $_value) {
            if (!isset($allowed[(string) $key])) {
                throw new KuaiqianSdkException('快钱通知包含未纳入验签的字段');
            }
        }

        $pieces = [];
        foreach (self::NOTIFY_FIELDS as $key) {
            if (($payload[$key] ?? '') !== '') {
                $pieces[] = $key . '=' . (string) $payload[$key];
            }
        }

        return implode('&', $pieces);
    }

    /**
     * 校验快钱表单 profile 的异步通知签名。
     *
     * @param array<string, mixed> $payload 通知参数
     */
    public function verifyNotify(array $payload): bool
    {
        $sign = is_scalar($payload['signMsg'] ?? null) ? trim((string) $payload['signMsg']) : '';
        if ($sign === '') {
            return false;
        }

        $signature = $this->strictBase64Decode($sign);

        return openssl_verify(
            $this->notifySigningContent($payload),
            $signature,
            $this->platformPublicKey,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * 生成请求签名。
     *
     * @param array<string, mixed> $payload
     */
    private function sign(array $payload): string
    {
        $signature = '';
        if (!openssl_sign(
            $this->requestSigningContent($payload),
            $signature,
            $this->merchantPrivateKey,
            OPENSSL_ALGO_SHA256
        )) {
            throw new KuaiqianSdkException('快钱请求签名失败');
        }

        return base64_encode($signature);
    }

    private function loadPlatformPublicKey(): OpenSSLAsymmetricKey
    {
        $content = $this->readCertificateFile($this->configText('platform_cert_path'), '快钱平台公钥证书');
        $certificate = $this->parseCertificate($content, '快钱平台公钥证书');
        $this->assertCertificateValidity($certificate, '快钱平台公钥证书');
        $publicKey = @openssl_pkey_get_public($certificate);
        if (!$publicKey instanceof OpenSSLAsymmetricKey) {
            throw new KuaiqianSdkException('快钱平台公钥证书未包含有效公钥');
        }
        $this->assertRsaKey($publicKey, '快钱平台公钥证书');

        return $publicKey;
    }

    private function loadMerchantPrivateKey(): OpenSSLAsymmetricKey
    {
        $content = $this->readCertificateFile($this->configText('merchant_key_path'), '快钱商户 PFX 证书');
        $certs = [];
        if (!@openssl_pkcs12_read($content, $certs, $this->configText('merchant_cert_password'))) {
            throw new KuaiqianSdkException('快钱商户 PFX 证书或密码无效');
        }
        $privateKey = @openssl_pkey_get_private($this->scalarText($certs['pkey'] ?? ''));
        $merchantCertificate = @openssl_x509_read($this->scalarText($certs['cert'] ?? ''));
        if (!$privateKey instanceof OpenSSLAsymmetricKey || !$merchantCertificate instanceof OpenSSLCertificate) {
            throw new KuaiqianSdkException('快钱商户 PFX 必须同时包含私钥和证书');
        }
        $this->assertRsaKey($privateKey, '快钱商户 PFX 证书');
        $this->assertCertificateValidity($merchantCertificate, '快钱商户 PFX 证书');
        if (!openssl_x509_check_private_key($merchantCertificate, $privateKey)) {
            throw new KuaiqianSdkException('快钱商户证书与私钥不匹配');
        }

        return $privateKey;
    }

    private function parseCertificate(string $content, string $label): OpenSSLCertificate
    {
        $certificate = @openssl_x509_read($content);
        if (!$certificate instanceof OpenSSLCertificate && !str_contains($content, 'BEGIN CERTIFICATE')) {
            $pem = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($content), 64, "\n")
                . "-----END CERTIFICATE-----\n";
            $certificate = @openssl_x509_read($pem);
        }
        if (!$certificate instanceof OpenSSLCertificate) {
            throw new KuaiqianSdkException($label . '不是有效的 X.509 证书');
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
            throw new KuaiqianSdkException($label . '不在有效期内');
        }
    }

    private function assertRsaKey(OpenSSLAsymmetricKey $key, string $label): void
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)
            || (int) ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA
            || (int) ($details['bits'] ?? 0) < 2048) {
            throw new KuaiqianSdkException($label . '必须包含至少 2048 位 RSA 密钥');
        }
    }

    private function readCertificateFile(string $path, string $label): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new KuaiqianSdkException($label . '读取失败');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_CERTIFICATE_BYTES) {
            throw new KuaiqianSdkException($label . '大小无效');
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new KuaiqianSdkException($label . '读取失败');
        }

        return $content;
    }

    private function strictBase64Decode(string $value): string
    {
        if (strlen($value) % 4 !== 0
            || preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $value) !== 1) {
            throw new KuaiqianSdkException('快钱通知签名不是规范 Base64');
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || base64_encode($decoded) !== $value) {
            throw new KuaiqianSdkException('快钱通知签名不是规范 Base64');
        }

        return $decoded;
    }

    /**
     * 校验请求字段均为标量。
     *
     * @param array<string, mixed> $fields
     */
    private function assertScalarFields(array $fields, string $scene): void
    {
        foreach ($fields as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new KuaiqianSdkException('快钱' . $scene . '字段必须是标量');
            }
        }
    }

    private function assertRequiredConfig(): void
    {
        foreach (['merchant_cert_password', 'platform_cert_path', 'merchant_key_path'] as $field) {
            if ($this->configText($field) === '') {
                throw new KuaiqianSdkException('快钱证书配置不完整');
            }
        }
    }

    private function configText(string $key): string
    {
        return $this->scalarText($this->config[$key] ?? '');
    }

    private function scalarText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
