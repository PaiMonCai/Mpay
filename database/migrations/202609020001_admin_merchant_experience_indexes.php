<?php

/**
 * 为后台默认时间范围和异常排查列表补充查询索引。
 */
return new class {
    public string $version = '202609020001';
    public string $name = 'admin_merchant_experience_indexes';

    public function up(\PDO $pdo): void
    {
        $this->addIndex($pdo, 'ma_pay_order', 'idx_created_id', '`created_at`, `id`');
        $this->addIndex($pdo, 'ma_refund_order', 'idx_created_id', '`created_at`, `id`');
        $this->addIndex($pdo, 'ma_settlement_order', 'idx_created_id', '`created_at`, `id`');
        $this->addIndex($pdo, 'ma_pay_callback_log', 'idx_process_created', '`process_status`, `created_at`, `id`');
        $this->addIndex($pdo, 'ma_notify_task', 'idx_status_created', '`status`, `created_at`, `id`');
        $this->addIndex($pdo, 'ma_merchant_account_ledger', 'idx_merchant_created', '`merchant_id`, `created_at`, `id`');
        $this->addIndex($pdo, 'ma_merchant_account_ledger', 'idx_created_id', '`created_at`, `id`');
        $this->addIndex($pdo, 'ma_merchant_fund_freeze', 'idx_merchant_created', '`merchant_id`, `created_at`, `id`');
        $this->addIndex($pdo, 'ma_merchant_fund_freeze', 'idx_created_id', '`created_at`, `id`');
    }

    public function down(\PDO $pdo): void
    {
        foreach ([
            ['ma_pay_order', 'idx_created_id'],
            ['ma_refund_order', 'idx_created_id'],
            ['ma_settlement_order', 'idx_created_id'],
            ['ma_pay_callback_log', 'idx_process_created'],
            ['ma_notify_task', 'idx_status_created'],
            ['ma_merchant_account_ledger', 'idx_merchant_created'],
            ['ma_merchant_account_ledger', 'idx_created_id'],
            ['ma_merchant_fund_freeze', 'idx_merchant_created'],
            ['ma_merchant_fund_freeze', 'idx_created_id'],
        ] as [$table, $index]) {
            $this->dropIndex($pdo, $table, $index);
        }
    }

    private function addIndex(\PDO $pdo, string $table, string $index, string $columns): void
    {
        if (!$this->indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        }
    }

    private function dropIndex(\PDO $pdo, string $table, string $index): void
    {
        if ($this->indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $statement->execute([$table, $index]);
        return (int) $statement->fetchColumn() > 0;
    }
};
