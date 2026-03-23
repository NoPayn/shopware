<?php

declare(strict_types=1);

namespace NoPayn\Payment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1742515200AddCaptureMode extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1742515200;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :table AND TABLE_SCHEMA = DATABASE()',
            ['table' => 'nopayn_transactions']
        );

        if (!\in_array('capture_mode', $columns, true)) {
            $connection->executeStatement(
                'ALTER TABLE `nopayn_transactions` ADD COLUMN `capture_mode` VARCHAR(32) NOT NULL DEFAULT \'automatic\' AFTER `status`'
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
