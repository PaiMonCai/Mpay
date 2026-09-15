<?php

/**
 * 建立显式退款冲减字段、支付恢复任务和支付异常记录。
 */
return new class {
    public string $version = '202607190001';
    public string $name = 'payment_recovery_architecture';

    public function up(\PDO $pdo): void
    {
        $columns = [
            'account_reverse_status' => "tinyint unsigned NOT NULL DEFAULT 0 COMMENT '本地冲减状态：0-无需冲减,1-存在欠款,2-已全部冲减'",
            'account_reverse_required_amount' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '本地应冲金额（分）'",
            'account_reverse_collected_amount' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '本地已冲金额（分）'",
            'account_reverse_due_amount' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '本地欠款金额（分）'",
            'account_reverse_recorded_at' => "datetime DEFAULT NULL COMMENT '本地冲减记录时间'",
            'account_reverse_recovered_at' => "datetime DEFAULT NULL COMMENT '本地冲减完成时间'",
        ];
        foreach ($columns as $name => $definition) {
            $this->addColumn($pdo, 'ma_refund_order', $name, $definition);
        }
        $this->addIndex(
            $pdo,
            'ma_refund_order',
            'idx_account_reverse',
            '`account_reverse_status`, `account_reverse_due_amount`, `id`'
        );

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ma_payment_recovery_task` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `task_no` varchar(32) NOT NULL DEFAULT '' COMMENT '恢复任务号',
  `task_type` varchar(48) NOT NULL DEFAULT '' COMMENT '恢复任务类型',
  `ref_no` varchar(32) NOT NULL DEFAULT '' COMMENT '业务引用单号',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '状态：0-等待,1-执行中,2-成功,3-暂停,4-取消',
  `priority` smallint unsigned NOT NULL DEFAULT 0 COMMENT '任务优先级',
  `next_retry_at` datetime DEFAULT NULL COMMENT '下次执行时间',
  `retry_count` int unsigned NOT NULL DEFAULT 0 COMMENT '已重试次数',
  `max_retry_count` int unsigned NOT NULL DEFAULT 0 COMMENT '最大重试次数，0表示不限',
  `execution_seq` int unsigned NOT NULL DEFAULT 0 COMMENT '执行序号',
  `lease_owner` varchar(64) NOT NULL DEFAULT '' COMMENT '租约持有者',
  `lease_token` varchar(64) NOT NULL DEFAULT '' COMMENT '租约令牌',
  `lease_expired_at` datetime DEFAULT NULL COMMENT '租约到期时间',
  `last_attempt_at` datetime DEFAULT NULL COMMENT '最近执行时间',
  `last_error` varchar(255) NOT NULL DEFAULT '' COMMENT '最近错误摘要',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_no` (`task_no`),
  UNIQUE KEY `uk_task_ref` (`task_type`, `ref_no`),
  KEY `idx_task_schedule` (`task_type`, `status`, `next_retry_at`, `id`),
  KEY `idx_task_lease` (`task_type`, `status`, `lease_expired_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付恢复任务表'
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ma_payment_exception` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `exception_no` varchar(32) NOT NULL DEFAULT '' COMMENT '异常单号',
  `subject_type` varchar(16) NOT NULL DEFAULT '' COMMENT '异常对象类型',
  `subject_no` varchar(32) NOT NULL DEFAULT '' COMMENT '异常对象单号',
  `biz_no` varchar(32) NOT NULL DEFAULT '' COMMENT '业务订单号',
  `merchant_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '商户ID',
  `exception_type` varchar(48) NOT NULL DEFAULT '' COMMENT '异常类型',
  `severity` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '级别：1-关注,2-高,3-严重',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '状态：0-待处理,1-处理中,2-已解决,3-已忽略',
  `source_type` varchar(32) NOT NULL DEFAULT '' COMMENT '异常来源类型',
  `source_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '异常来源记录ID',
  `summary` varchar(255) NOT NULL DEFAULT '' COMMENT '异常摘要',
  `detail_json` json DEFAULT NULL COMMENT '不参与查询的异常证据快照',
  `resolution` varchar(255) NOT NULL DEFAULT '' COMMENT '处理结论',
  `resolution_ref_no` varchar(32) NOT NULL DEFAULT '' COMMENT '处理关联单号',
  `resolver_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT '处理人ID',
  `detected_at` datetime DEFAULT NULL COMMENT '发现时间',
  `resolved_at` datetime DEFAULT NULL COMMENT '解决时间',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exception_no` (`exception_no`),
  UNIQUE KEY `uk_subject_exception` (`subject_type`, `subject_no`, `exception_type`),
  KEY `idx_status_severity_detected` (`status`, `severity`, `detected_at`, `id`),
  KEY `idx_merchant_status` (`merchant_id`, `status`, `detected_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付业务异常表'
SQL);
    }

    private function addColumn(\PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(1) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }

    private function addIndex(\PDO $pdo, string $table, string $index, string $columns): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $pdo->exec(sprintf('ALTER TABLE `%s` ADD KEY `%s` (%s)', $table, $index, $columns));
    }
};
