<?php

declare(strict_types=1);

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use app\service\payment\runtime\PaymentQueueService;
use RuntimeException;
use support\Log;
use support\Redis;
use Throwable;

/**
 * 支付宝账单 OpenAPI 内置监听服务。
 *
 * 这是 receipt_watcher 的独立兼容实现，只处理 alipay_bill_receipt 插件。
 * 它不修改、模拟或绕过任何第三方 watcher 授权，而是直接使用商户自己配置的
 * 支付宝应用 AppID/应用私钥/支付宝公钥调用官方账务明细接口。
 */
class AlipayBillOpenApiWatcherService extends BaseService
{
    private const PLUGIN_CODE = 'alipay_bill_receipt';
    private const QUERY_STREAM = 'receipt_watcher_direct_query_stream';
    private const QUERY_ACCOUNTS_KEY = 'receipt_watcher_query_accounts';
    private const ACCOUNTS_KEY = 'receipt_watcher_accounts';
    private const ACCOUNT_ORDERS_PREFIX = 'receipt_watcher_orders_';
    private const QUERY_ENQUEUED_PREFIX = 'receipt_watcher_query_enqueued_';
    private const LOCK_PREFIX = 'receipt_watcher_lock_';
    private const FLOW_DISPATCH_PREFIX = 'receipt_watcher_builtin_alipay_dispatched_';

    private const CONSUMER_GROUP = 'mpay_builtin_alipay';
    private const API_METHOD = 'alipay.data.bill.accountlog.query';
    private const RESPONSE_KEY = 'alipay_data_bill_accountlog_query_response';
    private const DEFAULT_GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public function __construct(
        private readonly PaymentQueueService $paymentQueueService
    ) {
    }

    /**
     * 初始化独立消费组，并强制当前支付宝账单账号重新进入调度。
     */
    public function bootstrap(): void
    {
        try {
            $this->redisRaw('XGROUP', 'CREATE', self::QUERY_STREAM, self::CONSUMER_GROUP, '$', 'MKSTREAM');
        } catch (Throwable $e) {
            if (!str_contains(strtoupper($e->getMessage()), 'BUSYGROUP')) {
                throw $e;
            }
        }

        $this->rescheduleKnownAccounts(true);
    }

    /**
     * 消费一批 direct watcher 任务。
     *
     * @return array<string,int>
     */
    public function consume(string $consumerName, int $count = 20): array
    {
        $summary = [
            'messages' => 0,
            'ignored' => 0,
            'queried' => 0,
            'records' => 0,
            'queued' => 0,
            'failed' => 0,
        ];

        $messages = Redis::xReadGroup(
            self::CONSUMER_GROUP,
            $consumerName,
            [self::QUERY_STREAM => '>'],
            max(1, min(100, $count)),
            1
        );

        if (!is_array($messages) || empty($messages[self::QUERY_STREAM])) {
            return $summary;
        }

        foreach ((array) $messages[self::QUERY_STREAM] as $messageId => $fields) {
            $summary['messages']++;
            $fields = is_array($fields) ? $fields : [];

            if (trim((string) ($fields['plugin_code'] ?? '')) !== self::PLUGIN_CODE) {
                $summary['ignored']++;
                $this->ack((string) $messageId);
                continue;
            }

            try {
                $result = $this->handleTask($fields);
                $summary['queried'] += (int) ($result['queried'] ?? 0);
                $summary['records'] += (int) ($result['records'] ?? 0);
                $summary['queued'] += (int) ($result['queued'] ?? 0);
            } catch (Throwable $e) {
                $summary['failed']++;
                Log::warning(sprintf(
                    '[AlipayBillOpenApiWatcher] task failed account=%s trace=%s error=%s',
                    (string) ($fields['account_key'] ?? ''),
                    (string) ($fields['trace_id'] ?? ''),
                    $e->getMessage()
                ));
                $this->rescheduleTask((string) ($fields['account_key'] ?? ''), 5);
            } finally {
                $this->ack((string) $messageId);
            }
        }

        return $summary;
    }

