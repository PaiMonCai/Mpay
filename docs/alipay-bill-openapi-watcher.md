# 内置支付宝账单 OpenAPI watcher

## 目标

本补丁为 `alipay_bill_receipt` 增加一个 MPAY 内置的支付宝账单监听实现。它不修改外置 `receipt_watcher` 的授权逻辑，也不依赖外置二进制，而是直接使用支付插件中由商户自己配置的：

- 支付宝应用 AppID
- 应用 RSA2 私钥
- 支付宝公钥
- `bill_user_id`

调用支付宝官方 `alipay.data.bill.accountlog.query` 账务明细接口。

原有订单、金额偏移、备注码、流水幂等、支付成功状态推进和商户通知逻辑全部继续复用 MPAY 原来的实现。

## 数据链路

```text
ReceiptWatcherProcess
  -> receipt_watcher_direct_query_stream
  -> AlipayBillOpenApiWatcherProcess
  -> alipay.data.bill.accountlog.query
  -> 标准化 record
  -> receipt_flow_notify Redis Queue
  -> ReceiptFlowNotifyJob
  -> AlipayBillReceiptPayment::channelNotifyPayload()
  -> AlipayBillReceiptPayment::notifyPayload()
  -> MPAY 原支付成功/入账/通知链路
```

内置 watcher 使用独立 Redis consumer group：

```text
mpay_builtin_alipay
```

因此不会抢占外置 watcher 自己的 consumer group。

## 启用步骤

已有安装升级代码后，使用项目实际运行的 PHP 版本执行：

```bash
php webman system:config-sync
php webman restart -d
```

建议使用 PHP 8.2。

进入系统配置的「运行时 / 网页流水监听」：

1. 打开 `启用网页流水监听`。
2. 打开 `内置支付宝账单 OpenAPI watcher`。
3. 外置 watcher 授权码可以留空；它只用于原外置 watcher。

然后配置 `支付宝账单收款` 支付插件：

- AppID
- 应用私钥
- 支付宝公钥
- 支付宝用户 ID（`bill_user_id`）
- 收款二维码或免输转账参数
- 匹配模式

启用内置 watcher 后，`alipay_bill_receipt` 会自动加入 Webman 的监听插件集合，不要求再手工写入 `receipt_watcher_plugin_codes`。

## 支付宝侧前提

支付宝应用必须实际拥有 `alipay.data.bill.accountlog.query` 的调用权限，并且配置的应用密钥、公钥和 `bill_user_id` 必须与该调用关系匹配。

这个补丁不会绕过支付宝开放平台自身的接口权限。如果接口返回 `isv.insufficient-isv-permissions`、`isv.invalid-app-id`、`isv.invalid-signature` 等错误，应在支付宝开放平台检查应用权限和密钥配置。

## 匹配模式建议

### 金额变动

推荐作为默认模式。MPAY 为并发待支付订单分配 `+0.01 ~ +0.99` 的识别金额，watcher 按账单实际入账金额和支付时间做粗过滤，最终仍由 `AlipayBillReceiptPayment` 做严格订单窗口匹配。

### 付款备注 / 免输转账

兼容保留。账单接口返回的 `trans_memo` 会映射为 MPAY 的 `record.remark`，现有插件再从其中提取四位备注码。

支付宝对 `trans_memo` 的定义说明该字段由上游业务决定，不建议作为严格对账主键。因此如果实际支付宝场景中备注无法稳定出现在 `trans_memo`，应优先使用金额变动模式。

## 安全设计

- 支付宝网关只允许 HTTPS。
- `CURLOPT_SSL_VERIFYPEER` 和 `CURLOPT_SSL_VERIFYHOST` 均保持开启。
- 请求使用 RSA2 / SHA-256 签名。
- 响应从原始 JSON 中提取被签名 response 节点并使用支付宝公钥验签。
- 不记录应用私钥。
- 只处理 `direction=收入` 的有效入账流水。
- 先按当前待支付订单金额和时间窗做粗过滤，避免把全部账务流水投递进支付回调队列。
- 最终仍使用 MPAY 原来的流水锁、流水 seen 标记和订单状态推进。

## 与原外置 watcher 共存

技术上使用独立 consumer group，不会直接抢消息；但同一个 `alipay_bill_receipt` 账号不建议同时开启两套 watcher，因为双方都会调用支付宝账单接口并尝试处理相同流水。

迁移时建议：

1. 先关闭原外置 watcher。
2. 打开内置 watcher。
3. 创建一笔小额测试订单。
4. 确认 `runtime/logs` 中出现 `AlipayBillOpenApiWatcherProcess` 查询日志。
5. 确认支付单由 `PAYING` 正常推进为 `SUCCESS`。

## 主要新增文件

```text
app/process/AlipayBillOpenApiWatcherProcess.php
app/service/payment/receipt/AlipayBillOpenApiWatcherService.php
```

同时修改：

```text
app/service/payment/receipt/ReceiptWatcherService.php
app/service/payment/receipt/ReceiptWatcherRuntimeStatusService.php
config/process.php
config/system_config.php
database/seeders/002_system_config_seeder.php
```
