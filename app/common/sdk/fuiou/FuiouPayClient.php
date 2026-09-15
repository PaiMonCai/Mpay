<?php

declare(strict_types=1);

namespace app\common\sdk\fuiou;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenSSLAsymmetricKey;

/**
 * 富友合作方聚合支付协议客户端。
 *
 * 协议边界固定为：UTF-8 业务字段 -> GBK 字段/XML -> RSA-MD5 -> req 双重
 * application/x-www-form-urlencoded 编码。响应和通知均严格要求 RSA-MD5 验签。
 */
class FuiouPayClient
{
    public const PATH_PRECREATE = '/preCreate';
    public const PATH_WX_PRECREATE = '/wxPreCreate';
    public const PATH_MICROPAY = '/micropay';
    public const PATH_QUERY = '/commonQuery';
    public const PATH_CLOSE = '/closeorder';
    public const PATH_CANCEL = '/cancelorder';
    public const PATH_REFUND = '/commonRefund';

    public const RESULT_SUCCESS = '000000';

    /**
     * 付款码结果未知，必须查单，不能直接判失败。
     */
    public const MICROPAY_PENDING_CODES = [
        '030010',
        '010002',
        '9999',
        '010001',
        '2001',
        '2002',
    ];

    private const VERSION = '1.0';
    private const TERM_ID = '88888888';
    private const PROD_GATEWAY = 'https://spay-mc.fuioupay.com';
    private const TEST_GATEWAY = 'https://fundwx.payfuiouo2o.com';

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
     * 发起富友接口请求。
     *
     * @param array<string, mixed> $params UTF-8 业务参数
     * @return array<string, mixed> UTF-8 响应
     */
    public function submit(string $path, array $params): array
    {
        $payload = $this->requestPayload($params);
        $gbkPayload = $this->toGbkPayload($payload);
        $gbkPayload['sign'] = $this->signProtocolPayload($gbkPayload);

        try {
            $response = $this->httpClient->post($this->gatewayUrl() . $path, [
                'headers' => [
                    'Accept' => '*/*',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=GBK',
                ],
                'body' => 'req=' . urlencode(urlencode($this->toXml($gbkPayload))),
            ]);
        } catch (GuzzleException $e) {
            throw new FuiouSdkException('富友网关请求失败：' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new FuiouSdkException('富友网关 HTTP 状态异常：' . $response->getStatusCode());
        }

        $body = (string) $response->getBody();
        $result = $this->parseXml(urldecode($body));
        if (!$this->verify($result)) {
            throw new FuiouSdkException('富友响应验签失败');
        }

        $resultCode = trim((string) ($result['result_code'] ?? ''));
        if ($resultCode === self::RESULT_SUCCESS
            || ($path === self::PATH_MICROPAY && in_array($resultCode, self::MICROPAY_PENDING_CODES, true))
            || ($path === self::PATH_QUERY && $resultCode === '9999')) {
            return $result;
        }

        throw new FuiouSdkException(
            trim((string) ($result['result_msg'] ?? '')) ?: '富友返回失败',
            0,
            null,
            $this->safeResponse($result)
        );
    }

    /**
     * 发送渠道请求。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $path, array $params): array
    {
        return $this->submit($path, $params);
    }

    /**
     * 解析 GBK XML。libxml 会按 XML 声明把文本节点转换为 UTF-8。
     *
     * @return array<string, mixed>
     */
    public function parseXml(string $xml): array
    {
        if ($xml === '') {
            throw new FuiouSdkException('富友响应为空');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new FuiouSdkException('富友 XML 包含不允许的声明');
        }

        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($element === false || $element->getName() !== 'xml') {
            throw new FuiouSdkException('富友 XML 解析失败');
        }

        $json = json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            throw new FuiouSdkException('富友 XML 转换失败');
        }

        return $data;
    }

    /**
     * 校验渠道通知签名。
     *
     * @param array<string, mixed> $payload
     */
    public function verifyNotify(array $payload): bool
    {
        return $this->verify($payload);
    }

    /**
     * 对 UTF-8 字段生成 RSA-MD5 签名，供固定向量和通知夹具复用。
     *
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        return $this->signProtocolPayload($this->toGbkPayload($payload));
    }

    /**
     * 校验渠道签名。
     *
     * @param array<string, mixed> $payload
     */
    public function verify(array $payload): bool
    {
        $signature = trim((string) ($payload['sign'] ?? ''));
        $decoded = $signature === '' ? false : base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        $publicKey = $this->publicKey();

        return openssl_verify(
            $this->protocolSignContent($this->toGbkPayload($payload)),
            $decoded,
            $publicKey,
            OPENSSL_ALGO_MD5
        ) === 1;
    }

    /**
     * 返回 UTF-8 参数对应的 GBK 待签名字节，用于协议固定向量测试。
     *
     * @param array<string, mixed> $payload
     */
    public function canonicalSignContent(array $payload): string
    {
        return $this->protocolSignContent($this->toGbkPayload($payload));
    }

    /**
     * 将 UTF-8 字段编码成富友要求的 GBK XML。
     *
     * @param array<string, mixed> $payload
     */
    public function encodeXml(array $payload): string
    {
        return $this->toXml($this->toGbkPayload($payload));
    }