    /**
     * 处理单个账号查询任务。
     *
     * @param array<string,mixed> $task
     * @return array<string,int>
     */
    private function handleTask(array $task): array
    {
        $accountKey = $this->safeKeyPart((string) ($task['account_key'] ?? ''));
        if ($accountKey === 'empty') {
            throw new RuntimeException('account_key 不能为空');
        }

        $token = $this->acquireAccountLock($accountKey);
        if ($token === null) {
            // 与其它 watcher 共存时尊重同一把账号锁；短延迟重新调度，避免陈旧锁造成 5 分钟停顿。
            $this->rescheduleTask($accountKey, 2);
            return ['queried' => 0, 'records' => 0, 'queued' => 0];
        }

        try {
            $account = $this->account($accountKey);
            if ($account === null || (string) ($account['plugin_code'] ?? '') !== self::PLUGIN_CODE) {
                $this->removeSchedule($accountKey);
                return ['queried' => 0, 'records' => 0, 'queued' => 0];
            }

            $orders = $this->orders($accountKey);
            if ($orders === []) {
                $this->removeSchedule($accountKey);
                return ['queried' => 0, 'records' => 0, 'queued' => 0];
            }

            $config = (array) ($account['config'] ?? []);
            $window = $this->queryWindow($orders);
            $rows = $this->queryAccountLogs($config, $window['start_at'], $window['end_at']);

            $records = [];
            foreach ($rows as $row) {
                $record = $this->normalizeRecord($row);
                if ($record === null || !$this->recordMayMatchOrders($record, $orders)) {
                    continue;
                }
                $records[] = $record;
            }

            $queued = 0;
            foreach ($records as $record) {
                if ($this->dispatchRecord((int) ($account['api_config_id'] ?? 0), $record)) {
                    $queued++;
                }
            }

            $this->rescheduleTask(
                $accountKey,
                max(2, (int) ($account['query_interval_seconds'] ?? 3))
            );

            return [
                'queried' => 1,
                'records' => count($records),
                'queued' => $queued,
            ];
        } finally {
            $this->releaseAccountLock($accountKey, $token);
        }
    }

    /**
     * 调用支付宝商家账户账务明细查询接口。
     *
     * @return array<int,array<string,mixed>>
     */
    private function queryAccountLogs(array $config, string $startAt, string $endAt): array
    {
        $appId = trim((string) ($config['app_id'] ?? ''));
        $privateKey = trim((string) ($config['private_key'] ?? ''));
        $alipayPublicKey = trim((string) ($config['alipay_public_key'] ?? ''));
        $billUserId = trim((string) ($config['bill_user_id'] ?? ''));

        if ($appId === '' || $privateKey === '' || $alipayPublicKey === '' || $billUserId === '') {
            throw new RuntimeException('支付宝账单 OpenAPI 配置不完整');
        }

        $biz = [
            'start_time' => $startAt,
            'end_time' => $endAt,
            'page_no' => '1',
            'page_size' => '2000',
            'bill_user_id' => $billUserId,
        ];

        $params = [
            'app_id' => $appId,
            'method' => self::API_METHOD,
            'format' => 'JSON',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => $this->jsonEncode($biz),
        ];
        $params['sign'] = $this->sign($params, $privateKey);

        $gateway = trim((string) ($config['alipay_gateway'] ?? self::DEFAULT_GATEWAY));
        if ($gateway === '') {
            $gateway = self::DEFAULT_GATEWAY;
        }

        $body = $this->postForm($gateway, $params);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('支付宝账单接口返回非 JSON 数据');
        }

        $response = $decoded[self::RESPONSE_KEY] ?? null;
        if (!is_array($response)) {
            throw new RuntimeException('支付宝账单接口响应节点不存在');
        }

