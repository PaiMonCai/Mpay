<?php

declare(strict_types=1);

namespace app\process;

use app\service\payment\receipt\AlipayBillOpenApiWatcherService;
use app\service\system\ops\SystemOpsHeartbeatService;
use support\Log;
use support\Redis;
use Workerman\Timer;
use Workerman\Worker;

/**
 * MPAY 内置支付宝账单 OpenAPI watcher。
 */
class AlipayBillOpenApiWatcherProcess
{
    private bool $running = false;
    private bool $bootstrapped = false;
    private int $startedAt = 0;
    private string $consumerName = '';

    public function __construct(
        private array $options = []
    ) {
    }

    public function onWorkerStart(Worker $worker): void
    {
        $this->startedAt = time();
        $this->consumerName = sprintf(
            'php-%s-%d',
            preg_replace('/[^A-Za-z0-9_-]/', '_', gethostname() ?: 'host'),
            getmypid()
        );

        $interval = max(0.2, min(5.0, (float) ($this->options['poll_interval_seconds'] ?? 0.5)));
        Timer::add($interval, function (): void {
            $this->tick();
        });

        $enabled = $this->enabled();
        $this->reportHeartbeat([
            'summary' => $enabled
                ? '内置支付宝账单 OpenAPI watcher 等待初始化'
                : '内置支付宝账单 OpenAPI watcher 未启用',
            'enabled' => $enabled,
            'consumer' => $this->consumerName,
        ]);

        Log::info(sprintf(
            '[AlipayBillOpenApiWatcherProcess] 进程已启动 consumer=%s interval=%.1fs enabled=%s',
            $this->consumerName,
            $interval,
            $enabled ? 'yes' : 'no'
        ));
    }

    private function tick(): void
    {
        if ($this->running) {
            return;
        }
        if (!$this->enabled()) {
            $this->bootstrapped = false;
            $this->clearWatcherCapabilityHeartbeat();
            return;
        }
        $this->reportWatcherCapabilityHeartbeat();
        $this->running = true;

        try {
            if (!$this->bootstrapped) {
                $this->watcher()->bootstrap();
                $this->bootstrapped = true;
            }
            $summary = $this->watcher()->consume($this->consumerName, 20);
            $this->reportHeartbeat([
                'summary' => '内置支付宝账单 OpenAPI watcher 运行中',
                'enabled' => true,
                'consumer' => $this->consumerName,
                'task_summary' => $summary,
            ]);
            if ((int) ($summary['queried'] ?? 0) > 0 || (int) ($summary['failed'] ?? 0) > 0) {
                Log::info('[AlipayBillOpenApiWatcherProcess] ' . json_encode(
                    $summary,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
            }
        } catch (\Throwable $e) {
            $this->reportHeartbeat([
                'summary' => '内置支付宝账单 OpenAPI watcher 异常',
                'enabled' => true,
                'last_error' => $e->getMessage(),
            ]);
            Log::warning('[AlipayBillOpenApiWatcherProcess] 消费失败：' . $e->getMessage());
        } finally {
            $this->running = false;
        }
    }

    private function enabled(): bool
    {
        $global = strtolower(trim((string) sys_config('receipt_watcher_enabled', '0')));
        $builtin = strtolower(trim((string) sys_config('receipt_watcher_builtin_alipay_enabled', '0')));

        return in_array($global, ['1', 'true', 'yes', 'on', 'enabled'], true)
            && in_array($builtin, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    private function watcher(): AlipayBillOpenApiWatcherService
    {
        return container_get(AlipayBillOpenApiWatcherService::class);
    }

    /**
     * 以 receipt_watcher 兼容格式上报内置 watcher 能力，便于现有管理后台正确显示在线状态。
     * 这里不伪造授权信息；license 保持为空，因为内置实现只依赖支付宝官方 OpenAPI 权限。
     */
    private function reportWatcherCapabilityHeartbeat(): void
    {
        $instanceId = $this->watcherInstanceId();
        $now = time();
        $payload = [
            'instance_id' => $instanceId,
            'hostname' => gethostname() ?: 'localhost',
            'pid' => getmypid(),
            'runtime' => 'php-builtin',
            'started_at' => $this->startedAt,
            'last_seen_at' => $now,
            'worker_index' => 1,
            'worker_processes' => 1,
            'stream' => [
                'query' => 'receipt_watcher_direct_query_stream',
                'consumer_group' => 'mpay_builtin_alipay',
            ],
            'license' => [],
            'plugins' => [[
                'code' => 'alipay_bill_receipt',
                'name' => '支付宝账单收款（内置 OpenAPI）',
                'class' => 'AlipayBillReceiptPayment',
                'collect_mode' => 'direct_api',
                'concurrency' => 1,
                'features' => ['rsa2', 'signed_api'],
                'status' => 'available',
            ]],
        ];

        Redis::setEx(
            'receipt_watcher_instance_' . $instanceId,
            120,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
        Redis::zAdd('receipt_watcher_instances', $now, $instanceId);
        Redis::zRemRangeByScore('receipt_watcher_instances', '-inf', (string) ($now - 3600));
    }

    private function clearWatcherCapabilityHeartbeat(): void
    {
        if ($this->consumerName === '') {
            return;
        }
        $instanceId = $this->watcherInstanceId();
        Redis::del('receipt_watcher_instance_' . $instanceId);
        Redis::zRem('receipt_watcher_instances', $instanceId);
    }

    private function watcherInstanceId(): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', 'php_builtin_alipay_' . $this->consumerName)
            ?: 'php_builtin_alipay';
    }

    private function reportHeartbeat(array $payload): void
    {
        try {
            /** @var SystemOpsHeartbeatService $service */
            $service = container_get(SystemOpsHeartbeatService::class);
            $service->report('alipay-bill-openapi-watcher', $payload);
        } catch (\Throwable) {
            // 运维心跳失败不能影响收款监听。
        }
    }
}
