<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

/**
 * 支付宝 RSA2 签名工具。
 *
 * 请求签名与异步通知验签必须使用不同的参数过滤规则；两者都使用
 * SHA256withRSA（RSA2），但绝不能重新 JSON 编码通知值或二次 URL 解码。
 */
class AlipaySigner
{
    /**
     * 构造支付宝待签名字符串。
     *
     * @param array<string, mixed> $params 参数数组
     * @param bool $excludeSignType 是否排除 sign_type；通知验签时必须排除
     * @return string 待签名字符串
     */
    public static function signContent(array $params, bool $excludeSignType = false): string
    {
        return $excludeSignType
            ? self::notifyContent($params)
            : self::requestContent($params);
    }

    /**
     * 构造支付宝 OpenAPI 请求待签名字符串。
     *
     * @param array<string, mixed> $params 请求参数
     * @return string 待签名字符串
     */
    public static function requestContent(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[] = $key . '=' . self::scalarValue($key, $value);
        }

        return implode('&', $pairs);
    }

    /**
     * 构造支付宝异步通知待验签字符串。
     *
     * Request 已完成 application/x-www-form-urlencoded 的一次解码，因此这里保留
     * PHP 收到的字符串字节，不再调用 urldecode/rawurldecode。
     *
     * @param array<string, mixed> $params 通知表单参数
     * @return string 待验签字符串
     */
    public static function notifyContent(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $key === 'sign_type') {
                continue;
            }
            $pairs[] = $key . '=' . self::scalarValue($key, $value);
        }

        return implode('&', $pairs);
    }

    /**
     * 使用应用私钥生成 RSA2 签名。
     *
     * @param string $content 待签名字符串
     * @param string $privateKey 应用私钥，支持带 PEM 头尾或纯 Base64 内容
     * @return string Base64 签名
     */
    public static function sign(string $content, string $privateKey): string
    {
        $resource = self::privateKeyResource($privateKey);

        $signature = '';
        $success = openssl_sign($content, $signature, $resource, OPENSSL_ALGO_SHA256);
        if (!$success || $signature === '') {
            throw new AlipaySdkException('支付宝请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 使用支付宝公钥验证 RSA2 签名。
     *
     * @param string $content 待验签字符串
     * @param string $sign Base64 签名
     * @param string $publicKey 支付宝公钥，支持带 PEM 头尾或纯 Base64 内容
     * @return bool 是否验签通过
     */
    public static function verify(string $content, string $sign, string $publicKey): bool
    {
        $decoded = base64_decode(trim($sign), true);
        if ($decoded === false) {
            return false;
        }

        $resource = self::publicKeyResource($publicKey);

        return openssl_verify($content, $decoded, $resource, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 标准化应用私钥 PEM。
     *
     * @param string $privateKey 应用私钥
     * @return string PEM 私钥
     */
    public static function normalizePrivateKey(string $privateKey): string
    {
        return self::normalizePem($privateKey, 'PRIVATE KEY');
    }

    /**
     * 标准化支付宝公钥 PEM。
     *
     * @param string $publicKey 支付宝公钥
     * @return string PEM 公钥
     */
    public static function normalizePublicKey(string $publicKey): string
    {
        return self::normalizePem($publicKey, 'PUBLIC KEY');
    }

    /**
     * 提前校验应用私钥必须是至少 2048 位的 RSA 私钥。
     *
     * @param string $privateKey 应用私钥
     * @return void
     */
    public static function validatePrivateKey(string $privateKey): void
    {
        self::assertRsaKey(self::privateKeyResource($privateKey), '支付宝应用私钥');
    }

    /**
     * 提前校验支付宝公钥必须是至少 2048 位的 RSA 公钥。
     *
     * @param string $publicKey 支付宝公钥
     * @return void
     */
    public static function validatePublicKey(string $publicKey): void
    {
        self::assertRsaKey(self::publicKeyResource($publicKey), '支付宝公钥');
    }

    /**
     * 校验私钥与证书公钥是否属于同一密钥对。
     *
     * @param string $privateKey 应用私钥
     * @param string $publicKey PEM 公钥
     * @return bool 是否属于同一密钥对
     */
    public static function keysMatch(string $privateKey, string $publicKey): bool
    {
        $private = openssl_pkey_get_details(self::privateKeyResource($privateKey));
        $public = openssl_pkey_get_details(self::publicKeyResource($publicKey));

        return is_array($private)
            && is_array($public)
            && isset($private['rsa']['n'], $public['rsa']['n'])
            && hash_equals((string) $private['rsa']['n'], (string) $public['rsa']['n']);
    }

    /**
     * 给纯 Base64 密钥补齐 PEM 头尾。
     *
     * @param string $key 原始密钥
     * @param string $label PEM 标签
     * @return string PEM 内容
     */
    private static function normalizePem(string $key, string $label): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (str_contains($key, '-----BEGIN ')) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $key) ?? '';

        return "-----BEGIN {$label}-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END {$label}-----";
    }

    /**
     * 通知与公共参数必须是标量；嵌套表单不能参与支付宝签名。
     *
     * @param string|int $key 参数名
     * @param mixed $value 参数值
     * @return string 标量字符串
     */
    private static function scalarValue(string|int $key, mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new AlipaySdkException('支付宝签名参数必须是标量', 'protocol', [
                'parameter' => (string) $key,
            ]);
        }

        return (string) $value;
    }

    /**
     * 解析并校验应用私钥。
     *
     * @param string $privateKey 应用私钥
     * @return \OpenSSLAsymmetricKey|resource OpenSSL 私钥资源
     */
    private static function privateKeyResource(string $privateKey): mixed
    {
        $resource = openssl_pkey_get_private(self::normalizePrivateKey($privateKey));
        if ($resource === false) {
            throw new AlipaySdkException('支付宝应用私钥无效', 'configuration');
        }
        self::assertRsaKey($resource, '支付宝应用私钥');

        return $resource;
    }

    /**
     * 解析并校验支付宝公钥。
     *
     * @param string $publicKey 支付宝公钥
     * @return \OpenSSLAsymmetricKey|resource OpenSSL 公钥资源
     */
    private static function publicKeyResource(string $publicKey): mixed
    {
        $resource = openssl_pkey_get_public(self::normalizePublicKey($publicKey));
        if ($resource === false) {
            throw new AlipaySdkException('支付宝公钥无效', 'configuration');
        }
        self::assertRsaKey($resource, '支付宝公钥');

        return $resource;
    }

    /**
     * 校验 OpenSSL 密钥类型和强度。
     *
     * @param \OpenSSLAsymmetricKey|resource $resource OpenSSL 密钥资源
     * @param string $label 密钥名称
     * @return void
     */
    private static function assertRsaKey(mixed $resource, string $label): void
    {
        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new AlipaySdkException($label . '必须是 RSA 密钥', 'configuration');
        }
        if ((int) ($details['bits'] ?? 0) < 2048) {
            throw new AlipaySdkException($label . '必须至少为 2048 位', 'configuration');
        }
    }
}
