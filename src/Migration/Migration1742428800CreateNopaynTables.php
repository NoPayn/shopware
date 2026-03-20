<?php

declare(strict_types=1);

namespace NoPayn\Payment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1742428800CreateNopaynTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1742428800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `nopayn_transactions` (
                `id`                    BINARY(16)   NOT NULL,
                `order_transaction_id`  BINARY(16)   NOT NULL,
                `order_id`              BINARY(16)   NOT NULL,
                `nopayn_order_id`       VARCHAR(255) NOT NULL,
                `payment_method`        VARCHAR(64)  NOT NULL,
                `amount`                INT          NOT NULL,
                `currency`              VARCHAR(3)   NOT NULL,
                `status`                VARCHAR(32)  NOT NULL DEFAULT \'new\',
                `created_at`            DATETIME(3)  NOT NULL,
                `updated_at`            DATETIME(3)  NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_nopayn_order_id` (`nopayn_order_id`),
                KEY `idx_order_transaction_id` (`order_transaction_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `nopayn_refunds` (
                `id`                BINARY(16)   NOT NULL,
                `order_id`          BINARY(16)   NOT NULL,
                `nopayn_order_id`   VARCHAR(255) NOT NULL,
                `nopayn_refund_id`  VARCHAR(255) NULL,
                `amount`            INT          NOT NULL,
                `currency`          VARCHAR(3)   NOT NULL,
                `status`            VARCHAR(32)  NOT NULL DEFAULT \'pending\',
                `description`       TEXT         NULL,
                `created_at`        DATETIME(3)  NOT NULL,
                `updated_at`        DATETIME(3)  NULL,
                PRIMARY KEY (`id`),
                KEY `idx_order_id` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
