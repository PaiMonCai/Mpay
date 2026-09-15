<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Log;
use support\Request;
use app\process\Http;
use app\process\PaymentRuntimeProcess;
use app\process\ReceiptWatcherProcess;
use app\process\AlipayBillOpenApiWatcherProcess;

global $argv;

$webman = [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => cpu_count() * 4,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
];

// The install package must be able to start before Redis/database config exists.
// Keep only the web server alive until the installer writes runtime/install.lock.
if (!is_file(base_path() . '/runtime/install.lock')) {
    return $webman;
}

return array_merge($webman, [
    // 支付运行时维护进程：负责商户通知重试、订单超时处理和主动查单。
    'payment-runtime' => [
        'handler' => PaymentRuntimeProcess::class,
        // 维护任务只需要单进程调度，避免多进程重复扫描同一批订单。
        'count' => 1,
        // 常驻调度进程不跟随普通 reload 重启，减少定时任务中断。
        'reloadable' => false,
        'constructor' => [
            'options' => [
                // 心跳频率只控制进程 tick，具体任务间隔仍以系统配置页为准。
                'heartbeat_seconds' => (int) env('PAY_RUNTIME_HEARTBEAT_SECONDS', 5),
            ],
        ],
    ],
    // 网页流水监听调度进程：维护账号、订单快照和预登录计划，并投放四条 v2 Stream。
    // 第三方登录、流水查询和队列投递由 Go 直连与 Python 浏览器 watcher 按运行时分别完成。
    'receipt-watcher' => [
        'handler' => ReceiptWatcherProcess::class,
        // 单进程维护账号任务，避免重复写入查询任务。
        'count' => 1,
        // 该进程依赖 Redis 中的短期任务状态，保持常驻更稳定。
        'reloadable' => false,
        'constructor' => [
            'options' => [
                // 进程心跳，实际订单扫描间隔由系统配置 receipt_watcher_order_scan_interval_seconds 控制。
                'heartbeat_seconds' => (int) env('RECEIPT_WATCHER_HEARTBEAT_SECONDS', 1),
            ],
        ],
    ],
    // 内置支付宝账单 OpenAPI watcher：仅消费 alipay_bill_receipt 的 direct 查询任务。
    // 使用商户自己的支付宝开放平台凭据，不依赖外置 receipt_watcher 二进制。
    'alipay-bill-openapi-watcher' => [
        'handler' => AlipayBillOpenApiWatcherProcess::class,
        'count' => 1,
        'reloadable' => false,
        'constructor' => [
            'options' => [
                'poll_interval_seconds' => (float) env('ALIPAY_BILL_WATCHER_POLL_INTERVAL_SECONDS', 0.5),
            ],
        ],
    ],

    // 文件变更监控进程：开发环境下用于检测代码变动并自动 reload。
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // 需要监控的目录。
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                // base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // 只有这些后缀的文件变更会触发监控逻辑。
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ]
]);