        $sign = trim((string) ($decoded['sign'] ?? ''));
        if ($sign === '') {
            throw new RuntimeException('支付宝账单接口响应签名缺失');
        }
        $signedContent = $this->extractSignedResponseContent($body, self::RESPONSE_KEY);
        if (!$this->verify($signedContent, $sign, $alipayPublicKey)) {
            throw new RuntimeException('支付宝账单接口响应验签失败');
        }

        if ((string) ($response['code'] ?? '') !== '10000') {
            throw new RuntimeException(sprintf(
                '支付宝账单接口失败 code=%s sub_code=%s msg=%s',
                (string) ($response['code'] ?? ''),
                (string) ($response['sub_code'] ?? ''),
                (string) (($response['sub_msg'] ?? '') ?: ($response['msg'] ?? ''))
            ));
        }

        $details = $response['detail_list'] ?? [];
        if (!is_array($details)) {
            return [];
        }

        $rows = [];
        foreach ($details as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * 生成查询时间窗。支付宝接口使用左闭右开时间范围。
     *
     * @param array<int,array<string,mixed>> $orders
     * @return array{start_at:string,end_at:string}
     */
    private function queryWindow(array $orders): array
    {
        $now = time();
        $start = $now - 600;
        $end = $now + 5;

        foreach ($orders as $order) {
            $requestAt = strtotime((string) ($order['request_at'] ?? '')) ?: 0;
            if ($requestAt > 0) {
                $start = min($start, $requestAt - 30);
            }
        }

        // pending receipt 订单本身只有分钟级有效期；额外限制窗口，避免误扫大量历史账单。
        $start = max($start, $now - 3600);

        return [
            'start_at' => date('Y-m-d H:i:s', $start),
            'end_at' => date('Y-m-d H:i:s', $end),
        ];
    }

    /**
     * 将支付宝账务明细归一化成 MPAY 现有插件所需 record。
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalizeRecord(array $row): ?array
    {
        $direction = trim((string) ($row['direction'] ?? ''));
        if ($direction !== '' && !in_array($direction, ['收入', 'in', 'IN', 'income', 'INCOME'], true)) {
            return null;
        }

        $orderNo = trim((string) ($row['alipay_order_no'] ?? ''));
        $paidAt = trim((string) ($row['trans_dt'] ?? ''));
        $price = $this->normalizeMoney((string) ($row['trans_amount'] ?? ''));
        if ($orderNo === '' || $paidAt === '' || $price === null || $this->moneyToCents($price) <= 0) {
            return null;
        }

        return [
            'order_no' => substr($orderNo, 0, 64),
            'price' => $price,
            'paid_at' => $paidAt,
            'remark' => trim((string) ($row['trans_memo'] ?? '')),
            'pay_type' => 'alipay',
            'account_log_id' => trim((string) ($row['account_log_id'] ?? '')),
            'merchant_order_no' => trim((string) ($row['merchant_order_no'] ?? '')),
            'other_account' => trim((string) ($row['other_account'] ?? '')),
            'direction' => $direction,
            'bill_source' => trim((string) ($row['bill_source'] ?? '')),
        ];
    }

    /**
     * 先按账号下待支付订单的金额和时间窗做粗过滤，避免无关流水进入业务队列。
     * 真正的金额/备注匹配仍由 AlipayBillReceiptPayment 完成。
     *
     * @param array<string,mixed> $record
     * @param array<int,array<string,mixed>> $orders
     */
    private function recordMayMatchOrders(array $record, array $orders): bool
    {
        $amount = $this->moneyToCents((string) ($record['price'] ?? ''));
        $paidAt = strtotime((string) ($record['paid_at'] ?? '')) ?: 0;
        if ($amount <= 0 || $paidAt <= 0) {
            return false;
        }

        foreach ($orders as $order) {
            if ((int) ($order['pay_amount'] ?? 0) !== $amount) {
                continue;
            }
            $requestAt = strtotime((string) ($order['request_at'] ?? '')) ?: 0;
            $expireAt = strtotime((string) ($order['expire_at'] ?? '')) ?: 0;
            if ($requestAt > 0 && $expireAt > 0 && $paidAt >= $requestAt && $paidAt <= $expireAt) {
                return true;
            }
        }

        return false;
    }

    /**
     * 投递归一化流水，并做短期投递去重。
     */
    private function dispatchRecord(int $apiConfigId, array $record): bool
    {
        if ($apiConfigId <= 0) {
            throw new RuntimeException('api_config_id 无效');
        }

        $orderNo = $this->safeKeyPart((string) ($record['order_no'] ?? ''));
        $dedupeKey = self::FLOW_DISPATCH_PREFIX . $apiConfigId . '_' . $orderNo;
        $reserved = $this->redisRaw('SET', $dedupeKey, (string) time(), 'NX', 'EX', '60');
        if ($reserved !== true && strtoupper((string) $reserved) !== 'OK') {
            return false;
        }

        $ok = $this->paymentQueueService->sendReceiptFlowNotify([
            'plugin_code' => self::PLUGIN_CODE,
            'api_config_id' => $apiConfigId,
            'record' => $record,
            'source' => 'builtin_alipay_openapi',
        ]);
        if (!$ok) {
            Redis::del($dedupeKey);
            throw new RuntimeException('投递支付宝账单流水队列失败');
        }

        return true;
    }

    /**
     * 获取账号配置快照。
     */
    private function account(string $accountKey): ?array
    {
        $raw = Redis::hGet(self::ACCOUNTS_KEY, $accountKey);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 获取账号下待支付订单快照。
     *
     * @return array<int,array<string,mixed>>
     */
    private function orders(string $accountKey): array
    {
        $raw = Redis::hGetAll(self::ACCOUNT_ORDERS_PREFIX . $accountKey);
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $orders = [];
        foreach ($raw as $json) {
            $decoded = is_string($json) ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                $orders[] = $decoded;
            }
        }
        return $orders;
    }

    /**
     * 启动时强制支付宝账单账号重新调度，避免在内置 watcher 启动前已经存在的 enqueued 标记阻塞数分钟。
     */
    private function rescheduleKnownAccounts(bool $immediate): void
    {
        $raw = Redis::hGetAll(self::ACCOUNTS_KEY);
        if (!is_array($raw)) {
            return;
        }

        foreach ($raw as $accountKey => $json) {
            $account = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($account) || (string) ($account['plugin_code'] ?? '') !== self::PLUGIN_CODE) {
                continue;
            }
            $accountKey = $this->safeKeyPart((string) $accountKey);
            Redis::del(self::QUERY_ENQUEUED_PREFIX . $accountKey);
            Redis::zAdd(self::QUERY_ACCOUNTS_KEY, $immediate ? time() : time() + 1, $accountKey);
        }
    }

