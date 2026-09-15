<?php

declare(strict_types=1);

namespace app\common\sdk\unionpay;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 银联前置 Swiftpass 协议轻量客户端。
 */
class UnionpayClient
{
    private const GATEWAY = 'https://qra.95516.com/pay/gateway';

    private const MAX_XML_BYTES = 1048576;

    private const SERVICES = [
        'unified.trade.native',
        'pay.weixin.jspay',
        'pay.alipay.jspay',
        'pay.unionpay.userid',
        'pay.unionpay.jspay',
        'pay.weixin.wappay',
        'unified.trade.refund',
    ];

    /**
     * SDK 配置。
     *
     * @var array<string, string>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private ClientInterface $httpClient;

    /**
     * 构造方法。
     *
     * @param array<string, string> $config SDK 配置
     */
    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        $mchId = trim((string) ($config['mch_id'] ?? ''));
        $key = trim((string) ($config['key'] ?? ''));
        if ($mchId === '' || $key === '') {
            throw new UnionpaySdkException('银联前置商户号和商户密钥不能为空');
        }

        $gateway = trim((string) ($config['gateway_url'] ?? '')) ?: self::GATEWAY;
        $parts = parse_url($gateway);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new UnionpaySdkException('银联前置网关必须是无用户凭据的 HTTPS 地址');
        }

        $this->config = [
            'mch_id' => $mchId,
            'sub_mch_id' => trim((string) ($config['sub_mch_id'] ?? '')),
            'key' => $key,
            'gateway_url' => $gateway,
        ];
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 发起 XML 接口请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function request(array $payload): array
    {
        $service = trim((string) ($payload['service'] ?? ''));
        if (!in_array($service, self::SERVICES, true)) {
            throw new UnionpaySdkException('银联前置 service 未获当前适配合同支持');
        }

        foreach (['mch_id', 'sub_mch_id', 'key', 'version', 'charset', 'sign_type', 'nonce_str', 'sign'] as $field) {
            unset($payload[$field]);
        }
        $common = [
            'mch_id' => $this->config['mch_id'],
            'version' => '2.0',
            'charset' => 'UTF-8',
            'sign_type' => 'MD5',
            'nonce_str' => bin2hex(random_bytes(16)),
        ];
        if ($this->config['sub_mch_id'] !== '') {
            $common['sub_mch_id'] = $this->config['sub_mch_id'];
        }
        $payload = array_merge($common, $payload);
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->request('POST', $this->config['gateway_url'], [
                'headers' => ['Content-Type' => 'application/xml; charset=UTF-8'],
                'body' => $this->encodeXml($payload),
            ]);
        } catch (GuzzleException $e) {
            throw new UnionpaySdkException('银联前置网关通信失败', true, $e);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new UnionpaySdkException('银联前置网关返回非成功 HTTP 状态', true);
        }

        try {
            $data = $this->parseXml((string) $response->getBody());
        } catch (UnionpaySdkException $e) {
            throw new UnionpaySdkException($e->getMessage(), true, $e);
        }
        if (!$this->verify($data)) {
            throw new UnionpaySdkException('银联前置响应验签失败', true);
        }
        $this->assertAcceptedResponse($data);

        return $data;
    }

    /**
     * 解析并校验回调 XML。
     *
     * @return array<string, mixed>
     */
    public function notify(string $xml): array
    {
        $data = $this->parseXml($xml);
        if (!$this->verify($data)) {
            throw new UnionpaySdkException('银联前置回调验签失败');
        }

        return $data;
    }

    /**
     * 校验签名。
     *
     * @param array<string, mixed> $payload 参数
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
     * 生成 MD5 签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function sign(array $payload): string
    {
        return strtoupper(md5($this->signingContent($payload) . '&key=' . $this->config['key']));
    }

    /**
     * 生成不含密钥的待签名原文。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function signingContent(array $payload): string
    {
        ksort($payload, SORT_STRING);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key === 'sign' || $value === null) {
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new UnionpaySdkException('银联前置签名字段必须是标量');
            }
            $text = (string) $value;
            if (trim($text) === '') {
                continue;
            }
            $pieces[] = (string) $key . '=' . $text;
        }

        return implode('&', $pieces);
    }

    /**
     * 将一层标量数组编码为 XML。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function encodeXml(array $payload): string
    {
        $xml = '<xml>';
        foreach ($payload as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
                throw new UnionpaySdkException('银联前置 XML 字段名无效');
            }
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new UnionpaySdkException('银联前置 XML 字段必须是标量');
            }
            $text = (string) $value;
            if (trim($text) === '') {
                continue;
            }
            $xml .= sprintf('<%1$s><![CDATA[%2$s]]></%1$s>', $key, str_replace(']]>', ']]]]><![CDATA[>', $text));
        }

        return $xml . '</xml>';
    }

    /**
     * 安全解析银联前置一层 XML。
     *
     * @return array<string, string>
     */
    public function parseXml(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            throw new UnionpaySdkException('银联前置响应为空');
        }
        if (strlen($xml) > self::MAX_XML_BYTES) {
            throw new UnionpaySdkException('银联前置 XML 超过允许大小');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new UnionpaySdkException('银联前置 XML 不允许包含 DOCTYPE 或 ENTITY');
        }

        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($element === false) {
            $message = trim((string) ($errors[0]->message ?? '未知 XML 错误'));
            throw new UnionpaySdkException('银联前置 XML 解析失败：' . $message);
        }
        if ($element->getName() !== 'xml' || count($element->attributes()) > 0) {
            throw new UnionpaySdkException('银联前置 XML 根节点必须是无属性的 xml');
        }

        $result = [];
        foreach ($element->children() as $child) {
            $key = $child->getName();
            if ($key === '' || array_key_exists($key, $result)) {
                throw new UnionpaySdkException('银联前置 XML 包含空字段名或重复字段');
            }
            if ($child->count() > 0 || count($child->attributes()) > 0) {
                throw new UnionpaySdkException('银联前置 XML 字段不允许嵌套或携带属性');
            }
            $result[$key] = (string) $child;
        }

        return $result;
    }

    /**
     * 校验已验签响应的通信和业务受理结果。
     *
     * 兼容合同使用 status=0，商户当前合同可能使用 return_code=SUCCESS；
     * 两种口径必须由响应字段显式区分，不能把缺失字段当成功。
     *
     * @param array<string, string> $data 响应数据
     */
    private function assertAcceptedResponse(array $data): void
    {
        if (array_key_exists('return_code', $data)) {
            if (strtoupper(trim($data['return_code'])) !== 'SUCCESS') {
                throw new UnionpaySdkException($this->safeMessage($data['return_msg'] ?? '', '银联前置通信失败'));
            }
            $resultCode = trim((string) ($data['result_code'] ?? ''));
            if ($resultCode === '') {
                throw new UnionpaySdkException('银联前置响应缺少业务状态', true);
            }
            $businessSuccess = strtoupper($resultCode) === 'SUCCESS';
        } elseif (array_key_exists('status', $data)) {
            if (trim($data['status']) !== '0') {
                throw new UnionpaySdkException($this->safeMessage($data['message'] ?? '', '银联前置通信失败'));
            }
            $resultCode = trim((string) ($data['result_code'] ?? ''));
            if ($resultCode === '') {
                throw new UnionpaySdkException('银联前置响应缺少业务状态', true);
            }
            $businessSuccess = $resultCode === '0';
        } else {
            throw new UnionpaySdkException('银联前置响应缺少通信状态', true);
        }

        if (!$businessSuccess) {
            $code = trim((string) ($data['err_code'] ?? ''));
            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $code) !== 1) {
                $code = '';
            }
            $message = $this->safeMessage($data['err_msg'] ?? '', '银联前置业务拒绝');
            throw new UnionpaySdkException($code === '' ? $message : '[' . $code . ']' . $message);
        }
    }

    private function safeMessage(mixed $message, string $default): string
    {
        $message = trim((string) $message);
        if ($message === '' || stripos($message, '<xml') !== false || stripos($message, '<?xml') !== false) {
            return $default;
        }
        $message = strip_tags($message);
        $message = preg_replace(
            '/\b(key|sign|openid|sub_openid|mini_openid|buyer_id|user_id|user_auth_code)\b\s*[:=]\s*[^&\s,;]+/i',
            '$1=[REDACTED]',
            $message
        ) ?? $default;

        return $message === '' ? $default : mb_strcut($message, 0, 240, 'UTF-8');
    }
}