    /**
     * 获取渠道网关地址。
     *
     * @return string 网关地址
     */
    public function gatewayUrl(): string
    {
        $custom = $this->configText('api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return $this->configBool('sandbox') ? self::TEST_GATEWAY : self::PROD_GATEWAY;
    }

    /**
     * 构建协议请求载荷。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function requestPayload(array $params): array
    {
        $factory = $this->config['random_str_factory'] ?? null;
        $random = is_callable($factory) ? (string) $factory() : bin2hex(random_bytes(8));
        if ($random === '') {
            throw new FuiouSdkException('富友请求随机串不能为空');
        }

        return array_merge([
            'version' => self::VERSION,
            'ins_cd' => $this->configText('institution_code'),
            'mchnt_cd' => $this->configText('merchant_no'),
            'term_id' => self::TERM_ID,
            'random_str' => $random,
        ], $params);
    }

    /**
     * 生成协议载荷签名。
     *
     * @param array<string, mixed> $payload
     */
    private function signProtocolPayload(array $payload): string
    {
        $signature = '';
        if (!openssl_sign(
            $this->protocolSignContent($payload),
            $signature,
            $this->privateKey(),
            OPENSSL_ALGO_MD5
        )) {
            throw new FuiouSdkException('富友请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 构建协议签名原文。
     *
     * @param array<string, mixed> $payload
     */
    private function protocolSignContent(array $payload): string
    {
        ksort($payload, SORT_STRING);
        $pieces = [];
        foreach ($payload as $key => $value) {
            $key = (string) $key;
            if ($key === 'sign' || str_starts_with($key, 'reserved')) {
                continue;
            }
            $pieces[] = $key . '=' . (is_array($value) ? '' : (string) $value);
        }

        return implode('&', $pieces);
    }

    /**
     * 生成 XML 请求正文。
     *
     * @param array<string, mixed> $payload
     */
    private function toXml(array $payload): string
    {
        return '<?xml version="1.0" encoding="GBK" standalone="yes"?><xml>'
            . $this->xmlNodes($payload)
            . '</xml>';
    }

    /**
     * 生成 XML 节点。
     *
     * @param array<string, mixed> $payload
     */
    private function xmlNodes(array $payload): string
    {
        $xml = '';
        foreach ($payload as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', (string) $key)) {
                throw new FuiouSdkException('富友 XML 字段名不合法');
            }
            if (is_array($value)) {
                $xml .= '<' . $key . '>' . $this->xmlNodes($value) . '</' . $key . '>';
                continue;
            }
            // PHP 的 htmlspecialchars 并不稳定支持 GBK 字节串；XML 五个保留字符
            // 都是 ASCII，直接在 GBK 字节上替换不会破坏多字节字符。
            $escaped = str_replace(
                ['&', '<', '>', '"', "'"],
                ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
                (string) $value
            );
            $xml .= '<' . $key . '>' . $escaped . '</' . $key . '>';
        }

        return $xml;
    }

    /**
     * 将请求载荷转换为 GBK。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function toGbkPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->toGbkPayload($value);
            } elseif (is_string($value) && $value !== '') {
                $converted = mb_convert_encoding($value, 'GBK', 'UTF-8');
                if ($converted === '') {
                    throw new FuiouSdkException('富友字段 GBK 编码失败');
                }
                $payload[$key] = $converted;
            }
        }

        return $payload;
    }

    private function privateKey(): OpenSSLAsymmetricKey
    {
        foreach ($this->keyCandidates($this->configText('merchant_private_key'), true) as $candidate) {
            $key = openssl_pkey_get_private($candidate);
            if ($key !== false) {
                return $key;
            }
        }

        throw new FuiouSdkException('富友商户私钥不正确（支持 PKCS#8/PKCS#1 PEM 或裸 Base64）');
    }

    private function publicKey(): OpenSSLAsymmetricKey
    {
        foreach ($this->keyCandidates($this->configText('platform_public_key'), false) as $candidate) {
            $key = openssl_pkey_get_public($candidate);
            if ($key !== false) {
                return $key;
            }
        }

        throw new FuiouSdkException('富友平台公钥不正确（支持 PEM/证书或裸 Base64）');
    }

    /**
     * 构建签名密钥候选列表。
     *
     * @return array<int, string>
     */
    private function keyCandidates(string $key, bool $private): array
    {
        $key = trim($key);
        if ($key === '') {
            return [];
        }
        if (str_contains($key, '-----BEGIN')) {
            return [$key];
        }

        $body = wordwrap(preg_replace('/\s+/', '', $key) ?: '', 64, "\n", true);
        $types = $private ? ['PRIVATE KEY', 'RSA PRIVATE KEY'] : ['PUBLIC KEY', 'RSA PUBLIC KEY', 'CERTIFICATE'];

        return array_map(
            static fn (string $type): string => "-----BEGIN {$type}-----\n{$body}\n-----END {$type}-----",
            $types
        );
    }

    /**
     * 生成脱敏响应摘要。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function safeResponse(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'result_code',
            'result_msg',
            'mchnt_cd',
            'mchnt_order_no',
            'refund_order_no',
            'order_type',
            'trans_stat',
        ]));
    }

    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }

    private function configBool(string $key): bool
    {
        return filter_var($this->config[$key] ?? false, FILTER_VALIDATE_BOOL);
    }
}