    /**
     * 完成一次账号查询后释放投放标记，并设置下一次到期时间。
     */
    private function rescheduleTask(string $accountKey, int $delaySeconds): void
    {
        $accountKey = $this->safeKeyPart($accountKey);
        if ($accountKey === 'empty') {
            return;
        }
        Redis::del(self::QUERY_ENQUEUED_PREFIX . $accountKey);
        Redis::zAdd(self::QUERY_ACCOUNTS_KEY, time() + max(1, $delaySeconds), $accountKey);
    }

    /**
     * 账号已经没有待支付订单时移除调度状态。
     */
    private function removeSchedule(string $accountKey): void
    {
        Redis::del(self::QUERY_ENQUEUED_PREFIX . $accountKey);
        Redis::zRem(self::QUERY_ACCOUNTS_KEY, $accountKey);
    }

    private function acquireAccountLock(string $accountKey): ?string
    {
        $token = bin2hex(random_bytes(8));
        $locked = $this->redisRaw('SET', self::LOCK_PREFIX . $accountKey, $token, 'NX', 'EX', '30');
        if ($locked !== true && strtoupper((string) $locked) !== 'OK') {
            return null;
        }
        return $token;
    }

    private function releaseAccountLock(string $accountKey, string $token): void
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;
        $this->redisRaw('EVAL', $script, '1', self::LOCK_PREFIX . $accountKey, $token);
    }

    private function ack(string $messageId): void
    {
        if ($messageId !== '') {
            $this->redisRaw('XACK', self::QUERY_STREAM, self::CONSUMER_GROUP, $messageId);
        }
    }

    /**
     * RSA2 请求签名。
     *
     * @param array<string,string> $params
     */
    private function sign(array $params, string $privateKey): string
    {
        unset($params['sign']);
        $params = array_filter($params, static fn (string $value): bool => $value !== '');
        ksort($params);

        $content = implode('&', array_map(
            static fn (string $key, string $value): string => $key . '=' . $value,
            array_keys($params),
            array_values($params)
        ));

        $key = openssl_pkey_get_private($this->pemPrivateKey($privateKey));
        if ($key === false) {
            throw new RuntimeException('支付宝应用私钥格式不正确');
        }

        $signature = '';
        if (!openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('支付宝请求 RSA2 签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 验证支付宝响应 RSA2 签名。
     */
    private function verify(string $content, string $signature, string $publicKey): bool
    {
        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }
        $key = openssl_pkey_get_public($this->pemPublicKey($publicKey));
        if ($key === false) {
            throw new RuntimeException('支付宝公钥格式不正确');
        }

        return openssl_verify($content, $decodedSignature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 从支付宝原始 JSON 中提取被签名的 response 节点原文，避免 decode/re-encode 改变签名文本。
     */
    private function extractSignedResponseContent(string $json, string $responseKey): string
    {
        $needle = '"' . $responseKey . '"';
        $keyPos = strpos($json, $needle);
        if ($keyPos === false) {
            throw new RuntimeException('支付宝响应签名节点不存在');
        }
        $colonPos = strpos($json, ':', $keyPos + strlen($needle));
        if ($colonPos === false) {
            throw new RuntimeException('支付宝响应格式异常');
        }

        $length = strlen($json);
        $start = $colonPos + 1;
        while ($start < $length && ctype_space($json[$start])) {
            $start++;
        }
        if ($start >= $length || $json[$start] !== '{') {
            throw new RuntimeException('支付宝响应签名内容不是对象');
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($i = $start; $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($json, $start, $i - $start + 1);
                }
            }
        }

        throw new RuntimeException('支付宝响应签名内容未闭合');
    }

    /**
     * HTTPS POST 表单请求。
     *
     * @param array<string,string> $params
     */
    private function postForm(string $url, array $params): string
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('支付宝网关必须使用 HTTPS');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('初始化支付宝 HTTP 客户端失败');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body)) {
            throw new RuntimeException('请求支付宝账单接口失败：' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('支付宝账单接口 HTTP 状态异常：' . $status);
        }

        return $body;
    }

    private function pemPrivateKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key) ?: '';
        return "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END PRIVATE KEY-----\n";
    }

    private function pemPublicKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key) ?: '';
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function normalizeMoney(string $money): ?string
    {
        $money = trim($money);
        if (str_starts_with($money, '+')) {
            $money = substr($money, 1);
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return null;
        }
        [$integer, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        return ltrim($integer, '0') === ''
            ? '0.' . str_pad($fraction, 2, '0')
            : ltrim($integer, '0') . '.' . str_pad($fraction, 2, '0');
    }

    private function moneyToCents(string $money): int
    {
        $normalized = $this->normalizeMoney($money);
        if ($normalized === null) {
            return 0;
        }
        [$integer, $fraction] = explode('.', $normalized, 2);
        return (int) $integer * 100 + (int) $fraction;
    }

    private function safeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $value) ?? '';
        return trim($safe, '_') !== '' ? trim($safe, '_') : 'empty';
    }

    private function jsonEncode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('JSON 编码失败');
        }
        return $json;
    }

    private function redisRaw(string $command, mixed ...$arguments): mixed
    {
        return Redis::connection()->rawCommand($command, ...$arguments);
    }
}
