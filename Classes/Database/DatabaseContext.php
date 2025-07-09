<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use TYPO3\CMS\Core\Database\Connection;

class DatabaseContext
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $connectionName,
        private readonly array $mappedTableNames,
    ) {}

    public function runWithinConnection(callable $callback): void
    {
        $this->connection->transactional(function () use ($callback) {
            $tcaBackup = $GLOBALS['TCA'];
            $GLOBALS['TCA'] = \array_intersect_key($GLOBALS['TCA'], \array_flip($this->mappedTableNames));
            $tableMappingBackup = $GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'] ?? [];
            $GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'] = \array_merge(
                $tableMappingBackup,
                \array_fill_keys($this->mappedTableNames, $this->connectionName),
            );

            $callback($this->connection);

            $GLOBALS['TCA'] = $tcaBackup;
            $GLOBALS['TYPO3_CONF_VARS']['DB']['TableMapping'] = $tableMappingBackup;
        });
    }
}
