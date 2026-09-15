<?php

declare(strict_types=1);

namespace app\common\sdk\xunhupay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * 虎皮椒支付 API v1.1 轻量客户端。
 *
 * 支付请求使用 JSON，当前官方查单和退款请求使用表单。所有出站地址必须为
 * HTTPS，TLS 证书校验不能由通道配置关闭。
 */
class XunhupayClient
{
    private const DEFAULT_PAY_URL = 'https://api.xunhupay.com/payment/do.html';
    private const MAX_API_RESPONSE_BYTES = 262144;
    private const MAX_QRCODE_RESPONSE_BYTES = 1048576;
    private const MAX_QRCODE_URL_BYTES = 2048;

    /**
     * 官网列出的正式、备用和其他平台域名后缀。
     *
     * @var array<int, string>
     */
    private const OFFICIAL_HOST_SUFFIXES = [
        'xunhupay.com',
        'dpweixin.com',
        'diypc.com.cn',
    ];

    /**
     * 二维码图片只接受不会执行脚本的常见位图类型。
     *
     * @var array<int, string>
     */
    private const QRCODE_IMAGE_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];

    private Client $httpClient;

    /**
     * 构造虎皮椒 API 客户端。
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
     * 发起支付下单。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function pay(array $params): array
    {
        return $this->submit($this->payUrl(), $params, 'json', true);
    }

    /**
     * 查询订单。
     *
     * 当前官网把成功业务字段放在 `data` 对象中，但没有定义对象值参与外层
     * `hash` 的规范化方式，官网下载 SDK 也不校验该响应签名。这里固定依赖
     * HTTPS/TLS，业务层必须继续严格核对订单号、金额和 `open_order_id`。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function query(array $params): array
    {
        $response = $this->submit($this->endpointUrl('/payment/query.html'), $params, 'form', false);
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new XunhupaySdkException('虎皮椒查单响应缺少 data 对象');
        }

        return $data;
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function refund(array $params): array
    {
        return $this->submit($this->endpointUrl('/payment/refund.html'), $params, 'form', true);
    }

    /**
     * 校验回调或平铺响应签名。
     *
     * @param array<string, mixed> $payload 回调或响应参数
     */
    public function verify(array $payload): bool
    {
        $hash = (string) ($payload['hash'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/D', $hash) !== 1) {
            return false;
        }

        try {
            return hash_equals($hash, $this->sign($payload));
        } catch (XunhupaySdkException) {
            return false;
        }
    }

    /**
     * 按官方字段规则生成 32 位小写 MD5。
     *
     * 参数名按 ASCII 升序排列，排除 `hash`、空字符串和 `null`，参数原值不做
     * URL 编码，最后一个值与 APPSECRET 之间没有连接符。
     *
     * @param array<string, mixed> $params 待签名参数
     */
    public function sign(array $params): string
    {
        unset($params['hash']);
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_string($value) && !is_int($value)) {
                throw new XunhupaySdkException('虎皮椒签名字段必须是字符串或整数');
            }
            $pairs[] = (string) $key . '=' . (string) $value;
        }

        $apiKey = $this->configText('api_key');
        if ($apiKey === '') {
            throw new XunhupaySdkException('虎皮椒 API 密钥不能为空', true, 'MISSING_API_KEY');
        }

        return md5(implode('&', $pairs) . $apiKey);
    }

    /**
     * 从受信二维码图片地址取得实际二维码内容。
     *
     * 当前兼容合同使用图片 URL 或一次重定向中的 `data` 参数保存 Base64 二维码
     * 内容。这里仍只接受该可验证合同；无可逆 `data` 的位图不能冒充二维码内容。
     */
    public function parseQrcode(string $qrcodeUrl): string
    {
        $qrcodeUrl = trim($qrcodeUrl);
        $this->assertTrustedQrcodeUrl($qrcodeUrl);

        $response = $this->qrcodeResponse($qrcodeUrl);
        $status = $response->getStatusCode();
        $contentUrl = $qrcodeUrl;

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $contentUrl = trim($response->getHeaderLine('Location'));
            $this->assertTrustedQrcodeUrl($contentUrl);
            $response = $this->qrcodeResponse($contentUrl);
            $status = $response->getStatusCode();
        }

        if ($status < 200 || $status >= 300) {
            throw new XunhupaySdkException('虎皮椒二维码图片响应状态无效');
        }
        $this->assertQrcodeImageResponse($response);

        $encoded = $this->queryParameter($contentUrl, 'data');
        if ($encoded === '') {
            throw new XunhupaySdkException('虎皮椒二维码图片未提供可验证的二维码内容');
        }
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || $decoded === '' || strlen($decoded) > self::MAX_QRCODE_URL_BYTES) {
            throw new XunhupaySdkException('虎皮椒二维码内容不是有效 Base64 数据');
        }

        return $this->assertQrcodeContent($decoded);
    }

    /**
     * 提交请求并解析受控 JSON 响应。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    private function submit(string $url, array $params, string $encoding, bool $verifyResponse): array
    {
        $appid = $this->configText('appid');
        if ($appid === '') {
            throw new XunhupaySdkException('虎皮椒 APPID 不能为空', true, 'MISSING_APPID');
        }

        unset($params['hash'], $params['appid'], $params['time'], $params['nonce_str']);
        $payload = array_merge($params, [
            'appid' => $appid,
            'time' => time(),
            'nonce_str' => bin2hex(random_bytes(8)),
        ]);
        $payload['hash'] = $this->sign($payload);

        $options = [
            'headers' => ['Accept' => 'application/json'],
            'stream' => true,
        ];
        if ($encoding === 'json') {
            try {
                $body = json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            } catch (JsonException $e) {
                throw new XunhupaySdkException('虎皮椒请求 JSON 编码失败', true, 'INVALID_JSON', $e);
            }
            $options['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $options['body'] = $body;
        } else {
            $options['form_params'] = $payload;
        }

        try {
            $response = $this->httpClient->post($url, $options);
        } catch (GuzzleException $e) {
            throw new XunhupaySdkException('虎皮椒请求通信失败', false, '', $e);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new XunhupaySdkException('虎皮椒网关返回非成功 HTTP 状态');
        }
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? ''));
        if ($contentType !== 'application/json') {
            throw new XunhupaySdkException('虎皮椒响应类型不是 application/json');
        }

        $body = $this->readLimitedBody($response, self::MAX_API_RESPONSE_BYTES, '虎皮椒响应');
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new XunhupaySdkException('虎皮椒响应不是合法 JSON', false, '', $e);
        }
        if (!is_array($decoded)) {
            throw new XunhupaySdkException('虎皮椒响应不是 JSON 对象');
        }

        if (!array_key_exists('errcode', $decoded)) {
            throw new XunhupaySdkException('虎皮椒响应缺少 errcode');
        }
        if (!is_int($decoded['errcode'])
            && (!is_string($decoded['errcode']) || preg_match('/^-?\d+$/D', $decoded['errcode']) !== 1)) {
            throw new XunhupaySdkException('虎皮椒响应 errcode 格式无效');
        }
        if ($verifyResponse && !$this->verify($decoded)) {
            throw new XunhupaySdkException('虎皮椒响应验签失败');
        }
        $errcode = (string) $decoded['errcode'];
        if ((int) $decoded['errcode'] !== 0) {
            throw new XunhupaySdkException(
                $this->safeMessage($decoded['errmsg'] ?? '虎皮椒明确拒绝请求'),
                true,
                $errcode
            );
        }

        return $decoded;
    }

    /**
     * 请求二维码图片，不自动跟随重定向。
     */
    private function qrcodeResponse(string $url): ResponseInterface
    {
        try {
            return $this->httpClient->get($url, [
                'allow_redirects' => false,
                'headers' => [
                    'Accept' => implode(', ', self::QRCODE_IMAGE_TYPES),
                ],
                'stream' => true,
            ]);
        } catch (GuzzleException $e) {
            throw new XunhupaySdkException('虎皮椒二维码图片请求失败', false, '', $e);
        }
    }

    /**
     * 校验二维码位图响应类型和大小。
     */
    private function assertQrcodeImageResponse(ResponseInterface $response): void
    {
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? ''));
        if (!in_array($contentType, self::QRCODE_IMAGE_TYPES, true)) {
            throw new XunhupaySdkException('虎皮椒二维码响应不是受支持的图片类型');
        }
        $this->readLimitedBody($response, self::MAX_QRCODE_RESPONSE_BYTES, '虎皮椒二维码图片');
    }

    /**
     * 受限读取响应体，避免网关返回超大内容占用内存。
     */
    private function readLimitedBody(ResponseInterface $response, int $limit, string $scene): string
    {
        $declaredLength = trim($response->getHeaderLine('Content-Length'));
        if ($declaredLength !== '' && ctype_digit($declaredLength) && (int) $declaredLength > $limit) {
            throw new XunhupaySdkException($scene . '超过大小限制');
        }

        $stream = $response->getBody();
        $body = '';
        while (!$stream->eof()) {
            $remaining = ($limit + 1) - strlen($body);
            if ($remaining <= 0) {
                break;
            }
            $chunk = $stream->read(min(8192, $remaining));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        if ($body === '') {
            throw new XunhupaySdkException($scene . '为空');
        }
        if (strlen($body) > $limit || !$stream->eof()) {
            throw new XunhupaySdkException($scene . '超过大小限制');
        }

        return $body;
    }

    /**
     * 校验二维码图片或重定向地址为受信 HTTPS 目标。
     */
    private function assertTrustedQrcodeUrl(string $url): void
    {
        if ($url === '' || strlen($url) > self::MAX_QRCODE_URL_BYTES) {
            throw new XunhupaySdkException('虎皮椒二维码地址无效');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? 443);
        if ($scheme !== 'https'
            || $host === ''
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || !$this->isTrustedHost($host)) {
            throw new XunhupaySdkException('虎皮椒二维码地址不是受信 HTTPS 目标');
        }
    }

    /**
     * 校验解析出的二维码内容协议和 HTTPS 主机。
     */
    private function assertQrcodeContent(string $content): string
    {
        $content = trim($content);
        if ($content === ''
            || preg_match('//u', $content) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $content) === 1) {
            throw new XunhupaySdkException('虎皮椒二维码内容包含无效字符');
        }

        $parts = parse_url($content);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (in_array($scheme, ['weixin', 'alipays'], true)) {
            return $content;
        }
        if ($scheme === 'https') {
            $host = strtolower((string) ($parts['host'] ?? ''));
            $port = (int) ($parts['port'] ?? 443);
            if ($host !== '' && $port === 443 && $this->isTrustedHost($host)) {
                return $content;
            }
        }

        throw new XunhupaySdkException('虎皮椒二维码内容不是受信支付目标');
    }

    /**
     * 读取 URL 中唯一的原始查询参数。
     */
    private function queryParameter(string $url, string $name): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }

        $value = null;
        foreach (explode('&', $query) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            if (rawurldecode($rawKey) !== $name) {
                continue;
            }
            if ($value !== null) {
                throw new XunhupaySdkException('虎皮椒二维码地址包含重复 data 参数');
            }
            $value = rawurldecode($rawValue);
        }

        return $value ?? '';
    }

    /**
     * 判断主机是否属于配置网关或官网列出的服务域名。
     */
    private function isTrustedHost(string $host): bool
    {
        $gatewayHost = strtolower((string) parse_url($this->payUrl(), PHP_URL_HOST));
        if ($host === $gatewayHost) {
            return true;
        }

        foreach (self::OFFICIAL_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成同一 HTTPS 网关 origin 下的接口地址。
     */
    private function endpointUrl(string $path): string
    {
        $parts = parse_url($this->payUrl());
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return 'https://' . (string) $parts['host'] . $port . $path;
    }

    /**
     * 返回经过约束的支付网关。
     */
    private function payUrl(): string
    {
        $url = $this->configText('api_url');
        $url = $url !== '' ? $url : self::DEFAULT_PAY_URL;
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || (int) ($parts['port'] ?? 443) !== 443
            || (string) ($parts['path'] ?? '') !== '/payment/do.html'
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new XunhupaySdkException('虎皮椒网关必须是 HTTPS /payment/do.html 地址', true, 'INVALID_GATEWAY');
        }

        return $url;
    }

    /**
     * 截断上游可读错误，不保留完整响应。
     */
    private function safeMessage(mixed $message): string
    {
        $message = strip_tags((string) $message);
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $message = trim(mb_strcut($message, 0, 160, 'UTF-8'));

        return $message !== '' ? $message : '虎皮椒明确拒绝请求';
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
